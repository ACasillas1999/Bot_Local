<?php

declare(strict_types=1);

use BotLocal\ChatCoordinator;
use BotLocal\DatabaseAssistant;
use BotLocal\KnowledgeBase;
use BotLocal\OllamaClient;

require_once dirname(__DIR__) . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $ollama = new OllamaClient(
        (string) app_config('app.ollama_url'),
        (string) app_config('app.model'),
        (float) app_config('app.temperature'),
        (int) app_config('app.request_timeout')
    );

    $knowledge = new KnowledgeBase((string) app_config('knowledge.path'), (int) app_config('knowledge.max_chars'));
    $databaseAssistant = new DatabaseAssistant((array) app_config('database'), $ollama);
    $coordinator = new ChatCoordinator(
        $ollama,
        $knowledge,
        $databaseAssistant,
        (int) app_config('app.history_limit')
    );

    echo json_encode([
        'appName' => (string) app_config('app.name'),
        'model' => $ollama->getModel(),
        'knowledgeFiles' => $knowledge->listDocuments(),
        'databaseConfigured' => $databaseAssistant->isConfigured(),
        'conversations' => $coordinator->getAllHistories(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'error' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
