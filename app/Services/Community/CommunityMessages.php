<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Contracts\SmsGateway;
use App\Enum\TenantStatusEnum;
use App\Jobs\DeliverCommunityMessage;
use App\Jobs\DispatchCommunityMessage;
use App\Mail\CommunityMessage as MessageMail;
use App\Models\CommunityMessage;
use App\Models\CommunityMessageDelivery;
use App\Models\CommunitySettings;
use App\Models\Tenant;
use App\Models\TenantNotificationSetting;
use App\Models\User;
use App\Services\Billing\EmailAllowance;
use App\Services\Notifications\NotificationGatewayService;
use App\Services\Sms\SmsAllowance;
use App\Services\Sms\SmsMessage;
use App\Services\Sms\SmsResult;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

final class CommunityMessages
{
    public const int PAGE_SIZE = 100;

    public function __construct(private AudienceResolver $audiences, private MemberAudienceSource $members, private MessagingPreferences $preferences, private MessageEntitlements $entitlements, private CommunitySmsBudget $budget, private EmailAllowance $email, private SmsAllowance $sms, private SmsGateway $gateway, private AnnouncementTransport $transport) {}

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function preview(Tenant $tenant, User $actor, array $input): array
    {
        $this->authorize($tenant, $actor);
        $this->checkEntitlements($tenant, $input);
        $scheduled = $this->schedule($tenant, $input['scheduled_at'] ?? null);
        $counts = [];
        foreach ($input['channels'] as $channel) {
            $counts[$channel] = iterator_count($this->audiences->resolve($tenant, $input['audience'], $channel, $actor));
        }
        $smsRemaining = $this->sms->remaining($tenant);
        $monthlyRemaining = $this->budget->remaining($tenant, CarbonImmutable::now());
        if (in_array('sms', $input['channels'], true) && mb_strlen($this->smsText($tenant, $input['subject'], $input['body'], str_repeat('a', 64))) > NotificationGatewayService::SMS_MAX_LENGTH) {
            throw ValidationException::withMessages(['body' => 'Shorten the SMS, including its announcement opt-out link, to 459 characters.']);
        }

        return ['counts' => $counts, 'sms_credits_needed' => $counts['sms'] ?? 0, 'sms_credits_remaining' => $smsRemaining,
            'sms_monthly_remaining' => $monthlyRemaining, 'email_credits_remaining' => $this->email->remaining($tenant),
            'timezone' => CommunitySettings::for($tenant)->timezone, 'scheduled_at' => $scheduled?->toIso8601String()];
    }

    /** @param array<string, mixed> $input */
    public function queue(Tenant $tenant, User $actor, array $input, ?CommunityMessage $editing = null): CommunityMessage
    {
        $this->authorize($tenant, $actor);
        $key = $input['idempotency_key'] ?? '';
        if (! is_string($key) || ! Str::isUuid($key)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Start a fresh message and try again.']);
        }
        $payload = [];
        foreach (['subject', 'body', 'channels', 'audience', 'scheduled_at', 'mode'] as $field) {
            $payload[$field] = $input[$field] ?? null;
        }
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return $tenant->getConnection()->transaction(function () use ($tenant, $actor, $input, $key, $hash, $editing): CommunityMessage {
            $tenant->getConnection()->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['community.message.submit:'.$tenant->id.':'.$key]);
            $existing = CommunityMessage::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                abort_unless(($editing === null || $existing->id === $editing->id) && hash_equals($existing->payload_hash, $hash), 409, 'This submission key already belongs to a different message.');

                return $existing;
            }
            if ($editing !== null) {
                $editing = CommunityMessage::withoutGlobalScopes()->where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($editing->id);
                abort_unless($editing->status === 'draft', 409, 'Only a draft can be edited. Start a new announcement instead.');
            }
            $preview = $this->preview($tenant, $actor, $input);
            $draft = ($input['mode'] ?? 'send') === 'draft';
            if (! $draft) {
                if (! filter_var($input['confirm_authority'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    throw ValidationException::withMessages(['confirm_authority' => 'Confirm you are authorised to contact this audience.']);
                }
                $this->checkCapacity($tenant, $preview);
                if (array_sum($preview['counts']) === 0) {
                    throw ValidationException::withMessages(['audience' => 'No eligible addresses remain in this audience.']);
                }
            }
            $scheduled = $preview['scheduled_at'];
            $message = $editing ?? new CommunityMessage;
            $message->fill([
                'tenant_id' => $tenant->id, 'sent_by' => $actor->id, 'idempotency_key' => $key, 'payload_hash' => $hash,
                'subject' => $input['subject'], 'body' => $input['body'], 'channels' => $input['channels'], 'audience' => $input['audience'],
                'estimate' => $preview['counts'], 'scheduled_at' => $scheduled,
                'status' => $draft ? 'draft' : ($scheduled === null ? 'queued' : 'scheduled'),
            ]);
            $message->save();
            if (! $draft) {
                $dispatch = DispatchCommunityMessage::dispatch($message->id)->afterCommit();
                if ($scheduled !== null) {
                    $dispatch->delay(CarbonImmutable::parse($scheduled));
                }
            }

            return $message;
        });
    }

