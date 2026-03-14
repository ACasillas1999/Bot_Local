<?php

declare(strict_types=1);

namespace BotLocal;

final class AppServices
{
    public static function makeOllamaClient(): OllamaClient
    {
        return new OllamaClient(
            (string) app_config('app.ollama_url'),
            (string) app_config('app.model'),
            (float) app_config('app.temperature'),
            (int) app_config('app.request_timeout')
        );
    }

    public static function makeKnowledgeBase(): KnowledgeBase
    {
        return new KnowledgeBase(
            (string) app_config('knowledge.path'),
            (int) app_config('knowledge.max_chars'),
            (int) app_config('knowledge.chunk_chars', 1400),
            (int) app_config('knowledge.max_chunks', 6)
        );
    }

    public static function makeDatabaseAssistant(?OllamaClient $ollama = null): DatabaseAssistant
    {
        return new DatabaseAssistant((array) app_config('database'), $ollama ?? self::makeOllamaClient());
    }

    public static function makeChatCoordinator(
        ?OllamaClient $ollama = null,
        ?KnowledgeBase $knowledgeBase = null,
        ?DatabaseAssistant $databaseAssistant = null
    ): ChatCoordinator
    {
        $ollama ??= self::makeOllamaClient();
        $knowledgeBase ??= self::makeKnowledgeBase();
        $databaseAssistant ??= self::makeDatabaseAssistant($ollama);

        return new ChatCoordinator(
            $ollama,
            $knowledgeBase,
            $databaseAssistant,
            (int) app_config('app.history_limit')
        );
    }
}
