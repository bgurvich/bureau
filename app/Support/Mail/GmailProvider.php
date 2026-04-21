<?php

namespace App\Support\Mail;

use App\Models\Integration;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Gmail API adapter. Uses the REST v1 endpoints (not IMAP, not Gmail+XOAUTH2).
 *
 * Configuration per integration:
 *   credentials.refresh_token      — long-lived OAuth refresh token (from the consent flow)
 *   credentials.access_token        — cached short-lived access token (rotated as needed)
 *   credentials.access_token_expires_at — unix timestamp
 *   settings.label_ids              — string[] of Gmail label IDs to watch (e.g. ["Label_123"])
 *   settings.history_id             — incremental cursor from users.history.list
 *
 * Scopes expected on the refresh token: gmail.readonly (plus gmail.modify if
 * we want to mark-as-processed by removing a label — currently no-op).
 */
class GmailProvider implements MailProvider
{
    private const API = 'https://gmail.googleapis.com/gmail/v1';

    public function pullSince(Integration $integration): iterable
    {
        $client = $this->authedClient($integration);
        if (! $client) {
            return;
        }

        $settings = (array) ($integration->settings ?? []);
        $labelIds = array_values(array_filter((array) ($settings['label_ids'] ?? []), 'is_string'));
        $historyId = isset($settings['history_id']) ? (string) $settings['history_id'] : '';

        $messageIds = $historyId === ''
            ? $this->backfillMessageIds($client, $labelIds)
            : $this->incrementalMessageIds($client, $labelIds, $historyId);

        $latestHistoryId = $historyId;

        foreach ($messageIds as $id) {
            $data = $this->fetchMessage($client, $id);
            if ($data !== null) {
                yield $data;
            }
        }

        // Capture the newest historyId so the next run picks up strictly after.
        $profile = $client->get(self::API.'/users/me/profile');
        if ($profile->successful()) {
            $latestHistoryId = (string) ($profile->json('historyId') ?? $latestHistoryId);
        }

        if ($latestHistoryId !== '') {
            $settings['history_id'] = $latestHistoryId;
            $integration->settings = $settings;
            $integration->last_synced_at = now();
            $integration->save();
        }
    }

    public function fetchAttachment(Integration $integration, MailAttachmentData $attachment): ?string
    {
        if ($attachment->providerRef === null || ! str_contains($attachment->providerRef, '|')) {
            return null;
        }
        [$messageId, $attachmentId] = explode('|', $attachment->providerRef, 2);

        $client = $this->authedClient($integration);
        if (! $client) {
            return null;
        }

        $response = $client->get(self::API.'/users/me/messages/'.$messageId.'/attachments/'.$attachmentId);
        if (! $response->successful()) {
            return null;
        }
        $encoded = $response->json('data');
        if (! is_string($encoded)) {
            return null;
        }

        return base64_decode(strtr($encoded, '-_', '+/'), strict: true) ?: null;
    }

    /**
     * @param  array<int, string>  $labelIds
     * @return iterable<string>
     */
    private function backfillMessageIds(PendingRequest $client, array $labelIds): iterable
    {
        $params = ['maxResults' => 50];
        if ($labelIds !== []) {
            $params['labelIds'] = $labelIds;
        }

        $response = $client->get(self::API.'/users/me/messages', $params);
        if (! $response->successful()) {
            Log::warning('Gmail messages.list failed', ['status' => $response->status(), 'body' => $response->body()]);

            return;
        }

        foreach ((array) $response->json('messages', []) as $row) {
            if (is_array($row) && is_string($row['id'] ?? null)) {
                yield $row['id'];
            }
        }
    }

    /**
     * @param  array<int, string>  $labelIds
     * @return iterable<string>
     */
    private function incrementalMessageIds(PendingRequest $client, array $labelIds, string $historyId): iterable
    {
        $params = [
            'startHistoryId' => $historyId,
            'historyTypes' => 'messageAdded',
            'maxResults' => 100,
        ];
        if ($labelIds !== []) {
            $params['labelId'] = $labelIds[0];
        }

        $response = $client->get(self::API.'/users/me/history', $params);
        if (! $response->successful()) {
            Log::warning('Gmail history.list failed', ['status' => $response->status(), 'body' => $response->body()]);

            return;
        }

        foreach ((array) $response->json('history', []) as $entry) {
            foreach ((array) ($entry['messagesAdded'] ?? []) as $added) {
                $msg = $added['message'] ?? null;
                if (is_array($msg) && is_string($msg['id'] ?? null)) {
                    yield $msg['id'];
                }
            }
        }
    }