    public function cancel(Tenant $tenant, User $actor, CommunityMessage $message): void
    {
        $this->authorize($tenant, $actor);
        abort_unless($message->tenant_id === $tenant->id, 404);
        $tenant->getConnection()->transaction(function () use ($message): void {
            $locked = CommunityMessage::withoutGlobalScopes()->lockForUpdate()->findOrFail($message->id);
            if (in_array($locked->status, ['completed', 'partial'], true)) {
                throw ValidationException::withMessages(['message' => 'Processing has finished. Submitted messages cannot be recalled.']);
            }
            $locked->update(['status' => 'cancelled', 'cancelled_at' => $locked->cancelled_at ?? now()]);
        });
    }

    public function dispatchDue(string $messageId): void
    {
        $model = CommunityMessage::withoutGlobalScopes()->find($messageId);
        if ($model === null) {
            return;
        }
        $model->getConnection()->transaction(function () use ($messageId): void {
            $message = CommunityMessage::withoutGlobalScopes()->lockForUpdate()->findOrFail($messageId);
            if (! in_array($message->status, ['queued', 'scheduled', 'sending'], true) || ($message->scheduled_at !== null && $message->scheduled_at->isFuture()) || $message->selection_complete) {
                return;
            }
            $tenant = Tenant::query()->find($message->tenant_id);
            $actor = User::query()->find($message->sent_by);
            if (! $this->stillAllowed($tenant, $actor, $message)) {
                return;
            }
            $started = $message->started_at ?? CarbonImmutable::now();
            $selector = $message->audience + ['as_of' => $started];
            $ids = $this->members->query($tenant, $selector)->when($message->last_member_id !== null, fn ($query) => $query->where('id', '>', $message->last_member_id))->orderBy('id')->limit(self::PAGE_SIZE)->pluck('id')->all();
            $selector['member_ids'] = $ids;
            $ready = ['email' => [], 'sms' => []];
            foreach ($message->channels as $channel) {
                foreach ($this->audiences->resolve($tenant, $selector, $channel, $actor) as $address) {
                    $delivery = CommunityMessageDelivery::query()->firstOrCreate(
                        ['message_id' => $message->id, 'channel' => $channel, 'address_hash' => $this->preferences->hash($channel, $address->address)],
                        ['tenant_id' => $tenant->id, 'address' => $address->address, 'status' => 'queued'],
                    );
                    if ($delivery->status === 'queued') {
                        $ready[$channel][] = $delivery->id;
                    }
                }
            }
            $complete = count($ids) < self::PAGE_SIZE;
            $message->update(['status' => 'sending', 'started_at' => $started, 'last_member_id' => $ids === [] ? $message->last_member_id : $ids[array_key_last($ids)], 'selection_complete' => $complete]);
            foreach ($ready as $channel => $deliveryIds) {
                foreach (array_chunk($deliveryIds, $channel === 'email' ? 1 : 50) as $batch) {
                    DeliverCommunityMessage::dispatch($message->id, $batch, $channel)->afterCommit();
                }
            }
            if (! $complete) {
                DispatchCommunityMessage::dispatch($message->id)->afterCommit();
            }
            $this->finishProcessing($message->fresh());
        });
    }

