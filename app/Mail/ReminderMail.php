<?php

namespace App\Mail;

use App\Models\Reminder;
use App\Models\User;
use App\Support\MagicLink;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * The recipient User is captured so we can embed a MagicLink that
     * auto-authenticates the recipient when they tap the "Open Bureau"
     * button on their phone. Nullable for legacy callers / tests that
     * just render the body without delivering.
     */
    public function __construct(
        public Reminder $reminder,
        public ?User $user = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Reminder: :title', ['title' => $this->reminder->title]),
        );
    }

    public function content(): Content
    {
        $url = $this->user
            ? MagicLink::to($this->user, 'dashboard')
            : config('app.url');

        return new Content(
            markdown: 'emails.reminder',
            with: [
                'reminder' => $this->reminder,
                'url' => $url,
            ],
        );
    }
}
