<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Media;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Render page 1 of a PDF to a PNG thumbnail for the media grid. Uses poppler's
 * `pdftoppm` (install-packages.sh installs `poppler-utils`). ImageMagick would
 * also work but its default policy blocks the PDF coder — see scripts/deploy/
 * install-packages.sh for the security rationale. pdftoppm is a narrower
 * surface with no scripting layer, so it stays enabled.
 *
 * Thumbnails land alongside the source file under `thumbs/<media-id>.png` on
 * the same disk, so disk migrations (local → S3) carry them automatically.
 * Idempotent: re-runs overwrite the existing thumb, which is fine since
 * source bytes haven't changed.
 *
 * For non-PDFs this job is a no-op — images render their own thumbs via the
 * existing /media/{id}/file endpoint.
 */
final class GenerateMediaThumbnail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $mediaId) {}

    public function handle(): void
    {
        $media = Media::withoutGlobalScopes()->find($this->mediaId);
        if (! $media) {
            return;
        }
        if ($media->mime !== 'application/pdf') {
            $media->forceFill(['thumb_status' => 'skip'])->saveQuietly();

            return;
        }

        $disk = Storage::disk($media->disk ?? 'local');
        if (! $disk->exists($media->path)) {
            Log::warning('GenerateMediaThumbnail: source file missing', [
                'media_id' => $this->mediaId, 'path' => $media->path,
            ]);
            $media->forceFill(['thumb_status' => 'failed'])->saveQuietly();

            return;
        }

        $sourcePath = $disk->path($media->path);
        $thumbRel = 'thumbs/'.$media->id.'.png';
        $thumbAbs = $disk->path($thumbRel);

        if (! is_dir(dirname($thumbAbs))) {
            @mkdir(dirname($thumbAbs), 0700, true);
        }

        // pdftoppm writes "<prefix>-1.png" for page 1. Use a tmp prefix in the
        // same dir then rename so partial writes don't leave a corrupted thumb
        // reachable via the route.
        $prefix = dirname($thumbAbs).'/.pending-'.$media->id;
        $generated = $prefix.'-1.png';

        $process = new Process([
            'pdftoppm',
            '-png',
            '-f', '1', '-l', '1',   // first page only
            '-scale-to', '400',      // max edge 400px — plenty for grid + modal preview
            '-singlefile',           // omit "-1" suffix; writes $prefix.png
            $sourcePath,
            $prefix,
        ]);
        $process->setTimeout(30);
        $process->run();

        $singlefile = $prefix.'.png';
        if (! $process->isSuccessful() || ! file_exists($singlefile)) {
            @unlink($singlefile);
            @unlink($generated);
            $err = $process->getErrorOutput() ?: $process->getOutput();
            Log::warning('pdftoppm failed', [
                'media_id' => $this->mediaId,
                'error' => $err,
            ]);
            // Hint operator about the most common cause so they don't stare
            // at a "rendering…" tile: the binary isn't installed. Matches
            // the literal stderr text sh emits on ENOENT for a PATH lookup.
            if (str_contains((string) $err, 'pdftoppm: not found')) {
                Log::warning('pdftoppm not installed — run: apt-get install poppler-utils');
            }
            $media->forceFill(['thumb_status' => 'failed'])->saveQuietly();

            return;
        }

        @rename($singlefile, $thumbAbs);
        $media->forceFill([
            'thumb_path' => $thumbRel,
            'thumb_status' => 'done',
        ])->saveQuietly();
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('GenerateMediaThumbnail failed terminally', [
            'media_id' => $this->mediaId, 'error' => $exception->getMessage(),
        ]);
        Media::withoutGlobalScopes()
            ->where('id', $this->mediaId)
            ->update(['thumb_status' => 'failed']);
    }
}
