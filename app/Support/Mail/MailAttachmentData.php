<?php

namespace App\Support\Mail;

/**
 * Provider-agnostic representation of a single email attachment. The adapter
 * either delivers the bytes inline (Postmark, sometimes) or provides a handle
 * (provider_ref) that MailIngester can use to fetch bytes later via the
 * same adapter — keeping large payloads out of memory during pull.
 */
final class MailAttachmentData
{
    public function __construct(
        public readonly string $filename,
        public readonly ?string $mime,
        public readonly ?int $size,
        public readonly ?string $content = null,
        public readonly ?string $providerRef = null,
    ) {}
}
