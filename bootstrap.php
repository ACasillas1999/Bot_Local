<?php

declare(strict_types=1);

define('BOTLOCAL_ROOT', __DIR__);

ensure_directory(BOTLOCAL_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'sessions');
ensure_directory(BOTLOCAL_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache');

if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

session_save_path(BOTLOCAL_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'sessions');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'BotLocal\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

function app_config(?string $path = null, mixed $default = null): mixed
{
    static $config = null;

    if ($config === null) {
        $config = load_app_config();
    }

    if ($path === null || $path === '') {
        return $config;
    }

    $segments = explode('.', $path);
    $value = $config;

    foreach ($segments as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }

        $value = $value[$segment];
    }

    return $value;
}

/**
 * @return array<string, mixed>
 */
function load_app_config(): array
{
    $configDirectory = BOTLOCAL_ROOT . DIRECTORY_SEPARATOR . 'config';
    $config = [];

    foreach ([
        'config.example.php',
        'config.php',
        'config.local.php',
    ] as $fileName) {
        $file = $configDirectory . DIRECTORY_SEPARATOR . $fileName;

        if (!is_file($file)) {
            continue;
        }

        $loaded = require $file;

        if (!is_array($loaded)) {
            throw new \RuntimeException("El archivo de configuracion {$fileName} no devolvio un arreglo.");
        }

        $config = array_replace_recursive($config, $loaded);
    }

    $config = apply_env_overrides($config);
    validate_app_config($config);

    return $config;
}

/**
 * @param array<string, mixed> $config
 * @return array<string, mixed>
 */
