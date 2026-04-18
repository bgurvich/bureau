<?php

namespace App\Support;

/**
 * Format a byte count as a compact human-readable string (e.g. "2.3 MB").
 */
class FileSize
{
    private const UNITS = ['B', 'KB', 'MB', 'GB', 'TB'];

    public static function format(?int $bytes, int $precision = 1): string
    {
        if ($bytes === null || $bytes < 0) {
            return '—';
        }

        $unit = 0;
        $size = (float) $bytes;
        while ($size >= 1024 && $unit < count(self::UNITS) - 1) {
            $size /= 1024;
            $unit++;
        }

        return $unit === 0
            ? sprintf('%d %s', (int) $size, self::UNITS[$unit])
            : sprintf('%.'.$precision.'f %s', $size, self::UNITS[$unit]);
    }
}
