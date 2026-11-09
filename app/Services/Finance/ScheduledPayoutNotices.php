<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Jobs\DeliverScheduledPayoutNotice;
use App\Mail\ScheduledPayoutNoticeMail;
use App\Models\OrganisationPayoutPolicy;
use App\Models\ScheduledPayoutNotice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class ScheduledPayoutNotices
{
    public function enqueue(OrganisationPayoutPolicy $policy, string $kind, string $context): void
    {
        $ids = array_unique(array_filter([$policy->requested_by, $policy->approved_by]));
        foreach (User::query()->whereIn('id', $ids)->get() as $recipient) {
            $notice = ScheduledPayoutNotice::query()->firstOrCreate(['dedupe_key' => implode(':', [$policy->id, $kind, $context, $recipient->id])],
                ['policy_id' => $policy->id, 'recipient_id' => $recipient->id, 'recipient_email' => $recipient->email, 'kind' => $kind, 'status' => 'queued']);
            if ($notice->wasRecentlyCreated) {
                $notice->getConnection()->afterCommit(function () use ($notice): void {
                    try {
                        DeliverScheduledPayoutNotice::dispatch($notice->id);
                    } catch (Throwable) {
                        Log::warning('Payout notice remains queued for sweep.', ['notice_id' => $notice->id]);
                    }
                });
            }
        }
    }

    public function deliver(string $id): void
    {
        $claimed = (new ScheduledPayoutNotice)->getConnection()->transaction(function () use ($id): ?ScheduledPayoutNotice {
            $notice = ScheduledPayoutNotice::query()->lockForUpdate()->findOrFail($id);
            if ($notice->attempts >= 5 || in_array($notice->status, ['sent', 'superseded', 'failed'], true)
                || ($notice->status === 'sending' && $notice->claimed_at?->gt(now()->subMinutes(10)))
                || ($notice->next_attempt_at !== null && $notice->next_attempt_at->gt(now()))) {
                return null;
            }
            $notice->update(['status' => 'sending', 'claimed_at' => now(), 'attempts' => $notice->attempts + 1]);

            return $notice;
        });
        if ($claimed === null) {
            return;
        }
        $policy = OrganisationPayoutPolicy::query()->findOrFail($claimed->policy_id);
        $actor = User::query()->find($claimed->recipient_id);
        $tenant = Tenant::query()->findOrFail($policy->tenant_id);
        try {
            if ($actor === null || $claimed->recipient_email === null) {
                $claimed->update(['status' => 'superseded']);

                return;
            }
            try {
                app(PayoutPolicies::class)->authoriseTenant($tenant, $actor);
            } catch (Throwable) {
                app(PayoutPolicies::class)->authorisePlatform($actor);
            }
        } catch (Throwable) {
            $claimed->update(['status' => 'superseded']);

            return;
        }
        try {
            Mail::to($claimed->recipient_email)->send(new ScheduledPayoutNoticeMail($claimed->id, $claimed->kind,
                route('tenant.finance.schedules.index', ['subdomain' => $tenant->slug])));
            ScheduledPayoutNotice::query()->whereKey($id)->where('status', 'sending')->update(['status' => 'sent', 'delivered_at' => now()]);
        } catch (Throwable) {
            ScheduledPayoutNotice::query()->whereKey($id)->where('status', 'sending')->update(['status' => $claimed->attempts >= 5 ? 'failed' : 'retry', 'next_attempt_at' => now()->addMinutes(5)]);
            Log::warning('Payout notice delivery needs retry.', ['notice_id' => $id]);
        }
    }
}
