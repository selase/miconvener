<?php

declare(strict_types=1);

namespace App\Services\Giving;

use App\Jobs\DeliverGivingReminder;
use App\Mail\GivingReminder as ReminderMail;
use App\Models\CommunitySettings;
use App\Models\GivingFund;
use App\Models\GivingReminder;
use App\Services\Billing\EmailAllowance;
use App\Services\Community\MessagingPreferences;
use App\Services\Events\PlatformAttendeeVerification;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class GivingReminders
{
    public function subscribe(GivingFund $fund, Session $session, string $cadence, ?int $anchor, bool $consent): GivingReminder
    {
        $email = PlatformAttendeeVerification::getVerifiedEmail($session);
        abort_unless($email !== null, 401);
        abort_unless($this->accepting($fund), 404);
        if (! $consent || ! in_array($cadence, ['weekly', 'monthly'], true) || ($anchor !== null && ($anchor < 1 || $anchor > 31))) {
            throw ValidationException::withMessages(['consent' => 'Choose a schedule and explicitly agree to email giving reminders.']);
        }
        $preferences = app(MessagingPreferences::class);
        if (! $preferences->allowsMarketing($fund->tenant, 'email', $email)) {
            throw ValidationException::withMessages(['consent' => 'Organisation marketing emails are switched off in your notification preferences. Enable them there before subscribing.']);
        }

        return $fund->getConnection()->transaction(function () use ($fund, $email, $cadence, $anchor, $preferences): GivingReminder {
            $fund = GivingFund::withoutGlobalScopes()->lockForUpdate()->findOrFail($fund->id);
            abort_unless($this->accepting($fund), 404);
            $settings = CommunitySettings::for($fund->tenant);
            $row = GivingReminder::withoutGlobalScopes()->firstOrNew(['fund_id' => $fund->id, 'email_hash' => $preferences->hash('email', $email)]);
            $row->fill(['tenant_id' => $fund->tenant_id, 'email' => $email, 'consented_at' => now(), 'consent_key' => (string) Str::uuid(), 'cadence' => $cadence,
                'anchor_day' => $cadence === 'weekly' ? $settings->week_starts_on : ($anchor ?? 1), 'local_time' => '09:00', 'timezone' => $settings->timezone,
                'disabled_at' => null, 'last_delivery_key' => null, 'delivery_state' => 'idle', 'credit_receipt_id' => null]);
            $row->id ??= (string) Str::uuid();
            $row->next_due_at = $this->nextDue($row, CarbonImmutable::now());
            $row->unsubscribe_hash = hash('sha256', $this->unsubscribeToken($row));
            $row->save();

            return $row;
        });
    }

    public function nextDue(GivingReminder $row, CarbonImmutable $after): CarbonImmutable
    {
        $local = $after->setTimezone($row->timezone);
        if ($row->cadence === 'weekly') {
            $candidate = $local->startOfDay()->addDays(($row->anchor_day - $local->dayOfWeek + 7) % 7)->setTimeFromTimeString($row->local_time);
            if ($candidate <= $local) {
                $candidate = $candidate->addWeek();
            }
        } else {
            $month = $local->startOfMonth();
            $candidate = $month->setDay(min($row->anchor_day, $month->daysInMonth))->setTimeFromTimeString($row->local_time);
            if ($candidate <= $local) {
                $month = $month->addMonthNoOverflow()->startOfMonth();
                $candidate = $month->setDay(min($row->anchor_day, $month->daysInMonth))->setTimeFromTimeString($row->local_time);
            }
        }

        return $candidate->utc();
    }

    public function unsubscribeToken(GivingReminder $row): string
    {
        return hash_hmac('sha256', 'giving-reminder:'.$row->id.':'.$row->consent_key, (string) config('app.key'));
    }

    public function forToken(string $tenantId, string $id, string $token): GivingReminder
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $token) === 1, 404);
        $row = GivingReminder::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail($id);
        abort_unless(hash_equals($row->unsubscribe_hash, hash('sha256', $token)), 404);

        return $row;
    }

    public function sendDue(): void
    {
        GivingReminder::withoutGlobalScopes()->whereNull('disabled_at')->where('next_due_at', '<=', now())->orderBy('next_due_at')->limit(100)->get()->each(function (GivingReminder $candidate): void {
            $key = $candidate->getConnection()->transaction(function () use ($candidate): ?string {
                $row = GivingReminder::withoutGlobalScopes()->lockForUpdate()->findOrFail($candidate->id);
                if ($row->disabled_at !== null || $row->next_due_at->isFuture()) {
                    return null;
                }
                $key = $row->consent_key.':'.$row->next_due_at->timestamp;
                $allowed = $this->allowed($row);
                $row->update(['last_delivery_key' => $key, 'next_due_at' => $this->nextDue($row, CarbonImmutable::now()), 'delivery_state' => $allowed ? 'queued' : 'stopped', 'credit_receipt_id' => null]);

                return $allowed ? $key : null;
            });
            if ($key !== null) {
                $candidate->getConnection()->afterCommit(fn () => DeliverGivingReminder::dispatch($candidate->id, $key));
            }
        });
        GivingReminder::withoutGlobalScopes()->whereNull('disabled_at')->where('delivery_state', 'queued')->whereNotNull('last_delivery_key')->limit(100)->get()->each(fn (GivingReminder $row) => DeliverGivingReminder::dispatch($row->id, (string) $row->last_delivery_key));
    }

    public function deliver(string $id, string $key): void
    {
        $row = GivingReminder::withoutGlobalScopes()->find($id);
        if ($row === null) {
            return;
        }
        $claimed = $row->getConnection()->transaction(function () use ($row, $key): bool {
            $current = GivingReminder::withoutGlobalScopes()->lockForUpdate()->findOrFail($row->id);
            if ($current->last_delivery_key !== $key || $current->delivery_state !== 'queued') {
                return false;
            }
            if (! $this->allowed($current)) {
                $current->update(['delivery_state' => 'stopped']);

                return false;
            }
            $credit = app(EmailAllowance::class)->reserve($current->tenant, 1);
            $current->update(['delivery_state' => $credit === null ? 'no_credit' : 'sending', 'credit_receipt_id' => $credit?->id]);

            return $credit !== null;
        });
        if (! $claimed) {
            return;
        }
        $row->refresh();
        try {
            Mail::to($row->email)->send(new ReminderMail($row, $this->unsubscribeToken($row)));
            GivingReminder::withoutGlobalScopes()->whereKey($id)->where('last_delivery_key', $key)->update(['delivery_state' => 'sent']);
        } catch (Throwable) {
            GivingReminder::withoutGlobalScopes()->whereKey($id)->where('last_delivery_key', $key)->update(['delivery_state' => 'outcome_unknown']);
        }
    }

    private function allowed(GivingReminder $row): bool
    {
        return $row->disabled_at === null && $this->accepting($row->fund) && app(MessagingPreferences::class)->allowsMarketing($row->tenant, 'email', $row->email);
    }

    private function accepting(GivingFund $fund): bool
    {
        if ($fund->event_id !== null || ! in_array($fund->visibility, ['public', 'unlisted'], true) || ! app(GivingRollout::class)->enabled($fund->tenant)) {
            return false;
        }
        try {
            app(GivingProgress::class)->assertOpen($fund);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }
}
