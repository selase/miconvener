<?php

declare(strict_types=1);

namespace App\Services\Giving;

use App\Enum\LedgerCategoryEnum;
use App\Models\CommunitySettings;
use App\Models\Event;
use App\Models\EventContribution;
use App\Models\EventPayout;
use App\Models\GivingContributionLink;
use App\Models\GivingFund;
use App\Models\GivingPaymentAttempt;
use App\Models\LedgerTransaction;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final class GivingContributionBridge
{
    public function usesGiving(Tenant $tenant, CarbonImmutable $initiatedAt): bool
    {
        $boundary = CommunitySettings::for($tenant)->giving_event_cutover_at;

        return $boundary !== null && $initiatedAt->greaterThanOrEqualTo($boundary);
    }

    /** @return array{sources:int,unlinked:int,applied:bool} */
    public function associate(Tenant $tenant, bool $apply): array
    {
        $sources = EventContribution::withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderBy('id')->get();
        $linked = GivingContributionLink::withoutGlobalScopes()->where('tenant_id', $tenant->id)->pluck('event_contribution_id')->all();
        $missing = $sources->reject(fn ($source) => in_array($source->id, $linked, true));
        if ($apply) {
            $tenant->getConnection()->transaction(function () use ($tenant, $missing): void {
                Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
                foreach ($missing as $source) {
                    $fund = $this->fundFor($source->event);
                    GivingContributionLink::withoutGlobalScopes()->firstOrCreate(['event_contribution_id' => $source->id], ['tenant_id' => $tenant->id, 'fund_id' => $fund->id, 'origin' => 'legacy_association', 'category' => 'event', 'associated_at' => now()]);
                }
            });
        }

        return ['sources' => $sources->count(), 'unlinked' => $missing->count(), 'applied' => $apply];
    }

    public function removeLegacyAssociations(Tenant $tenant): int
    {
        return $tenant->getConnection()->transaction(function () use ($tenant): int {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();

            return GivingContributionLink::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('origin', 'legacy_association')->where('category', 'event')->delete();
        });
    }

    public function cutover(Tenant $tenant, ?CarbonImmutable $at): void
    {
        $tenant->getConnection()->transaction(function () use ($tenant, $at): void {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            CommunitySettings::withoutGlobalScopes()->updateOrCreate(['tenant_id' => $tenant->id], ['giving_event_cutover_at' => $at]);
        });
    }

    public function fundFor(Event $event): GivingFund
    {
        return $event->getConnection()->transaction(function () use ($event): GivingFund {
            Tenant::query()->whereKey($event->tenant_id)->lockForUpdate()->firstOrFail();

            return GivingFund::withoutGlobalScopes()->firstOrCreate(['tenant_id' => $event->tenant_id, 'slug' => 'event-'.$event->id], [
                'event_id' => $event->id, 'name' => mb_substr($event->contribution_title ?: $event->name, 0, 160),
                'description' => $event->contribution_description, 'currency' => $event->currency ?: 'GHS',
                'kind' => 'open', 'target' => null, 'minimum_amount' => $event->contribution_min_amount_pesewas ?? 100,
                'state' => 'open', 'visibility' => 'private', 'progress' => 'hidden',
                'audit' => [['action' => 'event_fund_created', 'at' => now()->toIso8601String()]],
            ]);
        });
    }

    /** @param array<string,mixed> $data */
    public function initiate(Event $event, array $data, string $callback, string $provider, CarbonImmutable $initiatedAt): string
    {
        abort_unless(app(GivingRollout::class)->onlineEnabled($event->tenant), 404);
        $amount = GivingAmounts::minor((string) $data['amount']);
        $fund = $this->fundFor($event);
        $input = ['amount_minor' => $amount, 'currency' => $event->currency ?: 'GHS', 'provider' => $provider,
            'payer_email' => $data['contributor_email'] ?? null, 'payer_name' => $data['contributor_name'], 'callback_url' => $callback];

        return app(GivingCheckout::class)->start($fund, $input, app('session.store'), $data['submission_key'] ?? (string) Str::uuid(), null, function () use ($event, $data, $amount, $initiatedAt): EventContribution {
            $source = new EventContribution([
                'tenant_id' => $event->tenant_id, 'event_id' => $event->id, 'contributor_name' => mb_trim((string) $data['contributor_name']),
                'contributor_email' => filled($data['contributor_email'] ?? null) ? mb_trim($data['contributor_email']) : null,
                'contributor_phone' => $data['contributor_phone'] ?? null, 'amount' => $amount, 'currency' => $event->currency ?: 'GHS',
                'status' => 'pending_payment', 'payment_reference' => 'CONTRIB-'.Str::uuid(),
                'tribute_message' => $data['tribute_message'] ?? null, 'is_anonymous' => (bool) ($data['is_anonymous'] ?? false), 'is_approved' => true,
            ]);
            $source->setCreatedAt($initiatedAt);
            $source->save();

            return $source;
        });
    }

    /** @return array{giving_collected:int,giving_count:int,giving_payout_url:string|null} */
    public function eventSummary(Event $event): array
    {
        $ids = GivingContributionLink::withoutGlobalScopes()->where('tenant_id', $event->tenant_id)->where('category', 'giving')->pluck('event_contribution_id');
        $sources = EventContribution::withoutGlobalScopes()->where('tenant_id', $event->tenant_id)->where('event_id', $event->id)->whereIn('id', $ids)->whereIn('status', ['completed', 'refunded'])->get();
        $reductions = (int) \App\Models\GivingAdjustment::withoutGlobalScopes()->where('tenant_id', $event->tenant_id)->whereIn('event_contribution_id', $sources->pluck('id'))->where('status', 'confirmed')->sum('principal');

        return ['giving_collected' => max(0, (int) $sources->sum('amount') - $reductions), 'giving_count' => $sources->count(),
            'giving_payout_url' => $sources->isNotEmpty() ? route('tenant.finance.payouts.index', ['subdomain' => $event->tenant->slug, 'category' => 'giving']) : null];
    }

    /** @return array<string,mixed> */
    public function reconcile(Tenant $tenant): array
    {
        $issues = [];
        $attempts = GivingPaymentAttempt::withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderBy('id')->get();
        $confirmed = $attempts->where('state', 'confirmed');
        foreach ($attempts as $attempt) {
            if (in_array($attempt->state, ['needs_review', 'unknown', 'initialising'], true)) {
                $issues[] = 'Unresolved attempt '.$attempt->id;
            }
            if ($attempt->state !== 'confirmed') {
                continue;
            }
            try {
                $source = app(GivingSources::class)->find($tenant, $attempt->gift_id === null ? 'event_contribution' : 'gift', $attempt->gift_id ?? $attempt->event_contribution_id);
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                $issues[] = 'Missing source or fund '.$attempt->id;

                continue;
            }
            if (empty($attempt->provider_reference)) {
                $issues[] = 'Missing provider reference '.$attempt->id;
            }

            if ($source->fund->id !== $attempt->fund_id || $source->principal !== $attempt->amount || $source->currency !== $attempt->currency || ! in_array($source->model->status, ['completed', 'refunded'], true)) {
                $issues[] = 'Source mismatch '.$attempt->id;
            }
            $posting = LedgerTransaction::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('category', LedgerCategoryEnum::Giving)->where('reference', $attempt->reference)->where('transaction_type', LedgerTransaction::TYPE_GIVING_PAYMENT)->first();
            $needsPosting = $attempt->settlement_mode === Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT || $attempt->commission_amount > 0;
            if ($needsPosting && $posting === null) {
                $issues[] = 'Missing payment posting '.$attempt->id;
            }
        }
        $links = GivingContributionLink::withoutGlobalScopes()->with('fund')->where('tenant_id', $tenant->id)->get();
        foreach ($links as $link) {
            $source = EventContribution::withoutGlobalScopes()->where('tenant_id', $tenant->id)->find($link->event_contribution_id);
            if ($source === null || $link->fund->event_id !== $source->event_id || $link->fund->visibility !== 'private') {
                $issues[] = 'Invalid event association '.$link->id;
            }
            if (($link->category === 'giving') !== ($link->origin === 'checkout')) {
                $issues[] = 'Association category mismatch '.$link->id;
            }
            if ($link->category === 'giving' && $source?->status !== EventContribution::STATUS_FAILED && ! $attempts->contains('event_contribution_id', $link->event_contribution_id)) {
                $issues[] = 'Missing event attempt '.$link->id;
            }
        }
        foreach (LedgerTransaction::withoutGlobalScopes()->with('entries')->where('tenant_id', $tenant->id)->where('category', LedgerCategoryEnum::Giving)->get() as $transaction) {
            if ($transaction->event_id !== null || $transaction->entries->where('direction', 'debit')->sum('amount') !== $transaction->entries->where('direction', 'credit')->sum('amount')) {
                $issues[] = 'Unbalanced Giving posting '.$transaction->id;
            }
        }
        foreach (EventPayout::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('category', LedgerCategoryEnum::Giving)->where('status', EventPayout::STATUS_PAID)->get() as $payout) {
            if (! LedgerTransaction::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('category', LedgerCategoryEnum::Giving)->where('transaction_type', LedgerTransaction::TYPE_PAYOUT)->where('reference', $payout->provider_reference)->exists()) {
                $issues[] = 'Missing payout posting '.$payout->id;
            }
        }
        $providerKeys = $confirmed->map(fn ($row) => $row->account_identity.'|'.$row->provider_reference)->all();
        if (count($providerKeys) !== count(array_unique($providerKeys))) {
            $issues[] = 'Duplicate confirmed provider reference';
        }
        foreach (\App\Models\GivingAdjustment::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('kind', 'refund')->get() as $adjustment) {
            if (in_array($adjustment->status, ['requested', 'processing', 'unknown'], true)) {
                $issues[] = 'Unresolved refund '.$adjustment->id;

                continue;
            }
            if ($adjustment->status !== 'confirmed') {
                continue;
            }
            $attempt = $confirmed->first(fn ($row) => $adjustment->gift_id !== null ? $row->gift_id === $adjustment->gift_id : $row->event_contribution_id === $adjustment->event_contribution_id);
            if ($attempt === null) {
                $issues[] = 'Refund missing confirmed attempt '.$adjustment->id;

                continue;
            }
            $needsPosting = $attempt->settlement_mode === Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT || $adjustment->commission_reversal > 0 || $adjustment->commission_returned > 0;
            if ($needsPosting && ! LedgerTransaction::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('category', LedgerCategoryEnum::Giving)->where('transaction_type', LedgerTransaction::TYPE_REFUND)->where('reference', 'giving_refund_'.$adjustment->id)->exists()) {
                $issues[] = 'Missing refund posting '.$adjustment->id;
            }
        }
        $sourceKeys = $confirmed->map(fn ($attempt) => ($attempt->gift_id === null ? 'event_contribution:' : 'gift:').($attempt->gift_id ?? $attempt->event_contribution_id))->all();
        if (count($sourceKeys) !== count(array_unique($sourceKeys))) {
            $issues[] = 'Duplicate confirmed source';
        }

        $collections = app(GivingReport::class)->summary($tenant, CarbonImmutable::parse('1970-01-01'), CarbonImmutable::now()->endOfDay());
        foreach ($collections as $currency => $totals) {
            if ($totals['undated_legacy_refunds'] > 0) {
                $issues[] = 'Undated legacy refund requires reconciliation: '.$currency;
            }
        }

        return ['tenant' => $tenant->slug, 'confirmed_attempts' => $confirmed->count(), 'associated_events' => $links->count(), 'source_keys_digest' => hash('sha256', implode('|', $sourceKeys)),
            'provider_references_digest' => hash('sha256', $confirmed->pluck('provider_reference')->implode('|')),
            'collections' => $collections,
            'discrepancies' => array_values(array_unique($issues))];
    }
}
