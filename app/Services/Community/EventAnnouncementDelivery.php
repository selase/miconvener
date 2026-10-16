<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Mail\Events\EventBlastMail;
use App\Models\EventBlast;
use App\Models\EventBlastEmailClaim;
use App\Models\EventBlastRecipient;
use App\Models\EventNotificationLog;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Models\TenantNotificationSetting;
use App\Services\Billing\EmailAllowance;
use App\Services\Notifications\NotificationGatewayService;
use App\Services\Sms\SmsAllowance;
use App\Services\Sms\SmsMessage;
use App\Services\Sms\SmsResult;
use Carbon\CarbonImmutable;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Event-owned claims and templates; consent, credit locks and transport are shared. */
final class EventAnnouncementDelivery
{
    public function __construct(private MessagingPreferences $preferences, private EmailAllowance $email, private SmsAllowance $sms, private CommunitySmsBudget $budget, private AnnouncementTransport $transport) {}

    public function queueEmail(EventBlast $blast, EventRegistration $registration): void
    {
        $tenant = $blast->tenant;
        $address = $this->preferences->normalise('email', (string) $registration->email);
        if ($tenant === null || $address === null) {
            return;
        }
        $claim = $this->email->withLock($tenant, function () use ($tenant, $blast, $registration, $address): ?EventBlastEmailClaim {
            $claim = EventBlastEmailClaim::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'blast_id' => $blast->id, 'address_hash' => $this->preferences->hash('email', $address)],
                ['status' => 'reserved', 'claimed_at' => now()],
            );
            if (! $claim->wasRecentlyCreated) {
                return null;
            }
            if (! $this->preferences->allowsMarketing($tenant, 'email', $address)) {
                $claim->update(['status' => 'skipped', 'reason' => 'unsubscribed']);

                return null;
            }
            if (EventBlastRecipient::query()->where('blast_id', $blast->id)->where('registration_id', $registration->id)->exists()) {
                $claim->update(['status' => 'unknown', 'reason' => 'legacy_outcome']);

                return null;
            }
            $receipt = $this->email->reserve($tenant, 1, allowMissingLegacyFeature: true);
            if ($receipt === null) {
                $claim->update(['status' => 'skipped', 'reason' => 'no_credit']);

                return null;
            }
            $recipient = EventBlastRecipient::query()->create(['tenant_id' => $tenant->id, 'blast_id' => $blast->id, 'registration_id' => $registration->id]);
            $claim->update(['credit_receipt_id' => $receipt->id, 'recipient_id' => $recipient->id, 'unsubscribe_token' => $this->preferences->token($tenant, 'email', $address)]);

            return $claim;
        });
        if ($claim === null) {
            return;
        }
        try {
            $this->transport->email($address, new EventBlastMail($blast, $registration->full_name, $claim->recipient_id, $claim->id));
            EventBlastEmailClaim::query()->whereKey($claim->id)->where('status', 'reserved')->update(['status' => 'queued']);
        } catch (Throwable) {
            $claim->update(['status' => 'unknown', 'reason' => 'queue_outcome_unknown']);
            Log::warning('Event announcement email queue outcome unknown.', ['claim_id' => $claim->id]);
        }
    }

    /** @param callable(): ?SentMessage $send */
    public function sendQueuedEmail(EventBlastMail $mail, callable $send): ?SentMessage
    {
        $tenant = Tenant::query()->find($mail->blast->tenant_id);
        $address = $this->preferences->normalise('email', (string) ($mail->to[0]['address'] ?? ''));
        if ($tenant === null || $address === null) {
            return null;
        }
        $claim = $mail->claimId === null ? null : EventBlastEmailClaim::query()->where('tenant_id', $tenant->id)->find($mail->claimId);
        if ($claim === null) {
            return $this->preferences->allowsMarketing($tenant, 'email', $address) ? $send() : null;
        }
        $ready = $this->email->withLock($tenant, function () use ($tenant, $mail, $address, $claim): bool {
            $locked = EventBlastEmailClaim::query()->lockForUpdate()->findOrFail($claim->id);
            if (! in_array($locked->status, ['reserved', 'queued'], true)) {
                return false;
            }
            $blast = EventBlast::withoutGlobalScopes()->find($mail->blast->id);
            $eligible = $blast !== null && $blast->event !== null && $blast->status !== EventBlast::STATUS_CANCELLED && $this->preferences->allowsMarketing($tenant, 'email', $address)
                && EventBlast::audienceQuery($blast->event, $blast->audience)->whereRaw('lower(trim(email)) = ?', [$address])->exists();
            if (! $eligible) {
                if ($locked->credit_receipt_id !== null) {
                    $this->email->refund($tenant, $locked->credit_receipt_id);
                }
                $locked->update(['status' => 'skipped', 'reason' => 'no_longer_eligible']);

                return false;
            }
            $locked->update(['status' => 'submitting', 'claimed_at' => now()]);

            return true;
        });
        if (! $ready) {
            return null;
        }
        try {
            $sent = $send();
            if ($sent === null) {
                if ($claim->credit_receipt_id !== null) {
                    $this->email->refund($tenant, $claim->credit_receipt_id);
                }
                $claim->update(['status' => 'skipped', 'reason' => 'mail_not_submitted']);

                return null;
            }
            $claim->update(['status' => 'accepted', 'sent_at' => now()]);

            return $sent;
        } catch (Throwable) {
            $claim->update(['status' => 'unknown', 'reason' => 'outcome_unknown']);
            Log::warning('Event announcement email outcome unknown.', ['claim_id' => $claim->id]);

            return null;
        }
    }

    /** @param iterable<EventNotificationLog> $deliveries
     * @return array<string, array{status: string, message: string, cost: int, retry_after?: ?int}>
     */
    public function sms(iterable $deliveries): array
    {
        $results = [];
        $pending = [];
        foreach ($deliveries as $candidate) {
            $tenant = Tenant::query()->find($candidate->tenant_id);
            if ($tenant === null) {
                continue;
            }
            $row = $this->sms->withLock($tenant, function () use ($candidate, $tenant): ?EventNotificationLog {
                $row = EventNotificationLog::withoutGlobalScopes()->where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($candidate->id);
                if ($row->status !== EventNotificationLog::STATUS_PENDING) {
                    return null;
                }
                $address = $this->preferences->normalise('sms', (string) $row->recipient_phone);
                if (! $this->smsEligible($tenant, $row)) {
                    $row->update(['status' => EventNotificationLog::STATUS_UNSUBSCRIBED]);

                    return null;
                }
                $reservation = $this->budget->reserve($tenant, 'event_blast', $row->id, CarbonImmutable::now());
                if ($reservation === null) {
                    $row->update(['status' => EventNotificationLog::STATUS_SUPPRESSED_QUOTA]);

                    return null;
                }
                $token = $this->preferences->token($tenant, 'sms', $address);
                $metadata = $row->metadata ?? [];
                $row->update(['status' => EventNotificationLog::STATUS_STAGED, 'last_attempted_at' => now(), 'attempts' => $row->attempts + 1,
                    'metadata' => $metadata + ['community_reservation_id' => $reservation->id, 'unsubscribe_token' => Crypt::encryptString($token)]]);

                return $row;
            });
            if ($row === null) {
                $current = $candidate->fresh();
                $results[$candidate->id] = ['status' => $current->status, 'message' => 'Announcement skipped or already claimed.', 'cost' => 0];
            } else {
                $pending[$row->id] = [$row, $tenant];
            }
        }
        foreach (array_chunk($pending, 50, true) as $batch) {
            foreach ($batch as $id => [$row, $tenant]) {
                if (! $this->smsEligible($tenant->fresh(), $row)) {
                    $this->budget->finish($tenant, $row->metadata['community_reservation_id'], SmsResult::refused('Confirmed not submitted.'));
                    $row->update(['status' => EventNotificationLog::STATUS_UNSUBSCRIBED]);
                    $results[$id] = ['status' => $row->status, 'message' => 'No longer eligible before submission.', 'cost' => 0];
                    unset($batch[$id]);
                }
            }
            if ($batch === []) {
                continue;
            }
            $messages = [];
            foreach ($batch as $id => [$row, $tenant]) {
                $token = Crypt::decryptString($row->metadata['unsubscribe_token']);
                $tail = ' Stop: '.route('public.community.preferences.show', ['subdomain' => $tenant->slug, 'token' => $token]);
                $body = NotificationGatewayService::smsText($row->message);
                $available = NotificationGatewayService::SMS_MAX_LENGTH - mb_strlen($tail);
                $body = mb_strlen($body) > $available ? mb_substr($body, 0, max(0, $available - 1)).'…' : $body;
                $messages[] = new SmsMessage($row->recipient_phone, $body.$tail, $id);
            }
            try {
                $outcomes = $this->transport->sms($messages);
            } catch (Throwable) {
                $outcomes = [];
            }
            foreach ($batch as $id => [$row, $tenant]) {
                $result = $outcomes[$id] ?? SmsResult::unknown('Provider outcome missing.');
                $this->budget->finish($tenant, $row->metadata['community_reservation_id'], $result);
                $status = $result->accepted ? EventNotificationLog::STATUS_SENT : ($result->isThrottled() ? EventNotificationLog::STATUS_PENDING : ($result->uncertain ? EventNotificationLog::STATUS_STAGED : EventNotificationLog::STATUS_FAILED));
                $row->update(['status' => $status, 'sent_at' => $result->accepted ? now() : null,
                    'metadata' => array_merge($row->metadata, ['provider_reference' => $result->providerReference, 'outcome' => $result->accepted ? 'accepted' : ($result->uncertain ? 'unknown' : 'refused')])]);
                $results[$id] = ['status' => $status, 'message' => $result->accepted ? 'SMS accepted by provider.' : 'SMS not confirmed; see outcome.', 'cost' => 0, 'retry_after' => $result->retryAfter];
            }
        }

        return $results;
    }

    private function smsEligible(Tenant $tenant, EventNotificationLog $row): bool
    {
        $address = $this->preferences->normalise('sms', (string) $row->recipient_phone);
        $blast = EventBlast::withoutGlobalScopes()->where('tenant_id', $tenant->id)->find($row->source_id);
        if ($address === null || $blast === null || $blast->event === null || $blast->status === EventBlast::STATUS_CANCELLED
            || ! TenantNotificationSetting::forTenant($tenant->id)->canSend('sms') || ! $this->preferences->allowsMarketing($tenant, 'sms', $address)) {
            return false;
        }
        $registrationId = $row->metadata['registration_id'] ?? null;
        if ($registrationId === null) {
            $prefix = 'blast-sms:'.$blast->id.':';
            $legacyId = str_starts_with($row->dedupe_key, $prefix) ? mb_substr($row->dedupe_key, mb_strlen($prefix)) : '';
            $registrationId = \Illuminate\Support\Str::isUuid($legacyId) ? $legacyId : null;
        }
        $registration = EventRegistration::withoutGlobalScope(\App\Scopes\TenantScope::class)->where('tenant_id', $tenant->id)->find($registrationId);

        return $registration !== null && $this->preferences->normalise('sms', (string) $registration->phone) === $address
            && EventBlast::audienceQuery($blast->event, $blast->audience)->whereKey($registration->id)->exists();
    }
}
