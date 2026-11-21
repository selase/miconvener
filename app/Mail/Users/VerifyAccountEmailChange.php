<?php

declare(strict_types=1);

namespace App\Mail\Users;

use App\Models\AccountEmailChange;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Crypt;

final class VerifyAccountEmailChange extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public string $changeId, public string $encryptedToken)
    {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Verify your new account email');
    }

    public function content(): Content
    {
        $change = AccountEmailChange::query()->findOrFail($this->changeId);

        return new Content(view: 'emails.users.verify-account-email-change', with: [
            'confirmationUrl' => mb_rtrim((string) config('app.url'), '/').'/account-identity/email-changes/'.$change->id.'#'.Crypt::decryptString($this->encryptedToken),
            'expiresAt' => $change->expires_at,
        ]);
    }

    /** @param \Illuminate\Contracts\Mail\Factory|\Illuminate\Contracts\Mail\Mailer $mailer */
    public function send($mailer): mixed
    {
        $change = AccountEmailChange::query()->with('user')->find($this->changeId);
        if (! $change || $change->consumed_at || $change->cancelled_at || ! $change->expires_at->isFuture()
            || ! $change->user || $change->user->email !== $change->current_email
            || (int) $change->user->security_version !== $change->security_version) {
            return null;
        }

        return class_exists(\Laravel\Telescope\Telescope::class)
            ? \Laravel\Telescope\Telescope::withoutRecording(fn () => parent::send($mailer))
            : parent::send($mailer);
    }
}
