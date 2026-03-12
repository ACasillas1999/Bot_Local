<?php

declare(strict_types=1);

namespace BotLocal;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

final class KnowledgeBase
{
    private const SUPPORTED_EXTENSIONS = ['md', 'txt', 'csv', 'xlsx'];
    private const MAX_TABLE_ROWS = 120;

    public function __construct(
        private readonly string $knowledgePath,
        private readonly int $maxChars
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function listDocuments(): array
    {
        if (!is_dir($this->knowledgePath)) {
            return [];
        }

        $files = $this->getSupportedFiles();
        sort($files);

        return array_map(static fn (string $file): string => basename($file), $files);
    }

    public function buildContext(): string
    {
        $files = $this->getSupportedFiles();

        if ($files === []) {
            return '';
        }

        sort($files);

        $buffer = [];
        $currentLength = 0;

        foreach ($files as $file) {
            $contents = trim($this->extractContents($file));

            if ($contents === '') {
                continue;
            }

            $entry = "Documento: " . basename($file) . PHP_EOL . $contents;
            $entryLength = mb_strlen($entry);

            if ($currentLength + $entryLength > $this->maxChars) {
                $remaining = max(0, $this->maxChars - $currentLength);

                if ($remaining > 0) {
                    $buffer[] = mb_substr($entry, 0, $remaining);
                }

                break;
            }

            $buffer[] = $entry;
            $currentLength += $entryLength;
        }

        return trim(implode(PHP_EOL . PHP_EOL, $buffer));
    }

    /**
     * @return array<int, string>
     */
    private function getSupportedFiles(): array
    {
        $pattern = sprintf('*.{%s}', implode(',', self::SUPPORTED_EXTENSIONS));

        return glob($this->knowledgePath . DIRECTORY_SEPARATOR . $pattern, GLOB_BRACE) ?: [];
    }

    private function extractContents(string $file): string
    {
        $extension = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));

