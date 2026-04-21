<?php

namespace App\Support\Mail;

use App\Models\Integration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fastmail JMAP adapter. Speaks the RFC 8620/8621 dialect — a bearer-token
 * POST of "method calls" against a single API endpoint, with result chaining
 * by back-reference so one round trip does query + fetch.
 *
 * Configuration per integration:
 *   credentials.token       — Fastmail API token (Settings → Password & Security → API tokens)
 *   credentials.session_url — JMAP session endpoint (default https://api.fastmail.com/.well-known/jmap)
 *   settings.account_id     — primary mail account id (populated at provisioning time)
 *   settings.folder_id      — JMAP mailbox id to watch (populated at provisioning)
 *   settings.last_synced_at — ISO 8601 cursor (advanced after each successful pull)
 */
class JmapProvider implements MailProvider
{
    public function pullSince(Integration $integration): iterable
    {
        $creds = (array) $integration->credentials;
        $token = (string) ($creds['token'] ?? '');
        $sessionUrl = (string) ($creds['session_url'] ?? 'https://api.fastmail.com/.well-known/jmap');

        $settings = (array) ($integration->settings ?? []);
        $accountId = (string) ($settings['account_id'] ?? '');
        $folderId = (string) ($settings['folder_id'] ?? '');
        $since = $settings['last_synced_at'] ?? null;

        if ($token === '' || $accountId === '' || $folderId === '') {
            Log::warning('JMAP integration is incomplete', ['integration_id' => $integration->id]);

            return;
        }

        $session = $this->fetchSession($sessionUrl, $token);
        if (! $session) {
            return;
        }
        $apiUrl = (string) ($session['apiUrl'] ?? '');
        $downloadTemplate = (string) ($session['downloadUrl'] ?? '');
        if ($apiUrl === '') {
            return;
        }

        $filter = ['inMailbox' => $folderId];
        if (is_string($since) && $since !== '') {
            $filter['after'] = CarbonImmutable::parse($since)->toIso8601String();
        }

        $payload = [
            'using' => ['urn:ietf:params:jmap:core', 'urn:ietf:params:jmap:mail'],
            'methodCalls' => [
                [
                    'Email/query',
                    [
                        'accountId' => $accountId,
                        'filter' => $filter,
                        'sort' => [['property' => 'receivedAt', 'isAscending' => true]],
                        'limit' => 100,
                    ],
                    'c1',
                ],
                [
                    'Email/get',
                    [
                        'accountId' => $accountId,
                        '#ids' => ['resultOf' => 'c1', 'name' => 'Email/query', 'path' => '/ids'],
                        'properties' => [
                            'id', 'blobId', 'messageId', 'receivedAt',
                            'from', 'to', 'subject',
                            'textBody', 'htmlBody', 'bodyValues',
                            'attachments', 'headers',
                        ],
                        'bodyProperties' => ['partId', 'blobId', 'size', 'name', 'type', 'disposition'],
                        'fetchTextBodyValues' => true,
                        'fetchHTMLBodyValues' => true,
                    ],
                    'c2',
                ],
            ],
        ];

        try {
            $response = Http::withToken($token)->acceptJson()->asJson()->post($apiUrl, $payload);
        } catch (\Throwable $e) {
            Log::warning('JMAP POST failed', ['error' => $e->getMessage()]);

            return;
        }
        if (! $response->successful()) {
            Log::warning('JMAP non-2xx', ['status' => $response->status(), 'body' => $response->body()]);

            return;
        }

        $responses = $response->json('methodResponses') ?? [];
        $emails = null;
        foreach ($responses as $row) {
            if (is_array($row) && ($row[0] ?? null) === 'Email/get') {
                $emails = $row[1]['list'] ?? [];
                break;
            }
        }
        if (! is_array($emails)) {
            return;
        }

        $maxReceived = null;
        foreach ($emails as $email) {
            $data = $this->mapEmail($email, $downloadTemplate, $accountId);
            if ($data === null) {
                continue;
            }

            yield $data;

            if (! $maxReceived || $data->receivedAt->gt($maxReceived)) {
                $maxReceived = $data->receivedAt;
            }
        }

        if ($maxReceived) {
            $settings['last_synced_at'] = $maxReceived->toIso8601String();
            $integration->settings = $settings;
            $integration->last_synced_at = now();
            $integration->save();
        }
    }

    public function fetchAttachment(Integration $integration, MailAttachmentData $attachment): ?string
    {
        if ($attachment->providerRef === null) {
            return null;
        }

        $creds = (array) $integration->credentials;
        $token = (string) ($creds['token'] ?? '');

        try {
            $response = Http::withToken($token)->get($attachment->providerRef);
        } catch (\Throwable $e) {
            Log::warning('JMAP blob fetch failed', ['error' => $e->getMessage()]);

            return null;
        }

        return $response->successful() ? $response->body() : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchSession(string $sessionUrl, string $token): ?array
    {
        try {
            $response = Http::withToken($token)->acceptJson()->get($sessionUrl);
        } catch (\Throwable $e) {
            Log::warning('JMAP session fetch failed', ['error' => $e->getMessage()]);

            return null;
        }
        if (! $response->successful()) {
            return null;
        }

        return $response->json();
    }

    /**
     * @param  array<string, mixed>  $email
     */
    private function mapEmail(array $email, string $downloadTemplate, string $accountId): ?MailMessageData
    {
        $rfcMessageIdList = $email['messageId'] ?? [];
        $rfcMessageId = is_array($rfcMessageIdList) && $rfcMessageIdList !== []
            ? (string) $rfcMessageIdList[0]
            : '';
        $providerId = (string) ($email['id'] ?? '');
        if ($rfcMessageId === '' && $providerId === '') {
            return null;
        }
        if ($rfcMessageId === '') {
            $rfcMessageId = 'jmap-'.$providerId;
        }

        $from = $email['from'][0] ?? null;
        $fromAddress = is_array($from) && is_string($from['email'] ?? null) ? $from['email'] : null;
        $fromName = is_array($from) && is_string($from['name'] ?? null) ? $from['name'] : null;

        $toAddresses = [];
        foreach ((array) ($email['to'] ?? []) as $t) {
            if (is_array($t) && is_string($t['email'] ?? null)) {
                $toAddresses[] = $t['email'];
            }
        }

        $textBody = $this->pickBody($email, 'textBody');
        $htmlBody = $this->pickBody($email, 'htmlBody');

        $attachments = [];
        foreach ((array) ($email['attachments'] ?? []) as $a) {
            if (! is_array($a)) {
                continue;
            }
            $blobId = (string) ($a['blobId'] ?? '');
            if ($blobId === '') {
                continue;
            }
            $name = is_string($a['name'] ?? null) ? $a['name'] : 'attachment';
            $attachments[] = new MailAttachmentData(
                filename: $name,
                mime: is_string($a['type'] ?? null) ? $a['type'] : null,
                size: is_numeric($a['size'] ?? null) ? (int) $a['size'] : null,
                providerRef: $this->resolveDownloadUrl($downloadTemplate, $accountId, $blobId, $name, $a['type'] ?? null),
            );
        }

        $headers = [];
        foreach ((array) ($email['headers'] ?? []) as $h) {
            if (is_array($h) && is_string($h['name'] ?? null) && is_string($h['value'] ?? null)) {
                $headers[$h['name']] = $h['value'];
            }
        }

        return new MailMessageData(
            messageId: $rfcMessageId,
            providerMessageId: $providerId,
            receivedAt: CarbonImmutable::parse((string) ($email['receivedAt'] ?? now()->toIso8601String())),
            fromAddress: $fromAddress,
            fromName: $fromName,
            toAddresses: $toAddresses,
            subject: is_string($email['subject'] ?? null) ? $email['subject'] : null,
            textBody: $textBody,
            htmlBody: $htmlBody,
            attachments: $attachments,
            headers: $headers,
        );
    }

    /**
     * @param  array<string, mixed>  $email
     */
    private function pickBody(array $email, string $which): ?string
    {
        $parts = $email[$which] ?? [];
        if (! is_array($parts) || $parts === []) {
            return null;
        }
        $first = $parts[0] ?? null;
        if (! is_array($first)) {
            return null;
        }
        $partId = (string) ($first['partId'] ?? '');
        if ($partId === '') {
            return null;
        }
        $values = $email['bodyValues'] ?? [];

        return is_array($values) && is_array($values[$partId] ?? null) && is_string($values[$partId]['value'] ?? null)
            ? $values[$partId]['value']
            : null;
    }

    private function resolveDownloadUrl(string $template, string $accountId, string $blobId, string $name, mixed $type): string
    {
        $replacements = [
            '{accountId}' => $accountId,
            '{blobId}' => $blobId,
            '{name}' => rawurlencode($name),
            '{type}' => is_string($type) ? rawurlencode($type) : 'application/octet-stream',
        ];

        return strtr($template, $replacements);
    }
}
