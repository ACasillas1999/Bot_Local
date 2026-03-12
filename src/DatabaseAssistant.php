<?php

declare(strict_types=1);

namespace BotLocal;

use PDO;
use PDOException;
use RuntimeException;

final class DatabaseAssistant
{
    private ?PDO $pdo = null;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly array $config,
        private readonly OllamaClient $ollama
    ) {
    }

    public function isConfigured(): bool
    {
        return (bool) ($this->config['enabled'] ?? false)
            && ($this->config['database'] ?? '') !== ''
            && ($this->config['username'] ?? '') !== '';
    }

    /**
     * @param array<int, array{role: string, content: string}> $history
     * @return array{reply: string, meta: array<string, mixed>}
     */
    public function answerQuestion(string $question, array $history): array
    {
        if (!$this->isConfigured()) {
            return [
                'reply' => 'El modo base de datos esta desactivado. Configura `config/config.php` antes de usarlo.',
                'meta' => ['mode' => 'database', 'configured' => false],
            ];
        }

        $schema = $this->getSchemaContext();
        $plannerResponse = $this->ollama->chat([
            [
                'role' => 'system',
                'content' => $this->buildPlannerPrompt($schema),
            ],
            [
                'role' => 'user',
                'content' => $this->buildPlannerInput($question, $history),
            ],
        ], 0.1);

        $plan = $this->parsePlannerResponse($plannerResponse);

        if (($plan['action'] ?? 'query') === 'answer' && !empty($plan['answer'])) {
            return [
                'reply' => trim((string) $plan['answer']),
                'meta' => ['mode' => 'database', 'configured' => true, 'source' => 'planner'],
            ];
        }

        $sql = $this->sanitizeSql((string) ($plan['sql'] ?? ''));
        $rows = $this->runReadOnlyQuery($sql);

        $answer = $this->ollama->chat([
            [
                'role' => 'system',
                'content' => $this->buildAnswerPrompt(),
            ],
            [
                'role' => 'user',
                'content' => $this->buildAnswerInput($question, $sql, $rows),
            ],
        ], 0.2);

        return [
            'reply' => $answer,
            'meta' => [
                'mode' => 'database',
                'configured' => true,
                'source' => 'query',
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

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->config['host'],
            $this->config['port'],
            $this->config['database'],
            $this->config['charset']
        );

        try {
            $this->pdo = new PDO($dsn, (string) $this->config['username'], (string) $this->config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $exception) {
            throw new RuntimeException('No se pudo conectar a la base de datos: ' . $exception->getMessage(), 0, $exception);
        }

        return $this->pdo;
    }

    private function getSchemaContext(): string
    {
        $cacheKey = 'schema:' . md5(json_encode([
            $this->config['host'] ?? '',
            $this->config['port'] ?? '',
            $this->config['database'] ?? '',
            $this->config['table_whitelist'] ?? [],
        ], JSON_THROW_ON_ERROR));

        if (isset($_SESSION['schema_cache'][$cacheKey])) {
            return (string) $_SESSION['schema_cache'][$cacheKey];
        }

        $pdo = $this->getPdo();
        $database = (string) $this->config['database'];
        $whitelist = $this->config['table_whitelist'] ?? [];

        $sql = 'SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, COLUMN_KEY, IS_NULLABLE
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = :schema';

        $params = ['schema' => $database];

        if (is_array($whitelist) && $whitelist !== []) {
            $placeholders = [];

            foreach (array_values($whitelist) as $index => $table) {
                $placeholder = ':table' . $index;
                $placeholders[] = $placeholder;
                $params['table' . $index] = $table;
            }

            $sql .= ' AND TABLE_NAME IN (' . implode(', ', $placeholders) . ')';
        }

        $sql .= ' ORDER BY TABLE_NAME, ORDINAL_POSITION';

        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $columns = $statement->fetchAll();

        if ($columns === []) {
            throw new RuntimeException('No se encontro informacion de esquema en la base de datos configurada.');
        }

        $grouped = [];

        foreach ($columns as $column) {
            $columnLine = sprintf(
                '- %s (%s, null: %s%s)',
                $column['COLUMN_NAME'],
                $column['DATA_TYPE'],
                $column['IS_NULLABLE'],
                $column['COLUMN_KEY'] !== '' ? ', key: ' . $column['COLUMN_KEY'] : ''
            );
            $grouped[$column['TABLE_NAME']][] = $columnLine;
        }

        $chunks = [];

        foreach ($grouped as $table => $definitions) {
            $chunks[] = "Tabla {$table}" . PHP_EOL . implode(PHP_EOL, $definitions);
        }

        $context = implode(PHP_EOL . PHP_EOL, $chunks);
        $_SESSION['schema_cache'][$cacheKey] = $context;

        return $context;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function runReadOnlyQuery(string $sql): array
    {
        $statement = $this->getPdo()->query($sql);
        $rows = [];
        $maxRows = (int) ($this->config['max_rows'] ?? 25);

        while (($row = $statement->fetch()) !== false) {
            $rows[] = $row;

            if (count($rows) >= $maxRows) {
                break;
            }
        }

        return $rows;
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

        if (preg_match('/\b(insert|update|delete|drop|alter|truncate|create|replace|grant|revoke|call|handler|load|outfile|infile|set|use)\b/i', $sql)) {
            throw new RuntimeException('La consulta propuesta no es de solo lectura.');
        }

        if (str_contains($sql, ';')) {
            throw new RuntimeException('Solo se permite una consulta por peticion.');
        }

        return $sql;
    }

    /**
     * @param array<int, array{role: string, content: string}> $history
     */
    private function buildPlannerInput(string $question, array $history): string
    {
        $recentHistory = array_slice($history, -6);
        $historyLines = [];

        foreach ($recentHistory as $item) {
            $historyLines[] = strtoupper($item['role']) . ': ' . $item['content'];
        }

        return "Historial reciente:\n"
            . ($historyLines !== [] ? implode("\n", $historyLines) : 'Sin historial previo.')
            . "\n\nPregunta actual:\n{$question}";
    }

    private function buildPlannerPrompt(string $schema): string
    {
        return <<<PROMPT
Eres un asistente que convierte preguntas de negocio en SQL MySQL seguro.
Tu unica fuente para generar consultas es este esquema:

{$schema}

Reglas obligatorias:
- Responde solo con JSON valido.
- Usa este formato exacto: {"action":"query|answer","sql":"...","answer":"..."}.
- Si necesitas datos, usa action "query" y genera una sola consulta de solo lectura.
- Solo puedes usar SELECT o WITH.
- Nunca inventes columnas o tablas fuera del esquema.
- Si la pregunta no requiere consultar datos, usa action "answer" y responde en "answer".
- Mantener las consultas simples y entendibles.
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
            'answer' => 'No pude convertir la peticion a una consulta segura. Reformula la pregunta o revisa el esquema configurado.',
        ];
    }
}
