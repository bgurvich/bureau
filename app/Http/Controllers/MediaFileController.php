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
class MediaFileController extends Controller
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
}