function apply_env_overrides(array $config): array
{
    $map = [
        'BOTLOCAL_APP_NAME' => ['app', 'name'],
        'BOTLOCAL_OLLAMA_URL' => ['app', 'ollama_url'],
        'BOTLOCAL_MODEL' => ['app', 'model'],
        'BOTLOCAL_TEMPERATURE' => ['app', 'temperature'],
        'BOTLOCAL_HISTORY_LIMIT' => ['app', 'history_limit'],
        'BOTLOCAL_REQUEST_TIMEOUT' => ['app', 'request_timeout'],
        'BOTLOCAL_MAX_MESSAGE_CHARS' => ['app', 'max_message_chars'],
        'BOTLOCAL_KNOWLEDGE_PATH' => ['knowledge', 'path'],
        'BOTLOCAL_KNOWLEDGE_MAX_CHARS' => ['knowledge', 'max_chars'],
        'BOTLOCAL_KNOWLEDGE_CHUNK_CHARS' => ['knowledge', 'chunk_chars'],
        'BOTLOCAL_KNOWLEDGE_MAX_CHUNKS' => ['knowledge', 'max_chunks'],
        'BOTLOCAL_DB_ENABLED' => ['database', 'enabled'],
        'BOTLOCAL_DB_DRIVER' => ['database', 'driver'],
        'BOTLOCAL_DB_HOST' => ['database', 'host'],
        'BOTLOCAL_DB_PORT' => ['database', 'port'],
        'BOTLOCAL_DB_NAME' => ['database', 'database'],
        'BOTLOCAL_DB_SCHEMA' => ['database', 'schema'],
        'BOTLOCAL_DB_SQLITE_PATH' => ['database', 'sqlite_path'],
        'BOTLOCAL_DB_USERNAME' => ['database', 'username'],
        'BOTLOCAL_DB_PASSWORD' => ['database', 'password'],
        'BOTLOCAL_DB_CHARSET' => ['database', 'charset'],
        'BOTLOCAL_DB_SCHEMA_MAX_TABLES' => ['database', 'schema_max_tables'],
        'BOTLOCAL_DB_SCHEMA_MAX_COLUMNS' => ['database', 'schema_max_columns_per_table'],
        'BOTLOCAL_DB_PLANNER_HISTORY' => ['database', 'planner_history_messages'],
        'BOTLOCAL_DB_PLANNER_MODEL' => ['database', 'planner_model'],
        'BOTLOCAL_DB_SUMMARY_MODEL' => ['database', 'summary_model'],
        'BOTLOCAL_DB_USE_LLM_SUMMARY' => ['database', 'use_llm_summary'],
        'BOTLOCAL_DB_MAX_ROWS' => ['database', 'max_rows'],
        'BOTLOCAL_DB_QUERY_TIMEOUT_MS' => ['database', 'query_timeout_ms'],
        'BOTLOCAL_DB_ENFORCE_LIMIT' => ['database', 'enforce_limit'],
    ];

    foreach ($map as $envName => $path) {
        $value = getenv($envName);

        if ($value === false || $value === '') {
            continue;
        }

        $normalized = match (true) {
            in_array($envName, ['BOTLOCAL_TEMPERATURE'], true) => (float) $value,
            in_array($envName, [
                'BOTLOCAL_HISTORY_LIMIT',
                'BOTLOCAL_REQUEST_TIMEOUT',
                'BOTLOCAL_MAX_MESSAGE_CHARS',
                'BOTLOCAL_KNOWLEDGE_MAX_CHARS',
                'BOTLOCAL_KNOWLEDGE_CHUNK_CHARS',
                'BOTLOCAL_KNOWLEDGE_MAX_CHUNKS',
                'BOTLOCAL_DB_PORT',
                'BOTLOCAL_DB_SCHEMA_MAX_TABLES',
                'BOTLOCAL_DB_SCHEMA_MAX_COLUMNS',
                'BOTLOCAL_DB_PLANNER_HISTORY',
                'BOTLOCAL_DB_MAX_ROWS',
                'BOTLOCAL_DB_QUERY_TIMEOUT_MS',
            ], true) => (int) $value,
            in_array($envName, [
                'BOTLOCAL_DB_ENABLED',
                'BOTLOCAL_DB_USE_LLM_SUMMARY',
                'BOTLOCAL_DB_ENFORCE_LIMIT',
            ], true) => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false,
            default => $value,
        };

        set_nested_config_value($config, $path, $normalized);
    }

    $whitelist = getenv('BOTLOCAL_DB_TABLE_WHITELIST');

    if ($whitelist !== false && trim($whitelist) !== '') {
        set_nested_config_value(
            $config,
            ['database', 'table_whitelist'],
            array_values(array_filter(array_map('trim', explode(',', $whitelist)), static fn (string $item): bool => $item !== ''))
        );
    }

    return $config;
}

/**
 * @param array<string, mixed> $config
 */
function validate_app_config(array $config): void
{
    if (!is_array($config['app'] ?? null)) {
        throw new \RuntimeException('Falta la seccion `app` en la configuracion.');
    }

    if (!is_array($config['knowledge'] ?? null)) {
        throw new \RuntimeException('Falta la seccion `knowledge` en la configuracion.');
    }

    if (!is_array($config['database'] ?? null)) {
        throw new \RuntimeException('Falta la seccion `database` en la configuracion.');
    }

    if (trim((string) ($config['app']['ollama_url'] ?? '')) === '') {
        throw new \RuntimeException('La configuracion `app.ollama_url` es obligatoria.');
    }

    if (trim((string) ($config['app']['model'] ?? '')) === '') {
        throw new \RuntimeException('La configuracion `app.model` es obligatoria.');
    }
}

/**
 * @param array<string, mixed> $config
 * @param array<int, string> $path
 */
function set_nested_config_value(array &$config, array $path, mixed $value): void
{
    $cursor = &$config;

    foreach ($path as $index => $segment) {
        if ($index === count($path) - 1) {
            $cursor[$segment] = $value;
            return;
        }

        if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
            $cursor[$segment] = [];
        }

        $cursor = &$cursor[$segment];
    }
}

function ensure_directory(string $path): void
{
    if (is_dir($path)) {
        return;
    }

    if (!mkdir($path, 0777, true) && !is_dir($path)) {
        throw new \RuntimeException("No se pudo crear el directorio {$path}.");
    }
}
