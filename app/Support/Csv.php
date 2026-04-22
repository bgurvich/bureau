<?php

namespace App\Support;

/**
 * Thin CSV reader. Auto-detects comma vs semicolon vs tab delimiters by
 * sampling the first non-empty line. Strips BOM. Normalizes header keys to
 * a stable trimmed form — case preserved — so parsers can `$row['Date']`
 * without worrying about "Date " with trailing space from some banks.
 *
 * Preamble-skip: some exports (e.g. Costco Visa's "Annual Account Summary")
 * prefix the real header with a metadata line like
 * `"Time period of report:","Jan. 01, 2026 …"` plus a blank row. We detect
 * that by finding the modal column count across all non-empty parsed rows
 * and taking the first row matching it as the header — anything preceding
 * is discarded.
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

        /** @var array<int, array<int, string|null>> $raw */
        $raw = [];
        while (($data = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            if (count($data) === 1 && ($data[0] === null || trim((string) $data[0]) === '')) {
                continue;
            }
            $raw[] = $data;
        }
        fclose($handle);

        if ($raw === []) {
            return ['headers' => [], 'rows' => []];
        }

        $headerIndex = self::locateHeaderRow($raw);
        $headerRow = $raw[$headerIndex];
        $headers = array_map(fn ($h) => trim((string) $h), $headerRow);

        $rows = [];
        for ($i = $headerIndex + 1; $i < count($raw); $i++) {
            $data = $raw[$i];
            $row = [];
            foreach ($headers as $j => $col) {
                $row[$col] = isset($data[$j]) ? trim((string) $data[$j]) : '';
            }
            $rows[] = $row;
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * Find the header row index. The modal column count across the file
     * marks "real" rows; the first row matching it is the header and
     * anything before it is treated as preamble.
     *
     * @param  array<int, array<int, string|null>>  $raw
     */
    private static function locateHeaderRow(array $raw): int
    {
        $counts = [];
        foreach ($raw as $row) {
            $n = count($row);
            $counts[$n] = ($counts[$n] ?? 0) + 1;
        }
        arsort($counts);
        $modal = (int) array_key_first($counts);

        foreach ($raw as $i => $row) {
            if (count($row) === $modal) {
                return $i;
            }
        }

        return 0;
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
