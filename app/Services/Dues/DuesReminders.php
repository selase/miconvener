<?php

declare(strict_types=1);

namespace App\Services\Dues;

use App\Contracts\SmsGateway;
use App\Enum\DuesChargeStatusEnum;
use App\Enum\TenantStatusEnum;
use App\Mail\DuesReminder as ReminderMail;
use App\Models\CommunitySettings;
use App\Models\DuesCharge;
use App\Models\DuesReminder;
use App\Models\Member;
use App\Models\Tenant;
use App\Models\TenantNotificationSetting;
use App\Models\User;
use App\Scopes\TenantScope;
use App\Services\Notifications\NotificationGatewayService;
use App\Services\Sms\SmsAllowance;
use App\Services\Sms\SmsMessage;
use App\Services\Sms\SmsResult;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Throwable;

final class DuesReminders
{
    public const int SMS_BATCH_SIZE = 50;

    public function __construct(private readonly SmsAllowance $allowance, private readonly SmsGateway $gateway) {}

    /** @return array{sent: int, skipped: int} */
    public function sendDue(Tenant $tenant, CarbonImmutable $localToday): array
    {
        return $this->send($tenant, $localToday->setTimezone(CommunitySettings::for($tenant)->timezone)->startOfDay(), false);
    }

    /** @return array{sent: int, skipped: int} */
    public function remindArrears(Tenant $tenant, int $userId): array
    {
        $this->authorizeArrears($tenant, $userId);

        return $this->send($tenant, CarbonImmutable::now(CommunitySettings::for($tenant)->timezone)->startOfDay(), true);
    }

    /** @return list<string> */
    public function queuedRecipientIds(Tenant $tenant, ?int $userId, ?string $localDate, ?string $afterMemberId, int $limit): array
    {
        $today = $this->executionDate($tenant, $userId, $localDate);
        if ($today === null) {
            return [];
        }

        return $this->members($tenant, $today, $userId !== null)->withoutEagerLoads()
            ->when($afterMemberId !== null, fn (Builder $query) => $query->where('id', '>', $afterMemberId))
            ->orderBy('id')->limit($limit)->pluck('id')->all();
    }

    /**
     * Each queued unit resolves current charges/settings before claiming any channel.
     *
     * @param  list<string>  $memberIds
     * @return array{sent: int, skipped: int}
     */
    public function deliverUnit(Tenant $tenant, array $memberIds, string $channel, ?int $userId, ?string $localDate): array
    {
        if (! in_array($channel, ['email', 'sms'], true) || count($memberIds) > ($channel === 'email' ? 1 : self::SMS_BATCH_SIZE)) {
            throw new InvalidArgumentException('Reminder delivery units must be bounded by channel.');
        }
        $today = $this->executionDate($tenant, $userId, $localDate);

        return $today === null ? ['sent' => 0, 'skipped' => 0] : $this->send($tenant, $today, $userId !== null, $memberIds, $channel);
    }

    public function authorizeArrears(Tenant $tenant, int $userId): void
    {
        $user = User::query()->findOrFail($userId);
        abort_unless($user->tenant_id === $tenant->id || $tenant->users()->where('users.id', $userId)->exists(), 403);
        $team = getPermissionsTeamId();
        try {
            setPermissionsTeamId($tenant->id);
            Gate::forUser($user)->authorize('manage members');
            Gate::forUser($user)->authorize('read finance');
        } finally {
            setPermissionsTeamId($team);
        }
    }

    /**
     * Estimates only: audience, availability and daily guards are checked again at execution.
     *
     * @return array{members: int, sms_credits_needed: int, sms_credits_remaining: int|null, sms_monthly_remaining: int|null, reminders_enabled: bool}
     */
    public function previewArrears(Tenant $tenant, int $userId): array
    {
        $this->authorizeArrears($tenant, $userId);
        $settings = CommunitySettings::for($tenant);
        $today = CarbonImmutable::now($settings->timezone)->startOfDay();
        $members = $this->members($tenant, $today, true);
        $sms = (clone $members)->whereNotNull('phone')->where('phone', '!=', '')
            ->whereNotIn('id', DuesReminder::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('channel', 'sms')->whereDate('sent_for_date', $today)->select('member_id'))->count();
        $enabled = $settings->reminders_enabled && $this->smsEnabled($tenant) && $this->gateway->isConfigured();

        return [
            'members' => $members->count(),
            'sms_credits_needed' => $enabled ? $sms : 0,
            'sms_credits_remaining' => $this->allowance->remaining($tenant),
            'sms_monthly_remaining' => $settings->sms_monthly_limit === null ? null : max(0, $settings->sms_monthly_limit - $this->monthlySmsCount($tenant, $today)),
            'reminders_enabled' => $settings->reminders_enabled,
        ];
    }

