<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MembershipApprovedPasskeyNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $memberName,
        public readonly string $recipientEmail,
        public readonly string $campusName,
        public readonly string $roleLabel,
        public readonly string $passkeyCode,
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
            ->subject('Welcome to Circle of Peer Facilitators (CPF) — Your One-Time Passkey Code')
            ->greeting("Hello, {$this->memberName}!")
            ->line("Congratulations! Your CPF membership application for **{$this->campusName}** has been officially verified and approved ({$this->roleLabel}).")
            ->line("Your locked 12-character Passkey Code is: **{$this->passkeyCode}**")
            ->line("Account Email: **{$this->recipientEmail}**")
            ->line('How to sign in and unlock your Attendance QR Code:')
            ->line('1. Click the button below to open the CPF Portal Login page and choose the **Passkey Code** tab.')
            ->line('2. Enter your Account Email and type your 12-character Passkey Code into the 12 boxes.')
            ->line('3. Create your own personal password when prompted — no temporary password is needed.')
            ->action('Sign In with Passkey Code', route('login'))
            ->line('Security Notice: For your protection, this Passkey Code email is delivered **one time only**. Once signed in, you can view or regenerate your Passkey Code anytime from your CPF Dashboard.');
    }
}
