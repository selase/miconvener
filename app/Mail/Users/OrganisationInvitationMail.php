<?php

declare(strict_types=1);

namespace App\Mail\Users;

use App\Models\OrganisationInvitation;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

final class OrganisationInvitationMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public string $invitationId, public int $revision)
    {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Organisation invitation');
    }

    public function content(): Content
    {
        $invitation = OrganisationInvitation::query()->findOrFail($this->invitationId);
        abort_unless($invitation->revision === $this->revision && $invitation->delivery_payload, 410);

        return new Content(view: 'emails.users.organisation-invitation', with: [
            'organisationName' => $invitation->tenant->name,
            'acceptUrl' => mb_rtrim((string) config('app.url'), '/').'/organisation-invitations/'.$invitation->id.'#'.Crypt::decryptString($invitation->delivery_payload),
            'expiresAt' => $invitation->expires_at,
        ]);
    }

    public function send($mailer): mixed
    {
        $record = OrganisationInvitation::query()->find($this->invitationId);
        if (! $record) {
            return null;
        }
        $claimed = DB::connection('landlord')->transaction(function () use ($record): bool {
            Tenant::query()->whereKey($record->tenant_id)->lockForUpdate()->firstOrFail();
            $record = OrganisationInvitation::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            if ($record->revision !== $this->revision || $record->status !== 'pending' || ! $record->expires_at->isFuture() || $record->delivery_status !== 'pending') {
                return false;
            }
            $record->forceFill(['delivery_status' => 'sending', 'delivery_claimed_at' => now()])->save();

            return true;
        });
        if (! $claimed) {
            return null;
        }
        try {
            $result = class_exists(\Laravel\Telescope\Telescope::class)
                ? \Laravel\Telescope\Telescope::withoutRecording(fn () => parent::send($mailer))
                : parent::send($mailer);
            OrganisationInvitation::query()->whereKey($record->id)->where('revision', $this->revision)->update(['delivery_status' => 'sent', 'delivered_at' => now(), 'delivery_payload' => null]);

            return $result;
        } catch (Throwable) {
            OrganisationInvitation::query()->whereKey($record->id)->where('revision', $this->revision)->update(['delivery_status' => 'uncertain', 'delivery_payload' => null]);

            return null;
        }
    }
}
