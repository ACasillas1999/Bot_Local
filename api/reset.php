<?php

declare(strict_types=1);

use BotLocal\ChatCoordinator;
use BotLocal\DatabaseAssistant;
use BotLocal\KnowledgeBase;
use BotLocal\OllamaClient;

require_once dirname(__DIR__) . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Metodo no permitido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $payload = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $mode = (string) ($payload['mode'] ?? 'all');

    $ollama = new OllamaClient(
        (string) app_config('app.ollama_url'),
        (string) app_config('app.model'),
        (float) app_config('app.temperature'),
        (int) app_config('app.request_timeout')
    );

    $coordinator = new ChatCoordinator(
        $ollama,
        new KnowledgeBase((string) app_config('knowledge.path'), (int) app_config('knowledge.max_chars')),
        new DatabaseAssistant((array) app_config('database'), $ollama),
        (int) app_config('app.history_limit')
    );

    $coordinator->reset($mode);

    echo json_encode([
        'ok' => true,
        'conversations' => $coordinator->getAllHistories(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(422);
    echo json_encode([
        'error' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
