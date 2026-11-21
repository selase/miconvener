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
use Throwable;

final class AccountEmailChanged extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public string $changeId)
    {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your account email was changed');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.users.account-email-changed');
    }

    public function send($mailer): mixed
    {
        $claimed = AccountEmailChange::query()->whereKey($this->changeId)->whereNotNull('consumed_at')
            ->where('notification_status', 'pending')->update(['notification_status' => 'sending']);
        if ($claimed !== 1) {
            return null;
        }
        try {
            $result = parent::send($mailer);
            AccountEmailChange::query()->whereKey($this->changeId)->update(['notification_status' => 'sent', 'notified_at' => now()]);

            return $result;
        } catch (Throwable) {
            AccountEmailChange::query()->whereKey($this->changeId)->update(['notification_status' => 'uncertain']);

            return null;
        }
    }
}
