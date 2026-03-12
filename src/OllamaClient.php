<?php

declare(strict_types=1);

namespace BotLocal;

use RuntimeException;

final class OllamaClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly float $temperature,
        private readonly int $timeoutSeconds
    ) {
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     */
    public function chat(array $messages, ?float $temperature = null, ?string $model = null): string
    {
        $payload = [
            'model' => $model ?? $this->model,
            'stream' => false,
            'messages' => $messages,
            'options' => [
                'temperature' => $temperature ?? $this->temperature,
            ],
        ];

        $ch = curl_init(rtrim($this->baseUrl, '/') . '/api/chat');

        if ($ch === false) {
            throw new RuntimeException('No se pudo iniciar la conexion con Ollama.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('Ollama no respondio: ' . $error);
        }

        if ($httpCode >= 400) {
            throw new RuntimeException('Ollama devolvio un error HTTP ' . $httpCode . '.');
        }

        $decoded = json_decode($response, true);

        if (!is_array($decoded) || !isset($decoded['message']['content'])) {
            throw new RuntimeException('La respuesta de Ollama no tiene el formato esperado.');
        }

        return trim((string) $decoded['message']['content']);
    }

    public function getModel(): string
    {
        return $this->model;
    }
}
