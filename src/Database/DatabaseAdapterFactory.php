<?php

declare(strict_types=1);

namespace BotLocal\Database;

use RuntimeException;

final class DatabaseAdapterFactory
{
    /**
     * @param array<string, mixed> $config
     */
    public static function make(array $config): DatabaseAdapterInterface
    {
        $driver = strtolower(trim((string) ($config['driver'] ?? 'mysql')));

        return match ($driver) {
            'mysql' => new MySqlAdapter(),
            'pgsql', 'postgres', 'postgresql' => new PostgresAdapter(),
            'sqlite' => new SqliteAdapter(),
            default => throw new RuntimeException('Driver de base de datos no soportado: ' . $driver),
        };
    }
}
