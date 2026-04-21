<?php

namespace App\Http\Controllers;

use App\Models\MailIngestInbox;
use App\Support\Mail\MailAttachmentData;
use App\Support\Mail\MailIngester;
use App\Support\Mail\MailMessageData;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives Postmark Inbound webhook posts. The webhook URL should be
 * configured in Postmark with HTTP basic auth baked in (either
 * https://user:pass@host/webhooks/postmark/inbound or a custom Authorization
 * header) — the middleware stack gates this route behind that credential.
 *
 * Routing to a household is via the To address: the user creates a
 * MailIngestInbox row with the forwarding address Postmark is targeting,
 * and its household owns the incoming message. Unknown addresses 404 so
 * the user sees failed deliveries in Postmark's UI instead of silent drops.
 */
final class PostmarkInboundController extends Controller
{
    public function __invoke(Request $request, MailIngester $ingester): JsonResponse
    {
        $payload = $request->all();

        $toAddresses = $this->extractToAddresses($payload);
        $inbox = $this->resolveInbox($toAddresses);

        if (! $inbox) {
            return response()->json(['ok' => false, 'reason' => 'unknown inbox'], 404);
        }

        try {
            $data = $this->parsePayload($payload);
        } catch (\Throwable $e) {
            Log::warning('Postmark inbound parse failed', [
                'error' => $e->getMessage(),
                'from' => $payload['From'] ?? null,
            ]);

            return response()->json(['ok' => false, 'reason' => 'parse error'], 422);
        }

        $household = $inbox->household;
        if (! $household) {
            return response()->json(['ok' => false, 'reason' => 'orphaned inbox'], 500);
        }

        $result = $ingester->ingest(
            household: $household,
            data: $data,
            inbox: $inbox,
        );

        return response()->json([
            'ok' => true,
            'created' => $result['created'],
            'message_id' => $result['message']->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    private function extractToAddresses(array $payload): array
    {
        $addresses = [];

        if (is_string($payload['To'] ?? null)) {
            foreach (preg_split('/[,;]\s*/', (string) $payload['To']) ?: [] as $part) {
                $trimmed = trim($part);
                if ($trimmed !== '') {
                    $addresses[] = strtolower($trimmed);
                }
            }
        }

        if (is_array($payload['ToFull'] ?? null)) {
            foreach ($payload['ToFull'] as $row) {
                if (is_array($row) && is_string($row['Email'] ?? null)) {
                    $addresses[] = strtolower($row['Email']);
                }
            }
        }

        if (is_string($payload['OriginalRecipient'] ?? null)) {
            $addresses[] = strtolower($payload['OriginalRecipient']);
        }

        return array_values(array_unique($addresses));
    }

    /**
     * @param  array<int, string>  $addresses
     */
    private function resolveInbox(array $addresses): ?MailIngestInbox
    {
        if ($addresses === []) {
            return null;
        }

        return MailIngestInbox::query()
            ->withoutGlobalScopes()
            ->whereIn('local_address', $addresses)
            ->where('active', true)
            ->with('household')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function parsePayload(array $payload): MailMessageData
    {
        $headers = [];
        $rfcMessageId = null;
        if (is_array($payload['Headers'] ?? null)) {
            foreach ($payload['Headers'] as $h) {
                if (! is_array($h)) {
                    continue;
                }
                $name = is_string($h['Name'] ?? null) ? $h['Name'] : null;
                $value = is_string($h['Value'] ?? null) ? $h['Value'] : null;
                if ($name !== null && $value !== null) {
                    $headers[$name] = $value;
                    if (strcasecmp($name, 'Message-ID') === 0) {
                        $rfcMessageId = $value;
                    }
                }
            }
        }

        $providerId = is_string($payload['MessageID'] ?? null) ? $payload['MessageID'] : '';
        $fallbackId = $rfcMessageId ?: ('postmark-'.$providerId);

        $receivedAt = isset($payload['Date']) && is_string($payload['Date'])
            ? CarbonImmutable::parse($payload['Date'])
            : CarbonImmutable::now();

        $to = [];
        if (is_array($payload['ToFull'] ?? null)) {
            foreach ($payload['ToFull'] as $row) {
                if (is_array($row) && is_string($row['Email'] ?? null)) {
                    $to[] = $row['Email'];
                }
            }
        }

        $attachments = [];
        if (is_array($payload['Attachments'] ?? null)) {
            foreach ($payload['Attachments'] as $att) {
                if (! is_array($att)) {
                    continue;
                }
                $bytes = is_string($att['Content'] ?? null) ? base64_decode($att['Content'], strict: true) : null;
                $attachments[] = new MailAttachmentData(
                    filename: is_string($att['Name'] ?? null) ? $att['Name'] : 'attachment',
                    mime: is_string($att['ContentType'] ?? null) ? $att['ContentType'] : null,
                    size: is_numeric($att['ContentLength'] ?? null) ? (int) $att['ContentLength'] : null,
                    content: $bytes === false ? null : $bytes,
                );
            }
        }

        return new MailMessageData(
            messageId: (string) ($rfcMessageId ?: $fallbackId),
            providerMessageId: (string) $providerId,
            receivedAt: $receivedAt,
            fromAddress: is_string($payload['From'] ?? null) ? $payload['From'] : null,
            fromName: is_string($payload['FromName'] ?? null) ? $payload['FromName'] : null,
            toAddresses: $to,
            subject: is_string($payload['Subject'] ?? null) ? $payload['Subject'] : null,
            textBody: is_string($payload['TextBody'] ?? null) ? $payload['TextBody'] : null,
            htmlBody: is_string($payload['HtmlBody'] ?? null) ? $payload['HtmlBody'] : null,
            attachments: $attachments,
            headers: $headers,
        );
    }
}
