<?php

declare(strict_types=1);

namespace BotLocal\Database;

use PDO;
use RuntimeException;

final class SqliteAdapter extends AbstractDatabaseAdapter
{
    /**
     * @param array<string, mixed> $config
     */
    public function isConfigured(array $config): bool
    {
        return ($config['sqlite_path'] ?? '') !== '';
    }

    public function getDriver(): string
    {
        return 'sqlite';
    }

    public function getDialectLabel(): string
    {
        return 'SQLite';
    }

    /**
     * @param array<string, mixed> $config
     */
    public function connect(array $config): PDO
    {
        $path = (string) ($config['sqlite_path'] ?? '');

        if ($path === '') {
            throw new RuntimeException('Falta `sqlite_path` para la conexion SQLite.');
        }

        return $this->createPdo('sqlite:' . $path, $config);
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadSchema(PDO $pdo, array $config): array
    {
        $whitelist = is_array($config['table_whitelist'] ?? null) ? array_values($config['table_whitelist']) : [];
        $tableSql = "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name";
        $tableNames = $pdo->query($tableSql)->fetchAll(PDO::FETCH_COLUMN) ?: [];

        if ($tableNames === []) {
            throw new RuntimeException('No se encontro informacion de esquema en la base SQLite configurada.');
        }

        $tables = [];

        foreach ($tableNames as $tableName) {
            $tableName = (string) $tableName;

            if ($whitelist !== [] && !in_array($tableName, $whitelist, true)) {
                continue;
            }

            $tables[$tableName] = [
                'name' => $tableName,
                'columns' => [],
                'foreign_keys' => [],
            ];

            $columnRows = $pdo->query('PRAGMA table_info("' . str_replace('"', '""', $tableName) . '")')->fetchAll();

            foreach ($columnRows as $column) {
                $tables[$tableName]['columns'][] = [
                    'name' => (string) $column['name'],
                    'type' => (string) $column['type'],
                    'nullable' => ((int) $column['notnull'] === 1) ? 'NO' : 'YES',
                    'key' => ((int) $column['pk'] === 1) ? 'PRI' : '',
                ];
            }

            $fkRows = $pdo->query('PRAGMA foreign_key_list("' . str_replace('"', '""', $tableName) . '")')->fetchAll();

            foreach ($fkRows as $fk) {
                $tables[$tableName]['foreign_keys'][] = [
                    'column' => (string) $fk['from'],
                    'references_table' => (string) $fk['table'],
                    'references_column' => (string) $fk['to'],
                ];
            }
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
            'sqlite_path' => $config['sqlite_path'] ?? '',
            'whitelist' => $config['table_whitelist'] ?? [],
        ], JSON_THROW_ON_ERROR);
    }
}
