<?php

declare(strict_types=1);

namespace BotLocal;

use RuntimeException;

final class ChatCoordinator
{
    public function __construct(
        private readonly OllamaClient $ollama,
        private readonly KnowledgeBase $knowledgeBase,
        private readonly DatabaseAssistant $databaseAssistant,
        private readonly int $historyLimit
    ) {
    }

    /**
     * @return array{reply: string, meta: array<string, mixed>, history: array<int, array<string, mixed>>}
     */
    public function chat(string $mode, string $message): array
    {
        $mode = $this->normalizeMode($mode);
        $history = $this->getHistory($mode);

        if ($mode === 'database') {
            $result = $this->databaseAssistant->answerQuestion($message, $this->historyToModelMessages($history));
        } else {
            $messages = $this->buildMessages($mode, $message, $history);
            $result = [
                'reply' => $this->ollama->chat($messages),
                'meta' => ['mode' => $mode],
            ];
        }

        $history[] = ['role' => 'user', 'content' => $message];
        $history[] = [
            'role' => 'assistant',
            'content' => $result['reply'],
            'meta' => $result['meta'],
        ];

        $history = array_slice($history, -($this->historyLimit * 2));
        $_SESSION['conversations'][$mode] = $history;

        return [
            'reply' => $result['reply'],
            'meta' => $result['meta'],
            'history' => $history,
        ];
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function getAllHistories(): array
    {
        $conversations = $_SESSION['conversations'] ?? [];

        return [
            'general' => is_array($conversations['general'] ?? null) ? $conversations['general'] : [],
            'topic' => is_array($conversations['topic'] ?? null) ? $conversations['topic'] : [],
            'database' => is_array($conversations['database'] ?? null) ? $conversations['database'] : [],
        ];
    }

    public function reset(?string $mode = null): void
    {
        if ($mode === null || $mode === 'all') {
            $_SESSION['conversations'] = [];
            return;
        }

        $mode = $this->normalizeMode($mode);
        unset($_SESSION['conversations'][$mode]);
    }

    /**
     * @param array<int, array<string, mixed>> $history
     * @return array<int, array{role: string, content: string}>
     */
    private function buildMessages(string $mode, string $message, array $history): array
    {
        $messages = [
            [
                'role' => 'system',
                'content' => $this->buildSystemPrompt($mode),
            ],
        ];

        foreach (array_slice($this->historyToModelMessages($history), -($this->historyLimit * 2)) as $item) {
            $messages[] = $item;
        }

        $messages[] = [
            'role' => 'user',
            'content' => $message,
        ];

        return $messages;
    }

    private function buildSystemPrompt(string $mode): string
    {
        $basePrompt = <<<PROMPT
Eres un asistente local en espanol.
Responde con claridad, sin inventar datos y manteniendo el contexto de la conversacion.
Si no estas seguro, dilo de forma explicita.
PROMPT;

        if ($mode === 'topic') {
            $context = $this->knowledgeBase->buildContext();

            if ($context === '') {
                return $basePrompt . "\n\nNo hay documentos cargados. Indica al usuario que agregue archivos en la carpeta `knowledge/`.";
            }

            return $basePrompt . "\n\nUsa este contexto tematico como fuente principal:\n\n" . $context
                . "\n\nSi el dato no aparece en el contexto, dilo claramente.";
        }

        return $basePrompt;
    }

    /**
     * @param array<int, array<string, mixed>> $history
     * @return array<int, array{role: string, content: string}>
     */
    private function historyToModelMessages(array $history): array
    {
        $messages = [];

        foreach ($history as $item) {
            if (!isset($item['role'], $item['content'])) {
                continue;
            }

            $messages[] = [
                'role' => (string) $item['role'],
                'content' => (string) $item['content'],
            ];
        }

        return $messages;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getHistory(string $mode): array
    {
        $history = $_SESSION['conversations'][$mode] ?? [];

        return is_array($history) ? $history : [];
    }

    private function normalizeMode(string $mode): string
    {
        $allowed = ['general', 'topic', 'database'];

        if (!in_array($mode, $allowed, true)) {
            throw new RuntimeException('Modo de chat no valido.');
        }

        return $mode;
    }
}
