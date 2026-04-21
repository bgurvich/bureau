<?php

namespace App\Support\Mail;

use Carbon\CarbonImmutable;

/**
 * Provider-agnostic representation of one ingested email. Adapters (JMAP /
 * Gmail / Postmark / IMAP) convert their native shape into this DTO; the
 * MailIngester only deals with DTOs. Keeps adapter churn out of the
 * persistence layer and makes testing trivial.
 */
final class MailMessageData
{
    /**
     * @param  array<int, string>  $toAddresses
     * @param  array<int, MailAttachmentData>  $attachments
     * @param  array<string, mixed>  $headers
     */
    public function __construct(
        public readonly string $messageId,
        public readonly string $providerMessageId,
        public readonly CarbonImmutable $receivedAt,
        public readonly ?string $fromAddress,
        public readonly ?string $fromName,
        public readonly array $toAddresses,
        public readonly ?string $subject,
        public readonly ?string $textBody,
        public readonly ?string $htmlBody,
        public readonly array $attachments = [],
        public readonly array $headers = [],
    ) {}
}