        return match ($extension) {
            'md', 'txt' => (string) file_get_contents($file),
            'csv' => $this->extractCsvContents($file),
            'xlsx' => $this->extractXlsxContents($file),
            default => '',
        };
    }

    private function extractCsvContents(string $file): string
    {
        $handle = fopen($file, 'rb');

        if ($handle === false) {
            throw new RuntimeException('No se pudo abrir el archivo CSV: ' . basename($file));
        }

        $firstLine = fgets($handle);
        rewind($handle);

        $delimiter = $this->detectCsvDelimiter((string) $firstLine);
        $rows = [];

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $normalized = array_map(
                static fn ($value): string => trim((string) $value),
                $row
            );

            if ($normalized === [] || count(array_filter($normalized, static fn (string $value): bool => $value !== '')) === 0) {
                continue;
            }

            $rows[] = $normalized;

            if (count($rows) >= self::MAX_TABLE_ROWS) {
                break;
            }
        }

        fclose($handle);

        return $this->renderTableText($rows, basename($file));
    }

    private function extractXlsxContents(string $file): string
    {
        $zip = new ZipArchive();

        if ($zip->open($file) !== true) {
            throw new RuntimeException('No se pudo abrir el archivo XLSX: ' . basename($file));
        }

        $sharedStrings = $this->loadSharedStrings($zip);
        $sheets = $this->loadWorkbookSheets($zip);
        $output = [];

        foreach ($sheets as $sheet) {
            $rows = $this->loadSheetRows($zip, $sheet['path'], $sharedStrings);

            if ($rows === []) {
                continue;
            }

            $output[] = "Hoja: {$sheet['name']}" . PHP_EOL
                . $this->renderTableText($rows, basename($file) . ' / ' . $sheet['name']);
        }

        $zip->close();

        return implode(PHP_EOL . PHP_EOL, $output);
    }

    private function detectCsvDelimiter(string $sample): string
    {
        $delimiters = [',', ';', "\t", '|'];
        $counts = [];

        foreach ($delimiters as $delimiter) {
            $counts[$delimiter] = substr_count($sample, $delimiter);
        }

        arsort($counts);
        $best = array_key_first($counts);

        return is_string($best) ? $best : ',';
    }

    /**
     * @param array<int, array<int, string>> $rows
     */
    private function renderTableText(array $rows, string $label): string
    {
        if ($rows === []) {
            return '';
        }

        $headers = $rows[0];
        $dataRows = array_slice($rows, 1);
        $normalizedHeaders = [];

        foreach ($headers as $index => $header) {
            $normalizedHeaders[] = $header !== '' ? $header : 'columna_' . ($index + 1);
        }

        $lines = [];
        $lines[] = "Tabla: {$label}";
        $lines[] = 'Columnas: ' . implode(' | ', $normalizedHeaders);

        if ($dataRows === []) {
            $lines[] = 'Sin filas de datos.';
            return implode(PHP_EOL, $lines);
        }

        foreach ($dataRows as $rowIndex => $row) {
            $pairs = [];

            foreach ($normalizedHeaders as $index => $header) {
                $value = trim((string) ($row[$index] ?? ''));

                if ($value === '') {
                    continue;
                }

                $pairs[] = "{$header}={$value}";
            }

            if ($pairs === []) {
                continue;
            }

            $lines[] = 'Fila ' . ($rowIndex + 1) . ': ' . implode('; ', $pairs);
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @return array<int, string>
     */
    private function loadSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        if (!is_string($xml) || $xml === '') {
            return [];
        }

        $sharedStringsXml = simplexml_load_string($xml);

        if (!$sharedStringsXml instanceof SimpleXMLElement) {
            return [];
        }

        $strings = [];

        foreach ($sharedStringsXml->si as $item) {
            $texts = $item->xpath('.//*[local-name()="t"]') ?: [];
            $buffer = '';

            foreach ($texts as $text) {
                $buffer .= (string) $text;
            }

            $strings[] = trim($buffer);
        }

        return $strings;
    }

    /**
     * @return array<int, array{name: string, path: string}>
     */
    private function loadWorkbookSheets(ZipArchive $zip): array
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if (!is_string($workbookXml) || !is_string($relsXml)) {
            return [];
        }

        $workbook = simplexml_load_string($workbookXml);
        $relationships = simplexml_load_string($relsXml);

        if (!$workbook instanceof SimpleXMLElement || !$relationships instanceof SimpleXMLElement) {
            return [];
        }

        $targetById = [];

        foreach ($relationships->Relationship as $relationship) {
            $attributes = $relationship->attributes();
            $id = (string) ($attributes['Id'] ?? '');
            $target = (string) ($attributes['Target'] ?? '');

            if ($id !== '' && $target !== '') {
                $targetById[$id] = 'xl/' . ltrim($target, '/');
            }
        }

        $sheets = [];
        $sheetNodes = $workbook->xpath('//*[local-name()="sheet"]') ?: [];

        foreach ($sheetNodes as $sheetNode) {
            $attributes = $sheetNode->attributes('r', true) ?: $sheetNode->attributes();
            $sheetId = (string) ($attributes['id'] ?? '');
            $nameAttributes = $sheetNode->attributes();
            $name = (string) ($nameAttributes['name'] ?? 'Hoja');

            if ($sheetId === '' || !isset($targetById[$sheetId])) {
                continue;
            }

            $sheets[] = [
                'name' => $name,
                'path' => $targetById[$sheetId],
            ];
        }

        return $sheets;
    }

    /**
     * @param array<int, string> $sharedStrings
     * @return array<int, array<int, string>>
     */
    private function loadSheetRows(ZipArchive $zip, string $sheetPath, array $sharedStrings): array
    {
        $xml = $zip->getFromName($sheetPath);

        if (!is_string($xml) || $xml === '') {
            return [];
        }

        $sheet = simplexml_load_string($xml);

        if (!$sheet instanceof SimpleXMLElement) {
            return [];
        }

        $rows = [];
        $rowNodes = $sheet->xpath('//*[local-name()="row"]') ?: [];

        foreach ($rowNodes as $rowNode) {
            $cells = [];
            $cellNodes = $rowNode->xpath('./*[local-name()="c"]') ?: [];

            foreach ($cellNodes as $cellNode) {
                $attributes = $cellNode->attributes();
                $ref = (string) ($attributes['r'] ?? '');
                $type = (string) ($attributes['t'] ?? '');
                $columnIndex = $this->columnReferenceToIndex($ref);
                $value = $this->extractCellValue($cellNode, $type, $sharedStrings);

                if ($value === '') {
                    continue;
                }

                $cells[$columnIndex] = $value;
            }

            if ($cells === []) {
                continue;
            }

            ksort($cells);
            $maxIndex = max(array_keys($cells));
            $normalizedRow = [];

            for ($i = 0; $i <= $maxIndex; $i++) {
                $normalizedRow[$i] = $cells[$i] ?? '';
            }

            $rows[] = $normalizedRow;

            if (count($rows) >= self::MAX_TABLE_ROWS) {
                break;
            }
        }

        return $rows;
    }

    private function columnReferenceToIndex(string $reference): int
    {
        if (preg_match('/^([A-Z]+)/i', $reference, $matches) !== 1) {
            return 0;
        }

        $letters = strtoupper($matches[1]);
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return max(0, $index - 1);
    }

    /**
     * @param array<int, string> $sharedStrings
     */
    private function extractCellValue(SimpleXMLElement $cellNode, string $type, array $sharedStrings): string
    {
        if ($type === 'inlineStr') {
            $texts = $cellNode->xpath('.//*[local-name()="t"]') ?: [];
            $value = '';

            foreach ($texts as $text) {
                $value .= (string) $text;
            }

            return trim($value);
        }

        $valueNode = $cellNode->xpath('./*[local-name()="v"]');
        $rawValue = trim((string) ($valueNode[0] ?? ''));

        if ($rawValue === '') {
            return '';
        }

        return match ($type) {
            's' => $sharedStrings[(int) $rawValue] ?? '',
            'b' => $rawValue === '1' ? 'true' : 'false',
            default => $rawValue,
        };
    }
}
