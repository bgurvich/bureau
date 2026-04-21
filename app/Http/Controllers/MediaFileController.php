<?php

namespace App\Http\Controllers;

use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a media file after enforcing the household ACL.
 * Per the download-gating guardrail, every media byte goes through this
 * endpoint — never the public disk — so household scope stays enforced.
 */
final class MediaFileController extends Controller
{
    public function __invoke(Request $request, Media $media): StreamedResponse|Response
    {
        // Global BelongsToHousehold scope on Media already filters by the
        // current household; if the route-model-bound record loaded, it's
        // in-scope. Explicit guard here as a belt-and-suspenders check.
        abort_unless($media->exists, 404);

        $disk = Storage::disk($media->disk ?? 'local');
        abort_unless($disk->exists($media->path), 404);

        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return $disk->response(
            $media->path,
            $media->original_name ?? basename($media->path),
            [
                'Content-Type' => $media->mime ?? 'application/octet-stream',
                'Cache-Control' => 'private, max-age=300',
            ],
            $disposition,
        );
    }

    /**
     * Stream a generated PDF thumbnail. 404s if the job hasn't produced one
     * yet — the template falls back to an extension badge in that case, so
     * the tile stays functional while the queue catches up.
     */
    public function thumb(Media $media): StreamedResponse|Response
    {
        abort_unless($media->exists, 404);
        abort_unless(! empty($media->thumb_path), 404);

        $disk = Storage::disk($media->disk ?? 'local');
        abort_unless($disk->exists($media->thumb_path), 404);

        return $disk->response(
            $media->thumb_path,
            'thumb.png',
            [
                'Content-Type' => 'image/png',
                // Thumb is content-addressable by (media id, source bytes).
                // Once generated it doesn't change — cache aggressively.
                'Cache-Control' => 'private, max-age=86400, immutable',
            ],
            'inline',
        );
    }
}
