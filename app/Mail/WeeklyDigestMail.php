<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "What changed this week" — a short summary mailed weekly so the user can
 * notice spending surprises, pending tasks, and upcoming deadlines without
 * opening the app. Content is assembled by SendWeeklyDigest; this mailable
 * just renders it.
 *
 * @phpstan-type DigestPayload array{
 *     window_start: string,
 *     window_end: string,
 *     new_transactions_count: int,
 *     new_transactions_net: float,
 *     completed_tasks_count: int,
 *     upcoming_tasks_count: int,
 *     upcoming_bills_count: int,
 *     upcoming_bills_total: float,
 *     expiring_contracts_count: int,
 *     expiring_contracts: array<int, array{title: string, ends_on: string, cancellation_url: ?string, cancellation_email: ?string}>,
 *     currency: string,
 * }
 */
class WeeklyDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  DigestPayload  $payload
     */
    public function __construct(public readonly User $user, public readonly array $payload) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Bureau · this week at a glance'));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.weekly-digest', with: [
            'user' => $this->user,
            'payload' => $this->payload,
        ]);
    }
}