    private function executionDate(Tenant $tenant, ?int $userId, ?string $localDate): ?CarbonImmutable
    {
        if ($userId !== null) {
            $this->authorizeArrears($tenant, $userId);
        }
        $settings = CommunitySettings::for($tenant);
        if (! $tenant->communityEnabled() || $tenant->status !== TenantStatusEnum::ACTIVE || ! $settings->reminders_enabled) {
            return null;
        }
        $today = CarbonImmutable::now($settings->timezone)->startOfDay();

        return $userId === null && $localDate !== null && $localDate !== $today->toDateString() ? null : $today;
    }

    /** @return Builder<Member> */
    private function members(Tenant $tenant, CarbonImmutable $today, bool $arrears): Builder
    {
        return Member::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)
            ->whereHas('charges', fn (Builder $query) => $query->withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->where('status', DuesChargeStatusEnum::Open)->when($arrears, fn (Builder $query) => $query->whereDate('due_on', '<', $today->toDateString())))
            ->with(['charges' => fn ($query) => $query->withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->open()->orderBy('due_on'),
                'charges.membershipType' => fn ($query) => $query->withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)]);
    }

    /**
     * @param  list<string>|null  $memberIds
     * @return array{sent: int, skipped: int}
     */
    private function send(Tenant $tenant, CarbonImmutable $today, bool $arrears, ?array $memberIds = null, ?string $channel = null): array
    {
        $counts = ['sent' => 0, 'skipped' => 0];
        if (! $tenant->communityEnabled() || $tenant->status !== TenantStatusEnum::ACTIVE || ! CommunitySettings::for($tenant)->reminders_enabled) {
            return $counts;
        }
        $this->members($tenant, $today, $arrears)->when($memberIds !== null, fn (Builder $query) => $query->whereIn('id', $memberIds))
            ->chunkById(self::SMS_BATCH_SIZE, function (Collection $members) use ($tenant, $today, $arrears, $channel, &$counts): void {
                $messages = [];
                $rows = [];
                $emails = [];
                foreach ($members as $member) {
                    if ($member->charges->isEmpty()) {
                        continue;
                    }
                    $member->setRelation('tenant', $tenant);
                    $settings = CommunitySettings::for($tenant);
                    if (! $settings->reminders_enabled) {
                        break;
                    }
                    $step = $arrears ? 'manual' : $this->step($member->charges, $settings, $today);
                    if ($step === null) {
                        continue;
                    }
                    if ($channel !== 'sms' && filled($member->email)) {
                        $emails[] = [$member, $step];
                    }
                    if ($channel !== 'email' && filled($member->phone)) {
                        $text = $this->smsText($member);
                        $row = $this->reserve($tenant, $member, $today, $step, 'sms', $text);
                        if ($row?->status === 'reserved') {
                            $messages[] = new SmsMessage($member->phone, $text, $row->id);
                            $rows[$row->id] = $row;
                        } else {
                            $counts['skipped']++;
                        }
                    }
                }
                try {
                    $results = $messages === [] ? [] : $this->gateway->send($messages);
                } catch (Throwable $exception) {
                    report($exception);
                    $results = [];
                }
                foreach ($rows as $id => $row) {
                    $result = $results[$id] ?? SmsResult::unknown('No result returned.');
                    $this->finishSms($tenant, $row, $result);
                    $counts[$result->accepted ? 'sent' : 'skipped']++;
                }
                foreach ($emails as [$member, $step]) {
                    $row = $this->reserve($tenant, $member, $today, $step, 'email');
                    if ($row?->status === 'reserved') {
                        try {
                            Mail::to($member->email)->send(new ReminderMail($member, $member->charges));
                            $row->update(['status' => 'sent']);
                            $counts['sent']++;
                        } catch (Throwable $exception) {
                            $row->update(['reason' => 'outcome_unknown']);
                            report($exception);
                            $counts['skipped']++;
                        }
                    } else {
                        $counts['skipped']++;
                    }
                }

            });

        return $counts;
    }

    /** @param Collection<int, DuesCharge> $charges */
    private function step(Collection $charges, CommunitySettings $settings, CarbonImmutable $today): ?string
    {
        foreach ($charges as $charge) {
            if (in_array($charge->membershipType?->period?->value, ['daily', 'weekly'], true)) {
                if ($settings->frequent_digest === 'daily' || $today->dayOfWeek === $settings->week_starts_on) {
                    return 'digest';
                }

                continue;
            }
            foreach ($settings->reminder_offsets as $step => $offset) {
                $due = CarbonImmutable::parse($charge->due_on->toDateString(), $settings->timezone);
                $trigger = $step === 'before' ? $due->subDays($offset) : $due->addDays($offset);
                if ($today->isSameDay($trigger)) {
                    return $step;
                }
            }
        }

        return null;
    }

    private function reserve(Tenant $tenant, Member $member, CarbonImmutable $today, string $step, string $channel, string $text = ''): ?DuesReminder
    {
        $callback = function () use ($tenant, $member, $today, $step, $channel, $text): ?DuesReminder {
            if (DuesReminder::withoutGlobalScopes()->where('member_id', $member->id)->where('channel', $channel)->whereDate('sent_for_date', $today->toDateString())->exists()) {
                return null;
            }
            $settings = CommunitySettings::for($tenant);
            $reason = $settings->reminders_enabled ? null : 'reminders_disabled';
            if ($reason === null && $channel === 'sms') {
                $reason = match (true) {
                    ! $this->smsEnabled($tenant) => 'sms_disabled',
                    ! $this->gateway->isConfigured() => 'not_configured',
                    mb_strlen($text) > NotificationGatewayService::SMS_MAX_LENGTH => 'message_too_long',
                    $settings->sms_monthly_limit !== null && $this->monthlySmsCount($tenant, $today) >= $settings->sms_monthly_limit => 'monthly_limit',
                    ! $this->allowance->canSend($tenant) => 'no_credit',
                    default => null,
                };
            }
            $receipt = $reason === null && $channel === 'sms' ? $this->allowance->reserve($tenant, 1) : null;
            if ($channel === 'sms' && $reason === null && $receipt === null) {
                $reason = 'no_credit';
            }

            return DuesReminder::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id, 'member_id' => $member->id, 'step' => $step,
                'channel' => $channel, 'charge_ids' => $member->charges->modelKeys(),
                'sent_for_date' => $today->toDateString(), 'status' => $reason === null ? 'reserved' : 'skipped',
                'reason' => $reason, 'credit_receipt_id' => $receipt?->id,
            ]);
        };
        if ($channel === 'sms') {
            return $this->allowance->withLock($tenant, $callback);
        }

        return DB::connection('landlord')->transaction(function () use ($member, $callback): ?DuesReminder {
            DB::connection('landlord')->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['community.reminder.email:'.$member->id]);

            return $callback();
        });
    }

    private function finishSms(Tenant $tenant, DuesReminder $row, SmsResult $result): void
    {
        $this->allowance->withLock($tenant, function () use ($tenant, $row, $result): void {
            $locked = DuesReminder::withoutGlobalScopes()->lockForUpdate()->findOrFail($row->id);
            if ($locked->status !== 'reserved') {
                return;
            }
            if ($result->accepted) {
                $locked->update(['status' => 'sent', 'provider_reference' => $result->providerReference, 'reason' => null]);
            } elseif ($result->uncertain) {
                $locked->update(['reason' => 'outcome_unknown']);
            } else {
                $this->allowance->refund($tenant, $locked->credit_receipt_id);
                $locked->update(['status' => $result->isThrottled() ? 'skipped' : 'failed', 'reason' => $result->isThrottled() ? 'provider_throttled' : 'provider_refused']);
            }
        });
    }

    private function smsEnabled(Tenant $tenant): bool
    {
        return (bool) TenantNotificationSetting::query()->where('tenant_id', $tenant->id)->value('sms_enabled');
    }

    private function monthlySmsCount(Tenant $tenant, CarbonImmutable $today): int
    {
        return DuesReminder::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('channel', 'sms')->whereIn('status', ['reserved', 'sent'])
            ->whereBetween('sent_for_date', [$today->startOfMonth()->toDateString(), $today->endOfMonth()->toDateString()])->count();
    }

    private function smsText(Member $member): string
    {
        $totals = $member->charges->groupBy('currency')->map(fn ($charges, string $currency): string => $currency.' '.number_format((int) $charges->sum('amount') / 100, 2))->implode('; ');
        $oldest = $member->charges->min('due_on')->toDateString();
        $link = route('public.dues.show', ['subdomain' => $member->tenant->slug, 'token' => $member->pay_token]);

        return "{$member->tenant->name}: {$member->name}, {$member->charges->count()} open dues. Total {$totals}. Oldest due {$oldest}. View all charges and pay: {$link}";
    }
}
