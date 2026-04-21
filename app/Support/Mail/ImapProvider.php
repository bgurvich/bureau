<?php

namespace App\Support\Mail;

use App\Models\Integration;

/**
 * IMAP is a planned fallback for mailboxes that speak neither JMAP nor have a
 * convenient API (iCloud, Outlook, generic). Not implemented yet — see
 * ROADMAP.md "Integrations → Mail → IMAP (generic)". Keeping the class
 * registered so mail:sync can return a clear "skip" message when an IMAP
 * integration is encountered instead of falling through to a hard error.
 */
class ImapProvider implements MailProvider
{
    public function pullSince(Integration $integration): iterable
    {
        throw new \RuntimeException('IMAP provider is not implemented yet.');
    }

    public function fetchAttachment(Integration $integration, MailAttachmentData $attachment): ?string
    {
        throw new \RuntimeException('IMAP provider is not implemented yet.');
    }
}
