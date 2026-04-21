<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin client for an LM Studio HTTP server. LM Studio speaks OpenAI's
 * chat-completions shape, so this class intentionally stays narrow: one
 * method, same request body an OpenAI SDK would send, returned assistant
 * message content as a string. Tests swap it out with Http::fake().
 */
class LmStudio
{
    /**
     * Send a chat request. Returns the assistant message content on success,
     * or null on any failure (disabled, transport error, non-2xx, malformed
     * body). Callers decide how to surface "failed" — we do not throw so
     * pipeline jobs can mark rows failed and move on.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options  merged into the request body (temperature, response_format, etc.)
     */
    public function chat(array $messages, array $options = []): ?string
    {
        if (! config('services.lm_studio.enabled')) {
            return null;
        }

        $baseUrl = (string) config('services.lm_studio.base_url');
        $model = (string) config('services.lm_studio.model');
        $timeout = (int) config('services.lm_studio.timeout', 120);

        $payload = array_merge([
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.1,
            'stream' => false,
        ], $options);

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->asJson()
                ->post($baseUrl.'/chat/completions', $payload);
        } catch (\Throwable $e) {
            Log::warning('LM Studio request failed', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('LM Studio returned non-2xx', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $content = $response->json('choices.0.message.content');
        if (! is_string($content) || trim($content) === '') {
            return null;
        }

        return $content;
    }
}