    private function fetchMessage(PendingRequest $client, string $messageId): ?MailMessageData
    {
        $response = $client->get(self::API.'/users/me/messages/'.$messageId, ['format' => 'full']);
        if (! $response->successful()) {
            Log::warning('Gmail messages.get failed', ['id' => $messageId, 'status' => $response->status()]);

            return null;
        }
        $json = $response->json();
        if (! is_array($json)) {
            return null;
        }

        $headers = [];
        foreach ((array) ($json['payload']['headers'] ?? []) as $h) {
            if (is_array($h) && is_string($h['name'] ?? null) && is_string($h['value'] ?? null)) {
                $headers[$h['name']] = $h['value'];
            }
        }

        $rfcMessageId = (string) ($this->findHeaderCI($headers, 'Message-ID') ?? '');
        if ($rfcMessageId === '') {
            $rfcMessageId = 'gmail-'.$messageId;
        }
        $rfcMessageId = trim($rfcMessageId, '<>');

        $subject = $this->findHeaderCI($headers, 'Subject');
        $fromRaw = $this->findHeaderCI($headers, 'From');
        [$fromAddress, $fromName] = $this->splitAddress($fromRaw);

        $toRaw = (string) ($this->findHeaderCI($headers, 'To') ?? '');
        $toAddresses = array_values(array_filter(array_map(
            fn (string $s) => $this->splitAddress($s)[0] ?? null,
            preg_split('/,\s*/', $toRaw) ?: []
        )));

        $receivedAt = isset($json['internalDate']) && is_numeric($json['internalDate'])
            ? CarbonImmutable::createFromTimestampMs((int) $json['internalDate'])
            : CarbonImmutable::now();

        [$textBody, $htmlBody, $attachments] = $this->walkParts($json['payload'] ?? [], $messageId);

        return new MailMessageData(
            messageId: $rfcMessageId,
            providerMessageId: $messageId,
            receivedAt: $receivedAt,
            fromAddress: $fromAddress,
            fromName: $fromName,
            toAddresses: $toAddresses,
            subject: is_string($subject) ? $subject : null,
            textBody: $textBody,
            htmlBody: $htmlBody,
            attachments: $attachments,
            headers: $headers,
        );
    }

    /**
     * @param  array<string, mixed>  $part
     * @param  array<int, MailAttachmentData>  $attachments
     * @return array{0: ?string, 1: ?string, 2: array<int, MailAttachmentData>}
     */
    private function walkParts(array $part, string $messageId, ?string $text = null, ?string $html = null, array $attachments = []): array
    {
        $mime = (string) ($part['mimeType'] ?? '');
        $filename = (string) ($part['filename'] ?? '');

        if ($filename !== '' && isset($part['body']['attachmentId']) && is_string($part['body']['attachmentId'])) {
            $attachments[] = new MailAttachmentData(
                filename: $filename,
                mime: $mime !== '' ? $mime : null,
                size: is_numeric($part['body']['size'] ?? null) ? (int) $part['body']['size'] : null,
                providerRef: $messageId.'|'.$part['body']['attachmentId'],
            );
        }

        if ($mime === 'text/plain' && ! isset($text)) {
            $text = $this->decodeBodyData($part['body']['data'] ?? null);
        } elseif ($mime === 'text/html' && ! isset($html)) {
            $html = $this->decodeBodyData($part['body']['data'] ?? null);
        }

        foreach ((array) ($part['parts'] ?? []) as $child) {
            if (is_array($child)) {
                [$text, $html, $attachments] = $this->walkParts($child, $messageId, $text, $html, $attachments);
            }
        }

        return [$text, $html, $attachments];
    }

    private function decodeBodyData(mixed $data): ?string
    {
        if (! is_string($data) || $data === '') {
            return null;
        }
        $decoded = base64_decode(strtr($data, '-_', '+/'), strict: true);

        return $decoded === false ? null : $decoded;
    }

    private function authedClient(Integration $integration): ?PendingRequest
    {
        $creds = (array) $integration->credentials;
        $refresh = (string) ($creds['refresh_token'] ?? '');
        if ($refresh === '') {
            Log::warning('Gmail integration missing refresh_token', ['integration_id' => $integration->id]);

            return null;
        }

        $expiresAt = isset($creds['access_token_expires_at']) ? (int) $creds['access_token_expires_at'] : 0;
        $access = (string) ($creds['access_token'] ?? '');
        if ($access === '' || $expiresAt <= time() + 30) {
            $tokens = $this->refreshAccessToken($refresh);
            if ($tokens === null) {
                return null;
            }
            $access = $tokens['access_token'];
            $creds['access_token'] = $access;
            $creds['access_token_expires_at'] = time() + $tokens['expires_in'];
            $integration->credentials = $creds;
            $integration->save();
        }

        return Http::withToken($access)->acceptJson();
    }

    /**
     * @return array{access_token: string, expires_in: int}|null
     */
    private function refreshAccessToken(string $refreshToken): ?array
    {
        $clientId = (string) config('services.google.client_id', '');
        $clientSecret = (string) config('services.google.client_secret', '');
        if ($clientId === '' || $clientSecret === '') {
            Log::warning('Gmail OAuth client_id/secret not configured');

            return null;
        }

        try {
            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ]);
        } catch (\Throwable $e) {
            Log::warning('Gmail token refresh failed', ['error' => $e->getMessage()]);

            return null;
        }
        if (! $response->successful()) {
            Log::warning('Gmail token refresh non-2xx', ['status' => $response->status(), 'body' => $response->body()]);

            return null;
        }

        $token = $response->json('access_token');
        $expires = $response->json('expires_in');
        if (! is_string($token) || ! is_numeric($expires)) {
            return null;
        }

        return ['access_token' => $token, 'expires_in' => (int) $expires];
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function findHeaderCI(array $headers, string $name): ?string
    {
        foreach ($headers as $k => $v) {
            if (strcasecmp($k, $name) === 0) {
                return $v;
            }
        }

        return null;
    }

    /**
     * Parses "Display Name <addr@host>" into [address, name].
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function splitAddress(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [null, null];
        }
        if (preg_match('/^\s*"?([^"<]+?)"?\s*<([^>]+)>\s*$/', $raw, $m)) {
            return [trim($m[2]), trim($m[1])];
        }

        return [trim($raw), null];
    }
}
