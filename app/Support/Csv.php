<?php

namespace App\Support;

/**
 * Thin CSV reader. Auto-detects comma vs semicolon vs tab delimiters by
 * sampling the first non-empty line. Strips BOM. Normalizes header keys to
 * a stable trimmed form — case preserved — so parsers can `$row['Date']`
 * without worrying about "Date " with trailing space from some banks.
 */
class Csv
{
    /**
     * @return array{headers: array<int, string>, rows: array<int, array<string, string>>}
     */
    public static function parse(string $absolutePath): array
    {
        $content = @file_get_contents($absolutePath);
        if ($content === false) {
            return ['headers' => [], 'rows' => []];
        }
        // Strip UTF-8 BOM if present.
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        $delimiter = self::detectDelimiter($content);

        $handle = fopen('php://memory', 'r+');
        if ($handle === false) {
            return ['headers' => [], 'rows' => []];
        }
        fwrite($handle, $content);
        rewind($handle);

        $headerRow = fgetcsv($handle, 0, $delimiter, '"', '\\');
        if ($headerRow === false) {
            fclose($handle);

            return ['headers' => [], 'rows' => []];
        }
        $headers = array_map(fn ($h) => trim((string) $h), $headerRow);

        $rows = [];
        while (($data = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            if (count($data) === 1 && ($data[0] === null || trim((string) $data[0]) === '')) {
                continue;
            }
            $row = [];
            foreach ($headers as $i => $col) {
                $row[$col] = isset($data[$i]) ? trim((string) $data[$i]) : '';
            }
            $rows[] = $row;
        }
        fclose($handle);

        return ['headers' => $headers, 'rows' => $rows];
    }

    private static function detectDelimiter(string $content): string
    {
        $line = (string) strtok($content, "\n");
        strtok('', '');  // reset strtok

        $counts = [
            ',' => substr_count($line, ','),
            ';' => substr_count($line, ';'),
            "\t" => substr_count($line, "\t"),
        ];
        arsort($counts);

        return (string) array_key_first($counts);
    }
}