    /** @param list<string> $deliveryIds */
    public function deliver(string $messageId, array $deliveryIds, string $channel): void
    {
        if (! in_array($channel, ['email', 'sms'], true) || count($deliveryIds) > ($channel === 'email' ? 1 : 50)) {
            throw new InvalidArgumentException('Delivery units must be bounded by channel.');
        }
        $message = CommunityMessage::withoutGlobalScopes()->find($messageId);
        if ($message === null || $message->status !== 'sending') {
            return;
        }
        $tenant = Tenant::query()->find($message->tenant_id);
        $actor = User::query()->find($message->sent_by);
        if (! $this->stillAllowed($tenant, $actor, $message)) {
            return;
        }
        $pending = [];
        foreach ($deliveryIds as $id) {
            $delivery = $this->claim($tenant, $actor, $message, $id, $channel);
            if ($delivery === null) {
                continue;
            }
            if (! $this->eligible($tenant, $message, $delivery) || $message->fresh()->status === 'cancelled') {
                $this->refundUnsubmitted($tenant, $delivery);

                continue;
            }
            if ($channel === 'email') {
                try {
                    $this->transport->email($delivery->address, new MessageMail($message, $this->unsubscribeUrl($tenant, $delivery->unsubscribe_token)));
                    $delivery->update(['status' => 'accepted']);
                } catch (Throwable) {
                    $delivery->update(['status' => 'unknown', 'reason' => 'outcome_unknown']);
                    Log::warning('Community email outcome unknown.', ['delivery_id' => $delivery->id]);
                }
            } else {
                $pending[$delivery->id] = $delivery;
            }
        }
        if ($pending !== []) {
            $message = $message->fresh();
            $tenant = $tenant->fresh();
            $actor = User::query()->find($message->sent_by);
            $allowed = $message->status === 'sending' && $this->stillAllowed($tenant, $actor, $message);
            foreach ($pending as $id => $delivery) {
                if (! $allowed || ! $this->eligible($tenant, $message, $delivery)) {
                    $this->refundUnsubmitted($tenant, $delivery);
                    unset($pending[$id]);
                }
            }
        }
        if ($pending !== []) {
            $sms = array_map(fn ($row) => new SmsMessage($row->address, $this->smsText($tenant, $message->subject, $message->body, $row->unsubscribe_token), $row->id), array_values($pending));
            try {
                $results = $this->transport->sms($sms);
            } catch (Throwable) {
                $results = [];
                Log::warning('Community SMS outcome unknown.', ['message_id' => $message->id]);
            }
            foreach ($pending as $id => $delivery) {
                $result = $results[$id] ?? SmsResult::unknown('No provider outcome.');
                $this->budget->finish($tenant, $delivery->sms_reservation_id, $result);
                $delivery->update(['status' => $result->accepted ? 'accepted' : ($result->uncertain ? 'unknown' : 'failed'), 'reason' => $result->accepted ? null : ($result->uncertain ? 'outcome_unknown' : 'provider_refused'), 'provider_reference' => $result->providerReference]);
            }
        }
        $this->finishProcessing($message->fresh());
    }

