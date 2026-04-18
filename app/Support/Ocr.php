<?php

namespace App\Support;

use Illuminate\Support\Facades\Process;

/**
 * Thin wrapper around the `tesseract` binary. Returns extracted text or null
 * on failure. Kept as a class-with-static-method so tests can swap it out
 * via Process::fake() without hitting the real binary.
 */
class Ocr
{
    /**
     * Extract printed text from an image at an absolute filesystem path.
     * Returns null when tesseract exits non-zero, isn't installed, or emits
     * nothing usable. Caller decides how to report "failed".
     */
    public static function extract(string $absolutePath, string $language = 'eng'): ?string
    {
        try {
            $result = Process::timeout(60)->run(['tesseract', $absolutePath, '-', '-l', $language]);
        } catch (\Throwable) {
            return null;
        }

        if (! $result->successful()) {
            return null;
        }

        $text = trim($result->output());

        return $text !== '' ? $text : null;
    }
}
