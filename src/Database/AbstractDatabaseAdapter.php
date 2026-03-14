<?php

declare(strict_types=1);

namespace BotLocal\Database;

use PDO;
use PDOException;
use RuntimeException;

abstract class AbstractDatabaseAdapter implements DatabaseAdapterInterface
{
    /**
     * @param array<string, mixed> $config
     */
    protected function createPdo(string $dsn, array $config): PDO
    {
        try {
            return new PDO($dsn, (string) ($config['username'] ?? ''), (string) ($config['password'] ?? ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $exception) {
            throw new RuntimeException('No se pudo conectar a la base de datos: ' . $exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @param array<int, string> $whitelist
     * @param array<string, array<string, mixed>> $tables
     * @return array<string, array<string, mixed>>
     */
    protected function applyWhitelist(array $whitelist, array $tables): array
    {
        if ($whitelist === []) {
            return $tables;
        }

        $allowed = array_flip($whitelist);

        return array_filter(
            $tables,
            static fn (string $tableName): bool => isset($allowed[$tableName]),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * @param array<string, array<string, mixed>> $tables
     * @return array{tables: array<string, array<string, mixed>>, stats: array<string, int>}
     */
    protected function finalizeSchema(array $tables): array
    {
        ksort($tables);

        $columnCount = 0;

        foreach ($tables as $table) {
            $columnCount += count($table['columns'] ?? []);
        }

        return [
            'tables' => $tables,
            'stats' => [
                'tableCount' => count($tables),
                'columnCount' => $columnCount,
            ],
        ];
    }
}