    public function recoverInterruptedClaims(): void
    {
        CommunityMessageDelivery::query()->where('status', 'claimed')->where('claimed_at', '<', now()->subMinutes(2))
            ->lazyById(100)->each(function (CommunityMessageDelivery $candidate): void {
                $tenant = Tenant::query()->find($candidate->tenant_id);
                if ($tenant === null) {
                    return;
                }
                $allowance = $candidate->channel === 'email' ? $this->email : $this->sms;
                $allowance->withLock($tenant, function () use ($tenant, $candidate): void {
                    $row = CommunityMessageDelivery::query()->lockForUpdate()->findOrFail($candidate->id);
                    if ($row->status !== 'claimed' || $row->claimed_at->greaterThanOrEqualTo(now()->subMinutes(2))) {
                        return;
                    }
                    if ($row->sms_reservation_id !== null) {
                        $reservation = \App\Models\CommunitySmsReservation::query()->where('tenant_id', $tenant->id)->findOrFail($row->sms_reservation_id);
                        if ($reservation->status === 'accepted') {
                            $row->update(['status' => 'accepted', 'reason' => null, 'provider_reference' => $reservation->provider_reference]);

                            return;
                        }
                        if ($reservation->status === 'refused') {
                            $row->update(['status' => 'failed', 'reason' => 'provider_refused']);

                            return;
                        }
                        $this->budget->finish($tenant, $reservation->id, SmsResult::unknown('Interrupted worker.'));
                    }
                    $row->update(['status' => 'unknown', 'reason' => 'worker_outcome_unknown']);
                });
            });
    }

    public function authorize(Tenant $tenant, User $actor): void
    {
        abort_unless($tenant->communityEnabled() && $tenant->featureEnabled('community_messages'), 404);
        abort_unless($tenant->status === TenantStatusEnum::ACTIVE && ($actor->tenant_id === $tenant->id || $tenant->users()->where('users.id', $actor->id)->exists()), 403);
        $team = getPermissionsTeamId();
        try {
            setPermissionsTeamId($tenant->id);
            Gate::forUser($actor->fresh())->authorize('send community messages');
        } finally {
            setPermissionsTeamId($team);
        }
    }

    public function finishProcessing(CommunityMessage $message): void
    {
        if (! $message->selection_complete || ! in_array($message->status, ['sending', 'partial'], true) || $message->deliveries()->whereIn('status', ['queued', 'claimed'])->exists()) {
            return;
        }
        $partial = $message->deliveries()->whereIn('status', ['failed', 'unknown'])->exists();
        $message->update(['status' => $partial ? 'partial' : 'completed', 'finished_at' => now()]);
    }

    private function claim(Tenant $tenant, User $actor, CommunityMessage $message, string $id, string $channel): ?CommunityMessageDelivery
    {
        $allowance = $channel === 'email' ? $this->email : $this->sms;

        return $allowance->withLock($tenant, function () use ($tenant, $actor, $message, $id, $channel): ?CommunityMessageDelivery {
            $delivery = CommunityMessageDelivery::query()->where('tenant_id', $tenant->id)->where('message_id', $message->id)->where('channel', $channel)->lockForUpdate()->find($id);
            if ($delivery === null || $delivery->status !== 'queued') {
                return null;
            }
            if (! $this->stillAllowed($tenant, $actor, $message->fresh()) || ! $this->eligible($tenant, $message, $delivery)) {
                $delivery->update(['status' => 'skipped', 'reason' => 'no_longer_eligible']);

                return null;
            }
            if ($channel === 'sms' && (! TenantNotificationSetting::forTenant($tenant->id)->sms_enabled || ! $this->gateway->isConfigured())) {
                $delivery->update(['status' => 'skipped', 'reason' => 'sms_unavailable']);

                return null;
            }
            $reservation = $channel === 'sms' ? $this->budget->reserve($tenant, 'community_message', $delivery->id, CarbonImmutable::now()) : $this->email->reserve($tenant, 1);
            if ($reservation === null) {
                $delivery->update(['status' => 'skipped', 'reason' => 'no_credit_or_monthly_limit']);

                return null;
            }
            $delivery->update(['status' => 'claimed', 'claimed_at' => now(),
                'sms_reservation_id' => $channel === 'sms' ? $reservation->id : null,
                'credit_receipt_id' => $channel === 'sms' ? $reservation->credit_receipt_id : $reservation->id,
                'unsubscribe_token' => $this->preferences->token($tenant, $channel, $delivery->address)]);

            return $delivery;
        });
    }

    private function eligible(Tenant $tenant, CommunityMessage $message, CommunityMessageDelivery $delivery): bool
    {
        return $this->preferences->allowsMarketing($tenant, $delivery->channel, $delivery->address)
            && $this->members->contains($tenant, $message->audience + ['as_of' => $message->started_at], $delivery->channel, $delivery->address);
    }

