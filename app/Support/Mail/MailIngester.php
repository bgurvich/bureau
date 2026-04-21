<?php

namespace App\Support\Mail;

use App\Jobs\ExtractOcrStructure;
use App\Jobs\OcrMedia;
use App\Models\Household;
use App\Models\Integration;
use App\Models\MailAttachment;
use App\Models\MailIngestInbox;
use App\Models\MailMessage;
use App\Models\Media;
use App\Support\CurrentHousehold;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Takes provider-agnostic MailMessageData + its attachments and lands them
 * in the database. Responsibilities:
 *   1. Dedup by RFC 5322 Message-ID within a household (a single bill
 *      forwarded to several mailboxes won't create duplicates).
 *   2. Persist mail_messages / mail_attachments rows.
 *   3. Save attachment bytes as Media rows (polymorphic-ready for later
 *      attach-to-record flows) and queue OcrMedia for images.
 *
 * The caller decides which Integration/Inbox this message came from; the
 * ingester doesn't care about the source, only about the household scope.
 */
class MailIngester
{
    /**
     * @return array{message: MailMessage, created: bool}
     */
    public function ingest(
        Household $household,
        MailMessageData $data,
        ?Integration $integration = null,
        ?MailIngestInbox $inbox = null,
        ?MailProvider $provider = null,
    ): array {
        CurrentHousehold::set($household);

        // Dedup: a previously-ingested message wins, no matter which source
        // the second delivery came through.
        $existing = MailMessage::withoutGlobalScopes()
            ->where('household_id', $household->id)
            ->where('message_id', $data->messageId)
            ->first();
        if ($existing) {
            return ['message' => $existing, 'created' => false];
        }

        $message = MailMessage::create([
            'household_id' => $household->id,
            'integration_id' => $integration?->id,
            'inbox_id' => $inbox?->id,
            'provider_message_id' => $data->providerMessageId,
            'message_id' => $data->messageId,
            'received_at' => $data->receivedAt,
            'from_address' => $data->fromAddress,
            'from_name' => $data->fromName,
            'to_addresses' => $data->toAddresses,
            'subject' => $data->subject,
            'text_body' => $data->textBody,
            'html_body' => $data->htmlBody,
            'headers' => $data->headers,
        ]);

        $imageAttachmentCount = 0;
        foreach ($data->attachments as $attachment) {
            if ($this->persistAttachment($household, $message, $integration, $attachment, $provider)) {
                $imageAttachmentCount++;
            }
        }

        // HTML/text-body bills: when the email carries no image attachment
        // but has substantive body text, synthesize a Media from the body so
        // it flows through the same OCR-extract-Inbox pipeline as a scan.
        if ($imageAttachmentCount === 0) {
            $this->persistBodyAsMedia($household, $message);
        }

        return ['message' => $message, 'created' => true];
    }

    /**
     * Returns true when the attachment was persisted as an image (so the
     * caller can know whether to still synthesize a body-derived Media).
     */
    private function persistAttachment(
        Household $household,
        MailMessage $message,
        ?Integration $integration,
        MailAttachmentData $attachment,
        ?MailProvider $provider,
    ): bool {
        $bytes = $attachment->content;
        if ($bytes === null && $attachment->providerRef && $provider && $integration) {
            $bytes = $provider->fetchAttachment($integration, $attachment);
        }

        if ($bytes === null) {
            // Without bytes we can still record the attachment row for audit,
            // just without a Media link. The UI can show "couldn't fetch".
            MailAttachment::create([
                'message_id' => $message->id,
                'media_id' => null,
                'filename' => $attachment->filename,
                'mime' => $attachment->mime,
                'size' => $attachment->size,
            ]);

            return false;
        }

        $ext = pathinfo($attachment->filename, PATHINFO_EXTENSION) ?: 'bin';
        $path = 'mail/'.$household->id.'/'.date('Y/m').'/'.(string) Str::uuid().'.'.$ext;
        Storage::disk('local')->put($path, $bytes);

        $mime = $attachment->mime ?: (Storage::disk('local')->mimeType($path) ?: null);
        $isImage = is_string($mime) && str_starts_with($mime, 'image/');

        $media = Media::create([
            'household_id' => $household->id,
            'disk' => 'local',
            'source' => 'mail',
            'path' => $path,
            'original_name' => $attachment->filename,
            'mime' => $mime,
            'size' => $attachment->size ?? strlen($bytes),
            'captured_at' => $message->received_at,
            'ocr_status' => $isImage ? 'pending' : null,
        ]);

        MailAttachment::create([
            'message_id' => $message->id,
            'media_id' => $media->id,
            'filename' => $attachment->filename,
            'mime' => $mime,
            'size' => $media->size,
        ]);

        if ($isImage) {
            OcrMedia::dispatch($media->id);
        }

        return $isImage;
    }

    /**
     * Create a synthetic Media row from the email body so HTML-only bill
     * emails (no PDF, no image) flow through the Inbox via the same extract
     * path. Short/empty bodies are skipped — we want bill-like content, not
     * verification codes or newsletters shorter than a bill line item.
     */
    private function persistBodyAsMedia(Household $household, MailMessage $message): void
    {
        $text = $this->bodyText($message);
        if (mb_strlen($text) < 100) {
            return;
        }

        $isHtml = is_string($message->html_body) && $message->html_body !== '';
        $ext = $isHtml ? 'html' : 'txt';
        $mime = $isHtml ? 'text/html' : 'text/plain';
        $payload = $isHtml ? (string) $message->html_body : (string) $message->text_body;

        $subject = $message->subject ?: __('Email body');
        $name = Str::limit(Str::slug($subject) ?: 'email-body', 120, '').'.'.$ext;

        $path = 'mail/'.$household->id.'/'.date('Y/m').'/'.(string) Str::uuid().'.'.$ext;
        Storage::disk('local')->put($path, $payload);

        $media = Media::create([
            'household_id' => $household->id,
            'disk' => 'local',
            'source' => 'mail',
            'path' => $path,
            'original_name' => $name,
            'mime' => $mime,
            'size' => strlen($payload),
            'captured_at' => $message->received_at,
            // Pre-fill the text directly — Tesseract isn't needed for an
            // HTML/plain body. ExtractOcrStructure will consume this.
            'ocr_status' => 'done',
            'ocr_text' => $text,
        ]);

        MailAttachment::create([
            'message_id' => $message->id,
            'media_id' => $media->id,
            'filename' => $name,
            'mime' => $mime,
            'size' => $media->size,
        ]);

        // Mark the extraction pipeline as queued; the job writes ocr_extracted.
        if (config('services.lm_studio.enabled')) {
            $media->forceFill(['extraction_status' => 'pending'])->save();
            ExtractOcrStructure::dispatch($media->id);
        }
    }

    /**
     * Prefer text_body when present; otherwise strip the HTML body into
     * readable text. Keeps tagging crumbs out of the extractor input.
     */
    private function bodyText(MailMessage $message): string
    {
        $text = is_string($message->text_body) ? trim((string) $message->text_body) : '';
        if ($text !== '') {
            return $text;
        }
        $html = is_string($message->html_body) ? (string) $message->html_body : '';
        if ($html === '') {
            return '';
        }

        // Drop script / style contents before stripping so their payloads
        // don't leak into the extracted text.
        $html = (string) preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $html);
        // Turn block-level closers into newlines so the text reads naturally.
        $html = (string) preg_replace('/<(br|\/p|\/div|\/li|\/tr|\/h[1-6])\b[^>]*>/i', "\n", $html);

        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/[ \t]+/', ' ', $text);
        $text = (string) preg_replace("/\n[ \t]*\n\s*/", "\n\n", $text);

        return trim($text);
    }
}
