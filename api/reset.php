<?php

declare(strict_types=1);

use BotLocal\AppServices;

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

    $coordinator = AppServices::makeChatCoordinator();

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
