<?php

declare(strict_types=1);

namespace BotLocal;

use BotLocal\Database\DatabaseAdapterFactory;
use BotLocal\Database\DatabaseAdapterInterface;
use BotLocal\Database\SchemaSelector;
use PDO;
use RuntimeException;
use Throwable;

final class DatabaseAssistant
{
    private const PLAN_CACHE_FILE = __DIR__ . '/../storage/cache/sql_plan_cache.json';
    private const DANGEROUS_FUNCTIONS = [
        'sleep',
        'benchmark',
        'pg_sleep',
        'load_file',
        'xp_cmdshell',
    ];

    private ?PDO $pdo = null;
    private readonly DatabaseAdapterInterface $adapter;
    private readonly SchemaSelector $schemaSelector;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly array $config,
        private readonly OllamaClient $ollama
    ) {
        $this->adapter = DatabaseAdapterFactory::make($config);
        $this->schemaSelector = new SchemaSelector();
    }

    public function isConfigured(): bool
    {
        return (bool) ($this->config['enabled'] ?? false) && $this->adapter->isConfigured($this->config);
    }

    /**
     * @param array<int, array{role: string, content: string}> $history
     * @return array{reply: string, meta: array<string, mixed>}
     */
    public function answerQuestion(string $question, array $history): array
    {
        if (!$this->isConfigured()) {
            return [
                'reply' => 'El modo base de datos esta desactivado o incompleto. Revisa `config/config.local.php` o `config/config.php`.',
                'meta' => ['mode' => 'database', 'configured' => false],
            ];
        }

        $fullSchema = $this->getSchema();
        $relevantSchema = $this->schemaSelector->selectRelevant(
            $fullSchema,
            $question,
            $history,
            max(1, (int) ($this->config['schema_max_tables'] ?? 8)),
            (bool) ($this->config['schema_include_related'] ?? true)
        );

        $plan = $this->resolvePlan($question, $history, $relevantSchema, $fullSchema);
        $planCacheHit = (bool) ($plan['_cache_hit'] ?? false);

        if (($plan['action'] ?? 'query') === 'answer' && !empty($plan['answer'])) {
            return [
                'reply' => trim((string) $plan['answer']),
                'meta' => [
                    'mode' => 'database',
                    'configured' => true,
                    'source' => 'planner',
                    'driver' => $this->adapter->getDriver(),
                    'schemaTablesUsed' => count($relevantSchema['tables'] ?? []),
                    'planCacheHit' => $planCacheHit,
                ],
            ];
        }

        $sql = $this->guardSql((string) ($plan['sql'] ?? ''), $relevantSchema);
        $rows = $this->runReadOnlyQuery($sql);
        $summarySource = 'php';
        $answer = $this->formatRowsAsAnswer($question, $rows);

        if ((bool) ($this->config['use_llm_summary'] ?? false)) {
            $answer = $this->ollama->chat([
                [
                    'role' => 'system',
                    'content' => $this->buildAnswerPrompt(),
                ],
                [
                    'role' => 'user',
                    'content' => $this->buildAnswerInput($question, $sql, $rows),
                ],
            ], 0.2, $this->getSummaryModel());
            $summarySource = 'llm';
        }

        return [
            'reply' => $answer,
            'meta' => [
                'mode' => 'database',
                'configured' => true,
                'source' => 'query',
                'summarySource' => $summarySource,
                'driver' => $this->adapter->getDriver(),
                'dialect' => $this->adapter->getDialectLabel(),
                'schemaTablesUsed' => count($relevantSchema['tables'] ?? []),
                'schemaTableCount' => (int) ($fullSchema['stats']['tableCount'] ?? 0),
                'planCacheHit' => $planCacheHit,
                'sql' => $sql,
                'rowCount' => count($rows),
            ],
        ];
    }

    private function getPdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $this->pdo = $this->adapter->connect($this->config);

        return $this->pdo;
    }

    /**
     * @return array{tables: array<string, array<string, mixed>>, stats: array<string, int>}
     */
    private function getSchema(): array
    {
        $cacheKey = 'schema:' . md5($this->adapter->getCacheSignature($this->config));

        if (isset($_SESSION['schema_cache'][$cacheKey]) && is_array($_SESSION['schema_cache'][$cacheKey])) {
            /** @var array{tables: array<string, array<string, mixed>>, stats: array<string, int>} $schema */
            $schema = $_SESSION['schema_cache'][$cacheKey];
            return $schema;
        }

        $schema = $this->adapter->loadSchema($this->getPdo(), $this->config);
        $_SESSION['schema_cache'][$cacheKey] = $schema;

        return $schema;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function runReadOnlyQuery(string $sql): array
    {
        $pdo = $this->getPdo();
        $rows = [];
        $maxRows = max(1, (int) ($this->config['max_rows'] ?? 25));
        $driver = $this->adapter->getDriver();
        $timeoutMs = max(1000, (int) ($this->config['query_timeout_ms'] ?? 5000));

        $this->beginGuardedQuery($pdo, $driver, $timeoutMs);

        try {
            $statement = $pdo->query($sql);

            while (($row = $statement->fetch()) !== false) {
                $rows[] = $row;

                if (count($rows) >= $maxRows) {
                    break;
                }
            }

            $this->finishGuardedQuery($pdo, $driver, true);
        } catch (Throwable $exception) {
            $this->finishGuardedQuery($pdo, $driver, false);
            throw new RuntimeException('No se pudo ejecutar la consulta segura: ' . $exception->getMessage(), 0, $exception);
        }

        return $rows;
    }

    /**
     * @param array{tables: array<string, array<string, mixed>>, stats: array<string, int>} $relevantSchema
     */
    private function guardSql(string $sql, array $relevantSchema): string
    {
        $sql = $this->sanitizeSql($sql);
        $this->rejectDangerousFunctions($sql);
        $this->ensureQueryUsesAllowedTables($sql, array_keys($relevantSchema['tables'] ?? []));

        return $this->applyLimit($sql);
    }

    private function sanitizeSql(string $sql): string
    {
        $sql = trim($sql);
        $sql = preg_replace('/^```(?:sql)?|```$/mi', '', $sql) ?? $sql;
        $sql = trim($sql);
        $sql = rtrim($sql, ';');

        if ($sql === '') {
            throw new RuntimeException('El modelo no genero una consulta SQL util.');
        }

        if (!preg_match('/^(select|with)\b/i', $sql)) {
            throw new RuntimeException('Solo se permiten consultas SELECT o WITH.');
        }

        if (preg_match('/(--|\/\*|\*\/|#)/', $sql)) {
            throw new RuntimeException('No se permiten comentarios SQL en la consulta generada.');
        }

        if (preg_match('/\b(insert|update|delete|drop|alter|truncate|create|replace|grant|revoke|call|handler|load|outfile|infile|set|use|attach|detach|copy|vacuum|analyze|explain)\b/i', $sql)) {
            throw new RuntimeException('La consulta propuesta no es de solo lectura.');
        }

        if (str_contains($sql, ';')) {
            throw new RuntimeException('Solo se permite una consulta por peticion.');
        }

        return $sql;
    }

    private function rejectDangerousFunctions(string $sql): void
    {
        $normalized = $this->stripQuotedStrings($sql);

        foreach (self::DANGEROUS_FUNCTIONS as $function) {
            if (preg_match('/\b' . preg_quote($function, '/') . '\s*\(/i', $normalized)) {
                throw new RuntimeException("La consulta usa una funcion bloqueada: {$function}.");
            }
        }
    }

    /**
     * @param array<int, string> $allowedTables
     */
    private function ensureQueryUsesAllowedTables(string $sql, array $allowedTables): void
    {
        if ($allowedTables === []) {
            throw new RuntimeException('No hay tablas visibles disponibles para la consulta.');
        }

        $allowed = array_fill_keys(array_map(static fn (string $table): string => strtolower($table), $allowedTables), true);
        $cteNames = $this->extractCteNames($sql);

        foreach ($this->extractReferencedTables($sql) as $tableName) {
            if (isset($allowed[$tableName]) || isset($cteNames[$tableName])) {
                continue;
            }

            throw new RuntimeException("La consulta intenta usar una tabla fuera del esquema permitido: {$tableName}.");
        }
    }

    private function applyLimit(string $sql): string
    {
        if (!(bool) ($this->config['enforce_limit'] ?? true)) {
            return $sql;
        }

        if (preg_match('/\blimit\s+\d+\b/i', $sql) || preg_match('/\bfetch\s+first\s+\d+\s+rows\s+only\b/i', $sql)) {
            return $sql;
        }

        return $sql . ' LIMIT ' . max(1, (int) ($this->config['max_rows'] ?? 25));
    }

    /**
     * @return array<int, string>
     */
    private function extractReferencedTables(string $sql): array
    {
        $normalized = $this->stripQuotedStrings($sql);
        $matches = [];
        preg_match_all('/\b(?:from|join)\s+((?:[`"\[]?[a-zA-Z0-9_]+[`"\]]?\.)?[`"\[]?[a-zA-Z0-9_]+[`"\]]?)/i', $normalized, $matches);
        $tables = [];

        foreach ($matches[1] ?? [] as $match) {
            $table = $this->normalizeIdentifier((string) $match);

            if ($table !== '') {
                $tables[] = $table;
            }
        }

        return array_values(array_unique($tables));
    }

    /**
     * @return array<string, true>
     */
    private function extractCteNames(string $sql): array
    {
        $normalized = $this->stripQuotedStrings($sql);
        $matches = [];
        preg_match_all('/\b([a-zA-Z_][a-zA-Z0-9_]*)\s+as\s*\(/i', $normalized, $matches);
        $ctes = [];

        foreach ($matches[1] ?? [] as $match) {
            $ctes[strtolower((string) $match)] = true;
        }

        return $ctes;
    }

    private function normalizeIdentifier(string $identifier): string
    {
        $parts = explode('.', str_replace(['[', ']', '`', '"'], '', trim($identifier)));
        $name = strtolower(trim((string) end($parts)));

        return preg_match('/^[a-z_][a-z0-9_]*$/', $name) ? $name : '';
    }

    private function stripQuotedStrings(string $sql): string
    {
        $sql = preg_replace("/'(?:''|[^'])*'/", "''", $sql) ?? $sql;

        return preg_replace('/"(?:\\"|[^"])*"/', '""', $sql) ?? $sql;
    }

    private function beginGuardedQuery(PDO $pdo, string $driver, int $timeoutMs): void
    {
        match ($driver) {
            'mysql' => $this->beginMysqlReadOnlyQuery($pdo, $timeoutMs),
            'pgsql' => $this->beginPostgresReadOnlyQuery($pdo, $timeoutMs),
            'sqlite' => $this->beginSqliteReadOnlyQuery($pdo, $timeoutMs),
            default => null,
        };
    }

    private function finishGuardedQuery(PDO $pdo, string $driver, bool $success): void
    {
        if (!in_array($driver, ['mysql', 'pgsql'], true)) {
            return;
        }

        try {
            $pdo->exec($success ? 'COMMIT' : 'ROLLBACK');
        } catch (Throwable) {
        }
    }

    private function beginMysqlReadOnlyQuery(PDO $pdo, int $timeoutMs): void
    {
        $pdo->exec('SET SESSION max_execution_time = ' . $timeoutMs);
        $pdo->exec('START TRANSACTION READ ONLY');
    }

    private function beginPostgresReadOnlyQuery(PDO $pdo, int $timeoutMs): void
    {
        $pdo->exec('BEGIN READ ONLY');
        $pdo->exec('SET LOCAL statement_timeout = ' . $timeoutMs);
    }

    private function beginSqliteReadOnlyQuery(PDO $pdo, int $timeoutMs): void
    {
        $pdo->exec('PRAGMA busy_timeout = ' . $timeoutMs);
        $pdo->exec('PRAGMA query_only = ON');
    }

    /**
     * @param array<int, array{role: string, content: string}> $history
     */
    private function buildPlannerInput(string $question, array $history): string
    {
        $historyLimit = max(0, (int) ($this->config['planner_history_messages'] ?? 2));
        $recentHistory = $historyLimit > 0 ? array_slice($history, -$historyLimit) : [];
        $historyLines = [];

        foreach ($recentHistory as $item) {
            $historyLines[] = strtoupper($item['role']) . ': ' . $item['content'];
        }

        return "Historial reciente:\n"
            . ($historyLines !== [] ? implode("\n", $historyLines) : 'Sin historial previo.')
            . "\n\nPregunta actual:\n{$question}";
    }

    /**
     * @param array{tables: array<string, array<string, mixed>>, stats: array<string, int>} $relevantSchema
     * @param array{tables: array<string, array<string, mixed>>, stats: array<string, int>} $fullSchema
     */
    private function buildPlannerPrompt(array $relevantSchema, array $fullSchema): string
    {
        $relevantContext = $this->renderSchemaContext($relevantSchema);
        $fullTableCount = (int) ($fullSchema['stats']['tableCount'] ?? 0);
        $usedTableCount = count($relevantSchema['tables'] ?? []);
        $maxRows = max(1, (int) ($this->config['max_rows'] ?? 25));

        return <<<PROMPT
Eres un asistente que convierte preguntas de negocio en SQL {$this->adapter->getDialectLabel()} seguro.
Solo puedes usar tablas y columnas del esquema visible.

Resumen:
- Motor SQL: {$this->adapter->getDialectLabel()}
- Tablas visibles para esta pregunta: {$usedTableCount}
- Tablas totales en la base: {$fullTableCount}
- Filas maximas esperadas: {$maxRows}

Esquema relevante:

{$relevantContext}

Reglas obligatorias:
- Responde solo con JSON valido.
- Usa este formato exacto: {"action":"query|answer","sql":"...","answer":"..."}.
- Si necesitas datos, usa action "query" y genera una sola consulta de solo lectura.
- Solo puedes usar SELECT o WITH.
- Nunca inventes columnas o tablas fuera del esquema visible.
- Evita funciones costosas o no deterministas.
- Si la pregunta no requiere consultar datos, usa action "answer" y responde en "answer".
- Prefiere consultas simples y claras.
- Si el esquema visible no basta, responde con action "answer" indicando que falta contexto.
PROMPT;
    }

    private function buildAnswerPrompt(): string
    {
        return <<<PROMPT
Eres un analista que responde en espanol con base en resultados tabulares.
Reglas:
- Usa solo la informacion recibida.
- Si no hay filas, dilo de forma explicita.
- No inventes datos faltantes.
- Explica la respuesta de forma clara y breve.
PROMPT;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function formatRowsAsAnswer(string $question, array $rows): string
    {
        if ($rows === []) {
            return "No encontre registros para responder: {$question}";
        }

        if (count($rows) === 1 && count($rows[0]) === 1) {
            $column = (string) array_key_first($rows[0]);
            $value = (string) current($rows[0]);

            return "Resultado: {$value} ({$column}).";
        }

        $lines = [];

        foreach ($rows as $index => $row) {
            $pairs = [];

            foreach ($row as $column => $value) {
                if ($value === null || $value === '') {
                    continue;
                }

                $pairs[] = "{$column}: {$value}";
            }

            if ($pairs === []) {
                continue;
            }

            $lines[] = '- ' . implode(', ', $pairs);

            if ($index >= 9) {
                break;
            }
        }

        if ($lines === []) {
            return 'La consulta devolvio filas, pero sin datos legibles para resumir.';
        }

        return "Resultados para: {$question}\n" . implode("\n", $lines);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function buildAnswerInput(string $question, string $sql, array $rows): string
    {
        return "Pregunta:\n{$question}\n\nSQL ejecutado:\n{$sql}\n\nFilas devueltas (JSON):\n"
            . json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function parsePlannerResponse(string $response): array
    {
        $decoded = json_decode($response, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $response, $matches) === 1) {
            $decoded = json_decode($matches[0], true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [
            'action' => 'answer',
            'answer' => 'No pude convertir la peticion a una consulta segura. Reformula la pregunta o ajusta el contexto del esquema.',
        ];
    }

    private function getPlannerModel(): ?string
    {
        $model = trim((string) ($this->config['planner_model'] ?? ''));

        return $model !== '' ? $model : null;
    }

    private function getSummaryModel(): ?string
    {
        $model = trim((string) ($this->config['summary_model'] ?? ''));

        return $model !== '' ? $model : null;
    }

    /**
     * @param array{tables: array<string, array<string, mixed>>, stats: array<string, int>} $schema
     */
    private function renderSchemaContext(array $schema): string
    {
        $chunks = [];
        $maxColumnsPerTable = max(1, (int) ($this->config['schema_max_columns_per_table'] ?? 20));

        foreach (($schema['tables'] ?? []) as $tableName => $table) {
            $columnParts = [];

            foreach (array_slice(($table['columns'] ?? []), 0, $maxColumnsPerTable) as $column) {
                $columnParts[] = sprintf(
                    '%s:%s%s%s',
                    (string) ($column['name'] ?? ''),
                    (string) ($column['type'] ?? ''),
                    ((string) ($column['key'] ?? '')) !== '' ? '[' . (string) $column['key'] . ']' : '',
                    ((string) ($column['nullable'] ?? '')) === 'NO' ? '!' : ''
                );
            }

            if (count($table['columns'] ?? []) > $maxColumnsPerTable) {
                $columnParts[] = '...';
            }

            $line = "Tabla {$tableName}: " . implode(', ', $columnParts);

            if (($table['foreign_keys'] ?? []) !== []) {
                $relations = [];

                foreach ($table['foreign_keys'] as $foreignKey) {
                    $relations[] = sprintf(
                        '%s->%s.%s',
                        (string) ($foreignKey['column'] ?? ''),
                        (string) ($foreignKey['references_table'] ?? ''),
                        (string) ($foreignKey['references_column'] ?? '')
                    );
                }

                $line .= ' | FK: ' . implode(', ', $relations);
            }

            $chunks[] = $line;
        }

        return implode(PHP_EOL . PHP_EOL, $chunks);
    }

    /**
     * @param array<int, array{role: string, content: string}> $history
     * @param array{tables: array<string, array<string, mixed>>, stats: array<string, int>} $relevantSchema
     * @param array{tables: array<string, array<string, mixed>>, stats: array<string, int>} $fullSchema
     * @return array<string, mixed>
     */
    private function resolvePlan(string $question, array $history, array $relevantSchema, array $fullSchema): array
    {
        $cacheKey = $this->buildPlanCacheKey($question, $history, $relevantSchema);

        if ((bool) ($this->config['plan_cache_enabled'] ?? true)) {
            $cachedPlan = $this->getCachedPlan($cacheKey);

            if (is_array($cachedPlan)) {
                $cachedPlan['_cache_hit'] = true;
                return $cachedPlan;
            }
        }

        $plannerResponse = $this->ollama->chat([
            [
                'role' => 'system',
                'content' => $this->buildPlannerPrompt($relevantSchema, $fullSchema),
            ],
            [
                'role' => 'user',
                'content' => $this->buildPlannerInput($question, $history),
            ],
        ], 0.1, $this->getPlannerModel());

        $plan = $this->parsePlannerResponse($plannerResponse);

        if ((bool) ($this->config['plan_cache_enabled'] ?? true)
            && ($plan['action'] ?? 'query') === 'query'
            && !empty($plan['sql'])) {
            $this->storeCachedPlan($cacheKey, $plan);
        }

        $plan['_cache_hit'] = false;

        return $plan;
    }

    /**
     * @param array<int, array{role: string, content: string}> $history
     * @param array{tables: array<string, array<string, mixed>>, stats: array<string, int>} $relevantSchema
     */
    private function buildPlanCacheKey(string $question, array $history, array $relevantSchema): string
    {
        $historyLimit = max(0, (int) ($this->config['planner_history_messages'] ?? 2));
        $historyForKey = $historyLimit > 0 ? array_slice($history, -$historyLimit) : [];
        $historyText = [];

        foreach ($historyForKey as $item) {
            $historyText[] = strtolower(trim($item['role'] . ':' . $item['content']));
        }

        return sha1(json_encode([
            'driver' => $this->adapter->getDriver(),
            'question' => $this->normalizeForCache($question),
            'history' => $historyText,
            'schema' => $this->schemaFingerprint($relevantSchema),
        ], JSON_THROW_ON_ERROR));
    }

    private function normalizeForCache(string $value): string
    {
        $value = strtolower(trim($value));
        $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $normalized = is_string($normalized) ? $normalized : $value;
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    /**
     * @param array{tables: array<string, array<string, mixed>>, stats: array<string, int>} $schema
     */
    private function schemaFingerprint(array $schema): string
    {
        $tables = [];

        foreach (($schema['tables'] ?? []) as $tableName => $table) {
            $tables[$tableName] = [
                'columns' => array_map(
                    static fn (array $column): array => [
                        'name' => (string) ($column['name'] ?? ''),
                        'type' => (string) ($column['type'] ?? ''),
                        'key' => (string) ($column['key'] ?? ''),
                    ],
                    $table['columns'] ?? []
                ),
                'foreign_keys' => $table['foreign_keys'] ?? [],
            ];
        }

        return sha1(json_encode($tables, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getCachedPlan(string $cacheKey): ?array
    {
        $cache = $this->readPlanCache();

        if (!isset($cache[$cacheKey]) || !is_array($cache[$cacheKey])) {
            return null;
        }

        return $cache[$cacheKey];
    }

    /**
     * @param array<string, mixed> $plan
     */
    private function storeCachedPlan(string $cacheKey, array $plan): void
    {
        $cache = $this->readPlanCache();
        $cache[$cacheKey] = $plan;

        $directory = dirname(self::PLAN_CACHE_FILE);

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            return;
        }

        $payload = json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $tempFile = self::PLAN_CACHE_FILE . '.tmp';

        file_put_contents($tempFile, $payload, LOCK_EX);

        if (is_file(self::PLAN_CACHE_FILE)) {
            unlink(self::PLAN_CACHE_FILE);
        }

        rename($tempFile, self::PLAN_CACHE_FILE);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function readPlanCache(): array
    {
        if (!is_file(self::PLAN_CACHE_FILE)) {
            return [];
        }

        $contents = file_get_contents(self::PLAN_CACHE_FILE);

        if (!is_string($contents) || trim($contents) === '') {
            return [];
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : [];
    }
}
