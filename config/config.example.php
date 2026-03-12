<?php

declare(strict_types=1);

return [
    'app' => [
        'name' => 'BotLocal',
        'ollama_url' => 'http://127.0.0.1:11434',
        'model' => 'qwen3:30b',
        'temperature' => 0.4,
        'history_limit' => 16,
        'request_timeout' => 120,
    ],
    'knowledge' => [
        'path' => __DIR__ . '/../knowledge',
        'max_chars' => 12000,
    ],
    'database' => [
        'enabled' => false,
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => '',
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
        'table_whitelist' => [],
        'max_rows' => 25,
    ],
];
