<?php

namespace App\Support\Mail;

use App\Models\Integration;

/**
 * Adapter contract for pull-style mail providers (JMAP, Gmail, IMAP). A push
 * provider like Postmark does not implement this — it lands on a webhook
 * controller and feeds the MailIngester directly.
 *
 * Adapters are stateless factories parameterised by an Integration row:
 * credentials, settings (folder/label), and cursor all live there. The
 * adapter reads/writes the cursor through the integration model; the
 * caller is expected to save() after pullSince() returns.
 */
interface MailProvider
{
    /**
     * Stream new messages since the integration's stored cursor. The cursor
     * is updated in-place on the Integration's settings; caller persists.
     *
     * @return iterable<MailMessageData>
     */
    public function pullSince(Integration $integration): iterable;

    /**
     * Resolve raw attachment bytes for a MailAttachmentData that was emitted
     * with only a providerRef. Returns null when the handle is stale.
     */
    public function fetchAttachment(Integration $integration, MailAttachmentData $attachment): ?string;
}
