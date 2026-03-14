<?php

declare(strict_types=1);

use BotLocal\AppServices;

require_once dirname(__DIR__) . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $ollama = AppServices::makeOllamaClient();
    $knowledge = AppServices::makeKnowledgeBase();
    $databaseAssistant = AppServices::makeDatabaseAssistant($ollama);
    $coordinator = AppServices::makeChatCoordinator($ollama, $knowledge, $databaseAssistant);

    echo json_encode([
        'appName' => (string) app_config('app.name'),
        'model' => $ollama->getModel(),
        'knowledgeFiles' => $knowledge->listDocuments(),
        'databaseConfigured' => $databaseAssistant->isConfigured(),
        'requestTimeout' => (int) app_config('app.request_timeout'),
        'maxMessageChars' => (int) app_config('app.max_message_chars', 4000),
        'conversations' => $coordinator->getAllHistories(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'error' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
