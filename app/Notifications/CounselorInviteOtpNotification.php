<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CounselorInviteOtpNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $campusName,
        public readonly string $inviterName,
        public readonly string $inviteUrl,
        public readonly string $otpCode,
        public readonly string $expiresAtLabel,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("CPF Guidance Counselor Invitation & OTP — {$this->campusName}")
            ->greeting('Hello Guidance Counselor,')
            ->line("{$this->inviterName} invited you to join {$this->campusName} on the Circle of Peer Facilitators (CPF) portal with coordination access to Counselors Private GC, Testing Room schedules, and confidential Guidance Referrals.")
            ->line("Your 6-digit verification OTP code is: **{$this->otpCode}**")
            ->action('Accept Guidance Counselor Invitation', $this->inviteUrl)
            ->line("This signed invitation link and OTP expire on {$this->expiresAtLabel} (24 hours).")
            ->line('If you were not expecting this invitation, you may safely ignore this email.');
    }
}
