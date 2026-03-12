<?php

declare(strict_types=1);

namespace BotLocal\Database;

final class SchemaSelector
{
    /**
     * @param array{tables: array<string, array<string, mixed>>, stats: array<string, int>} $schema
     * @param array<int, array{role: string, content: string}> $history
     * @return array{tables: array<string, array<string, mixed>>, stats: array<string, int>}
     */
    public function selectRelevant(array $schema, string $question, array $history, int $maxTables = 8, bool $includeRelated = true): array
    {
        $tables = $schema['tables'] ?? [];

        if (count($tables) <= $maxTables) {
            return $schema;
        }

        $text = $question;

        foreach (array_slice($history, -4) as $item) {
            $text .= ' ' . $item['content'];
        }

        $tokens = $this->tokenize($text);
        $scores = [];

        foreach ($tables as $tableName => $table) {
            $score = $this->scoreTable((string) $tableName, $table, $tokens);

            if ($score > 0) {
                $scores[$tableName] = $score;
            }
        }

        if ($scores === []) {
            $selected = array_slice(array_keys($tables), 0, $maxTables);
        } else {
            arsort($scores);
            $selected = array_slice(array_keys($scores), 0, $maxTables);
        }

        if ($includeRelated) {
            $selected = $this->expandByRelationships($tables, $selected, $maxTables);
        }

        $selectedTables = [];

        foreach ($selected as $tableName) {
            if (isset($tables[$tableName])) {
                $selectedTables[$tableName] = $tables[$tableName];
            }
        }

        return [
            'tables' => $selectedTables,
            'stats' => [
                'tableCount' => count($selectedTables),
                'columnCount' => array_sum(array_map(static fn (array $table): int => count($table['columns'] ?? []), $selectedTables)),
                'totalTableCount' => (int) (($schema['stats']['tableCount'] ?? count($tables))),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $table
     * @param array<int, string> $tokens
     */
    private function scoreTable(string $tableName, array $table, array $tokens): int
    {
        $score = 0;
        $tableTokens = $this->tokenize($tableName);

        foreach ($tokens as $token) {
            if (in_array($token, $tableTokens, true)) {
                $score += 10;
            }

            foreach (($table['columns'] ?? []) as $column) {
                $columnTokens = $this->tokenize((string) ($column['name'] ?? ''));

                if (in_array($token, $columnTokens, true)) {
                    $score += 4;
                }
            }
        }

        return $score;
    }

    /**
     * @param array<string, array<string, mixed>> $tables
     * @param array<int, string> $selected
     * @return array<int, string>
     */
    private function expandByRelationships(array $tables, array $selected, int $maxTables): array
    {
        $result = $selected;

        foreach ($selected as $tableName) {
            foreach (($tables[$tableName]['foreign_keys'] ?? []) as $fk) {
                $related = (string) ($fk['references_table'] ?? '');

                if ($related === '' || in_array($related, $result, true)) {
                    continue;
                }

                $result[] = $related;

                if (count($result) >= $maxTables) {
                    return $result;
                }
            }
        }

        return $result;
    }

    /**
     * @return array<int, string>
     */
    private function tokenize(string $text): array
    {
        $text = strtolower($text);
        $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        $normalized = is_string($normalized) ? $normalized : $text;
        $normalized = preg_replace('/[^a-z0-9_]+/', ' ', $normalized) ?? $normalized;
        $parts = preg_split('/\s+/', trim($normalized)) ?: [];
        $parts = array_values(array_filter($parts, static fn (string $part): bool => strlen($part) >= 3));

        return array_values(array_unique($parts));
    }
}
