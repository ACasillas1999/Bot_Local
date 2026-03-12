<?php

declare(strict_types=1);

namespace BotLocal\Database;

use PDO;
use RuntimeException;

final class MySqlAdapter extends AbstractDatabaseAdapter
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
        return 'mysql';
    }

    public function getDialectLabel(): string
    {
        return 'MySQL';
    }

    /**
     * @param array<string, mixed> $config
     */
    public function connect(array $config): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            (string) ($config['host'] ?? '127.0.0.1'),
            (int) ($config['port'] ?? 3306),
            (string) ($config['database'] ?? ''),
            (string) ($config['charset'] ?? 'utf8mb4')
        );

        return $this->createPdo($dsn, $config);
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadSchema(PDO $pdo, array $config): array
    {
        $database = (string) ($config['database'] ?? '');
        $whitelist = is_array($config['table_whitelist'] ?? null) ? array_values($config['table_whitelist']) : [];

        if ($database === '') {
            throw new RuntimeException('Falta el nombre de la base de datos para MySQL.');
        }

        $columnsSql = 'SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, COLUMN_KEY, IS_NULLABLE, ORDINAL_POSITION
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = :schema';

        $columnParams = ['schema' => $database];

        if ($whitelist !== []) {
            $placeholders = [];

            foreach ($whitelist as $index => $table) {
                $placeholder = ':table' . $index;
                $placeholders[] = $placeholder;
                $columnParams['table' . $index] = $table;
            }

            $columnsSql .= ' AND TABLE_NAME IN (' . implode(', ', $placeholders) . ')';
        }

        $columnsSql .= ' ORDER BY TABLE_NAME, ORDINAL_POSITION';
        $columnsStmt = $pdo->prepare($columnsSql);
        $columnsStmt->execute($columnParams);
        $columns = $columnsStmt->fetchAll();

        if ($columns === []) {
            throw new RuntimeException('No se encontro informacion de esquema en la base MySQL configurada.');
        }

        $tables = [];

        foreach ($columns as $column) {
            $tableName = (string) $column['TABLE_NAME'];
            $tables[$tableName] ??= [
                'name' => $tableName,
                'columns' => [],
                'foreign_keys' => [],
            ];

            $tables[$tableName]['columns'][] = [
                'name' => (string) $column['COLUMN_NAME'],
                'type' => (string) $column['DATA_TYPE'],
                'nullable' => (string) $column['IS_NULLABLE'],
                'key' => (string) $column['COLUMN_KEY'],
            ];
        }

        $fkSql = 'SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = :schema
              AND REFERENCED_TABLE_NAME IS NOT NULL';

        $fkParams = ['schema' => $database];

        if ($whitelist !== []) {
            $placeholders = [];

            foreach ($whitelist as $index => $table) {
                $placeholder = ':fk_table' . $index;
                $placeholders[] = $placeholder;
                $fkParams['fk_table' . $index] = $table;
            }

            $fkSql .= ' AND TABLE_NAME IN (' . implode(', ', $placeholders) . ')';
        }

        $fkStmt = $pdo->prepare($fkSql);
        $fkStmt->execute($fkParams);

        foreach ($fkStmt->fetchAll() as $fk) {
            $tableName = (string) $fk['TABLE_NAME'];

            if (!isset($tables[$tableName])) {
                continue;
            }

            $tables[$tableName]['foreign_keys'][] = [
                'column' => (string) $fk['COLUMN_NAME'],
                'references_table' => (string) $fk['REFERENCED_TABLE_NAME'],
                'references_column' => (string) $fk['REFERENCED_COLUMN_NAME'],
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
            'whitelist' => $config['table_whitelist'] ?? [],
        ], JSON_THROW_ON_ERROR);
    }
}
