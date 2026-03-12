<?php

declare(strict_types=1);

namespace BotLocal\Database;

use PDO;

interface DatabaseAdapterInterface
{
    /**
     * @param array<string, mixed> $config
     */
    public function isConfigured(array $config): bool;

    public function getDriver(): string;

    public function getDialectLabel(): string;

    /**
     * @param array<string, mixed> $config
     */
    public function connect(array $config): PDO;

    /**
     * @param array<string, mixed> $config
     * @return array{tables: array<string, array<string, mixed>>, stats: array<string, int>}
     */
    public function loadSchema(PDO $pdo, array $config): array;

    /**
     * @param array<string, mixed> $config
     */
    public function getCacheSignature(array $config): string;
}
