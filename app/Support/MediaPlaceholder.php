<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Media;
use Illuminate\Support\Facades\Storage;

/**
 * Writes placeholder bytes to storage for Media rows whose underlying
 * file is missing. Used by the demo seeder so the library picker +
 * inspector thumbnails don't 404 on seeded rows, and by the OCR
 * pipeline's tests that need a real file on disk without wiring a
 * fake upload.
 */
final class MediaPlaceholder
{
    /** Minimal 1×1 transparent PNG, ~67 bytes. */
    public const PNG_BYTES =
        "\x89PNG\r\n\x1a\n\x00\x00\x00\x0dIHDR\x00\x00\x00\x01\x00\x00\x00\x01"
        ."\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\x0dIDATx\x9cc\x00"
        ."\x01\x00\x00\x05\x00\x01\x0d\x0a\x2d\xb4\x00\x00\x00\x00IEND\xaeB`\x82";

    /** Minimal single-page PDF, ~230 bytes. */
    public const PDF_BYTES =
        "%PDF-1.1\n%\xc2\xa5\xc2\xb1\xc3\xab\n\n1 0 obj\n  << /Type /Catalog\n     /Pages 2 0 R\n  >>\nendobj\n\n"
        ."2 0 obj\n  << /Type /Pages\n     /Kids [3 0 R]\n     /Count 1\n     /MediaBox [0 0 72 72]\n  >>\nendobj\n\n"
        ."3 0 obj\n  <<  /Type /Page\n      /Parent 2 0 R\n  >>\nendobj\n\n"
        ."xref\n0 4\n0000000000 65535 f \n0000000016 00000 n \n0000000062 00000 n \n0000000133 00000 n \n"
        ."trailer\n  << /Root 1 0 R\n     /Size 4\n  >>\nstartxref\n178\n%%EOF\n";

    /**
     * Write placeholder bytes at the media's storage path if the file
     * doesn't exist yet. No-op if the file is already there; returns
     * true only when we actually wrote something.
     */
    public static function ensureStub(Media $media): bool
    {
        $disk = Storage::disk((string) ($media->disk ?: 'local'));
        $path = (string) $media->path;
        if ($path === '' || $disk->exists($path)) {
            return false;
        }

        $bytes = str_starts_with((string) $media->mime, 'image/')
            ? self::PNG_BYTES
            : self::PDF_BYTES;
        $disk->put($path, $bytes);

        return true;
    }

    /**
     * Iterate every Media row and write a placeholder for any whose
     * file is missing. Used by seeders + repair commands.
     */
    public static function repairAll(): int
    {
        $count = 0;
        Media::query()->chunk(200, function ($batch) use (&$count) {
            foreach ($batch as $media) {
                if (self::ensureStub($media)) {
                    $count++;
                }
            }
        });

        return $count;
    }
}