    private function refundUnsubmitted(Tenant $tenant, CommunityMessageDelivery $delivery): void
    {
        if ($delivery->channel === 'sms') {
            $this->budget->finish($tenant, $delivery->sms_reservation_id, SmsResult::refused('Confirmed not submitted.'));
        } else {
            $this->email->refund($tenant, $delivery->credit_receipt_id);
        }
        $delivery->update(['status' => 'skipped', 'reason' => 'no_longer_eligible']);
    }

    private function stillAllowed(?Tenant $tenant, ?User $actor, CommunityMessage $message): bool
    {
        try {
            if ($message->status === 'cancelled') {
                return false;
            }
            if ($tenant === null || $actor === null) {
                $message->update(['status' => 'paused', 'pause_reason' => 'actor_or_organisation_unavailable']);

                return false;
            }
            $this->authorize($tenant, $actor);
            $this->checkEntitlements($tenant, ['audience' => $message->audience, 'scheduled_at' => $message->scheduled_at]);
            $this->audiences->assertValid($tenant, $message->audience, 'email', $actor);

            return true;
        } catch (AuthorizationException|ValidationException|\Symfony\Component\HttpKernel\Exception\HttpException) {
            $message->update(['status' => 'paused', 'pause_reason' => 'permission_plan_or_rollout_changed']);

            return false;
        }
    }

    /** @param array<string, mixed> $input */
    private function checkEntitlements(Tenant $tenant, array $input): void
    {
        if (! empty($input['scheduled_at']) && ! $this->entitlements->canSchedule($tenant)) {
            throw ValidationException::withMessages(['scheduled_at' => 'Scheduling requires Starter or above.']);
        }
        if (($input['audience']['kind'] ?? '') === 'groups' && ! $this->entitlements->canMessageGroups($tenant)) {
            throw ValidationException::withMessages(['audience' => 'Sending to groups requires Growth or above. Groups can still be managed on every plan.']);
        }
    }

    private function schedule(Tenant $tenant, ?string $value): ?CarbonImmutable
    {
        if (blank($value)) {
            return null;
        }
        try {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?[+-]\d{2}:\d{2}$/D', $value)) {
                throw new InvalidArgumentException;
            }
            $date = CarbonImmutable::parse($value);
            $local = $date->setTimezone(CommunitySettings::for($tenant)->timezone);
            if (! $date->isFuture() || $local->format('Y-m-d\TH:iP') !== $date->format('Y-m-d\TH:iP')) {
                throw new InvalidArgumentException;
            }

            return $date->utc();
        } catch (Throwable) {
            throw ValidationException::withMessages(['scheduled_at' => 'Choose a future local time with its correct UTC offset for the organisation timezone.']);
        }
    }

    /** @param array<string, mixed> $preview */
    private function checkCapacity(Tenant $tenant, array $preview): void
    {
        foreach (['email' => 'email_credits_remaining', 'sms' => 'sms_credits_remaining'] as $channel => $key) {
            if ($preview[$key] !== null && ($preview['counts'][$channel] ?? 0) > $preview[$key]) {
                throw ValidationException::withMessages(['channels' => 'Not enough '.$channel.' credits for this audience.']);
            }
        }
        if (($preview['counts']['sms'] ?? 0) > 0 && ((! TenantNotificationSetting::forTenant($tenant->id)->sms_enabled) || ! $this->gateway->isConfigured()
            || ($preview['sms_monthly_remaining'] !== null && $preview['counts']['sms'] > $preview['sms_monthly_remaining']))) {
            throw ValidationException::withMessages(['channels' => 'SMS is unavailable or this send exceeds the monthly Community spending limit.']);
        }
    }

    private function smsText(Tenant $tenant, string $subject, string $body, string $token): string
    {
        return $tenant->name.': '.$subject.': '.$body.' Stop: '.$this->unsubscribeUrl($tenant, $token);
    }

    private function unsubscribeUrl(Tenant $tenant, string $token): string
    {
        return route('public.community.preferences.show', ['subdomain' => $tenant->slug, 'token' => $token]);
    }
}
