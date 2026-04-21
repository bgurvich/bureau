<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Household;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class HouseholdInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $acceptUrl,
        public readonly Household $household,
        public readonly ?User $invitedBy,
        public readonly string $inviteeEmail,
        public readonly string $role,
        public readonly \DateTimeInterface $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __(':inviter invited you to :household on :app', [
                'inviter' => $this->invitedBy !== null ? $this->invitedBy->name : __('Someone'),
                'household' => $this->household->name,
                'app' => config('app.name'),
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.household-invitation',
            with: [
                'acceptUrl' => $this->acceptUrl,
                'householdName' => $this->household->name,
                'inviterName' => $this->invitedBy?->name,
                'inviteeEmail' => $this->inviteeEmail,
                'role' => $this->role,
                'expiresAt' => $this->expiresAt,
            ],
        );
    }
}
