<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Smalot\PdfParser\Parser as SmalotParser;

/**
 * Extracts a plaintext representation of a PDF for downstream parsing.
 *
 * Two backends — pick the faster one that's available. pdftotext (poppler)
 * is ~10x faster on big statements and preserves column alignment better,
 * but installation is per-host. smalot/pdfparser is pure PHP, works in any
 * environment, and is good enough for the bank statements Secretaire targets.
 *
 * Callers should not care which backend ran — the output contract is
 * "UTF-8 text, roughly reading-order, newlines between lines." Parsers
 * written against it handle both shapes.
 */
class Pdf
{
    public static function extractText(string $absolutePath): string
    {
        if (self::hasPdftotext()) {
            $text = self::extractViaPdftotext($absolutePath);
            if ($text !== null) {
                return $text;
            }
            // Fall through to the PHP parser if pdftotext misbehaves on a
            // specific PDF — some malformed PDFs break poppler.
        }

        return self::extractViaSmalot($absolutePath);
    }

    private static function hasPdftotext(): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        try {
            $result = Process::run(['which', 'pdftotext']);
            $cached = $result->successful() && trim($result->output()) !== '';
        } catch (\Throwable) {
            $cached = false;
        }

        return $cached;
    }

    private static function extractViaPdftotext(string $path): ?string
    {
        try {
            // -layout preserves column positioning (important for statement
            // tables). -enc UTF-8 prevents locale-specific encoding surprises.
            $result = Process::timeout(60)->run([
                'pdftotext', '-layout', '-enc', 'UTF-8', $path, '-',
            ]);
        } catch (\Throwable $e) {
            Log::warning('pdftotext failed', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }
        if (! $result->successful()) {
            return null;
        }

        return (string) $result->output();
    }

    private static function extractViaSmalot(string $path): string
    {
        try {
            $parser = new SmalotParser;
            $pdf = $parser->parseFile($path);

            return (string) $pdf->getText();
        } catch (\Throwable $e) {
            Log::warning('smalot/pdfparser failed', ['path' => $path, 'error' => $e->getMessage()]);

            return '';
        }
    }
}
