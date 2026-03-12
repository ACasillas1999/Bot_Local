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

    $payload = json_decode((string) file_get_contents('php://input'), true);

    if (!is_array($payload)) {
        throw new RuntimeException('El cuerpo de la peticion no es JSON valido.');
    }

    $message = trim((string) ($payload['message'] ?? ''));
    $mode = (string) ($payload['mode'] ?? 'general');

    if ($message === '') {
        throw new RuntimeException('El mensaje no puede estar vacio.');
    }

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

    $result = $coordinator->chat($mode, $message);

    echo json_encode([
        'reply' => $result['reply'],
        'meta' => $result['meta'],
        'history' => $result['history'],
        'model' => $ollama->getModel(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(422);
    echo json_encode([
        'error' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
