<?php

declare(strict_types=1);

namespace BotLocal\Database;

use PDO;
use RuntimeException;

final class PostgresAdapter extends AbstractDatabaseAdapter
{
    /**
     * @param array<string, mixed> $config
     */
    public function isConfigured(array $config): bool
    {
        return ($config['database'] ?? '') !== '' && ($config['username'] ?? '') !== '';
    }

    public function getDriver(): string
    {
        return 'pgsql';
    }

    public function getDialectLabel(): string
    {
        return 'PostgreSQL';
    }

    /**
     * @param array<string, mixed> $config
     */
    public function connect(array $config): PDO
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            (string) ($config['host'] ?? '127.0.0.1'),
            (int) ($config['port'] ?? 5432),
            (string) ($config['database'] ?? '')
        );

        $pdo = $this->createPdo($dsn, $config);
        $schema = (string) ($config['schema'] ?? 'public');
        $pdo->exec("SET search_path TO {$schema}");

        return $pdo;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadSchema(PDO $pdo, array $config): array
    {
        $schema = (string) ($config['schema'] ?? 'public');
        $whitelist = is_array($config['table_whitelist'] ?? null) ? array_values($config['table_whitelist']) : [];

        $columnsSql = 'SELECT c.table_name, c.column_name, c.data_type, c.is_nullable,
                CASE WHEN tc.constraint_type = \'PRIMARY KEY\' THEN \'PRI\' ELSE \'\' END AS column_key,
                c.ordinal_position
            FROM information_schema.columns c
            LEFT JOIN information_schema.key_column_usage kcu
                ON c.table_schema = kcu.table_schema
               AND c.table_name = kcu.table_name
               AND c.column_name = kcu.column_name
            LEFT JOIN information_schema.table_constraints tc
                ON kcu.constraint_name = tc.constraint_name
               AND kcu.table_schema = tc.table_schema
               AND kcu.table_name = tc.table_name
            WHERE c.table_schema = :schema';

        $params = ['schema' => $schema];

        if ($whitelist !== []) {
            $placeholders = [];

            foreach ($whitelist as $index => $table) {
                $placeholder = ':table' . $index;
                $placeholders[] = $placeholder;
                $params['table' . $index] = $table;
            }

            $columnsSql .= ' AND c.table_name IN (' . implode(', ', $placeholders) . ')';
        }

        $columnsSql .= ' ORDER BY c.table_name, c.ordinal_position';
        $stmt = $pdo->prepare($columnsSql);
        $stmt->execute($params);
        $columns = $stmt->fetchAll();

        if ($columns === []) {
            throw new RuntimeException('No se encontro informacion de esquema en la base PostgreSQL configurada.');
        }

        $tables = [];

        foreach ($columns as $column) {
            $tableName = (string) $column['table_name'];
            $tables[$tableName] ??= [
                'name' => $tableName,
                'columns' => [],
                'foreign_keys' => [],
            ];

            $tables[$tableName]['columns'][] = [
                'name' => (string) $column['column_name'],
                'type' => (string) $column['data_type'],
                'nullable' => (string) $column['is_nullable'],
                'key' => (string) $column['column_key'],
            ];
        }

        $fkSql = 'SELECT
                tc.table_name,
                kcu.column_name,
                ccu.table_name AS referenced_table_name,
                ccu.column_name AS referenced_column_name
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
              ON tc.constraint_name = kcu.constraint_name
             AND tc.table_schema = kcu.table_schema
            JOIN information_schema.constraint_column_usage ccu
              ON ccu.constraint_name = tc.constraint_name
             AND ccu.table_schema = tc.table_schema
            WHERE tc.constraint_type = \'FOREIGN KEY\'
              AND tc.table_schema = :schema';

        $fkParams = ['schema' => $schema];

        if ($whitelist !== []) {
            $placeholders = [];

            foreach ($whitelist as $index => $table) {
                $placeholder = ':fk_table' . $index;
                $placeholders[] = $placeholder;
                $fkParams['fk_table' . $index] = $table;
            }

            $fkSql .= ' AND tc.table_name IN (' . implode(', ', $placeholders) . ')';
        }

        $fkStmt = $pdo->prepare($fkSql);
        $fkStmt->execute($fkParams);

        foreach ($fkStmt->fetchAll() as $fk) {
            $tableName = (string) $fk['table_name'];

            if (!isset($tables[$tableName])) {
                continue;
            }

            $tables[$tableName]['foreign_keys'][] = [
                'column' => (string) $fk['column_name'],
                'references_table' => (string) $fk['referenced_table_name'],
                'references_column' => (string) $fk['referenced_column_name'],
            ];
        }

        return $this->finalizeSchema($this->applyWhitelist($whitelist, $tables));
    }

    /**
     * @param array<string, mixed> $config
     */
    public function getCacheSignature(array $config): string
    {
        return json_encode([
            'driver' => $this->getDriver(),
            'host' => $config['host'] ?? '',
            'port' => $config['port'] ?? '',
            'database' => $config['database'] ?? '',
            'schema' => $config['schema'] ?? 'public',
            'whitelist' => $config['table_whitelist'] ?? [],
        ], JSON_THROW_ON_ERROR);
    }
}
