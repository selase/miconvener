<?php

declare(strict_types=1);

namespace App\Services\Giving;

use App\Models\GivingContributionLink;
use App\Models\GivingFund;
use App\Models\GivingGift;
use App\Models\GivingPaymentAttempt;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class GivingFunds
{
    /** @param array<string, mixed> $validated */
    public function save(Tenant $tenant, User $actor, array $validated, ?GivingFund $fund = null): GivingFund
    {
        $this->authorize($tenant, $actor);
        abort_if($fund !== null && $fund->tenant_id !== $tenant->id, 404);

        return $tenant->getConnection()->transaction(function () use ($tenant, $actor, $validated, $fund): GivingFund {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $fund = $fund === null ? new GivingFund(['tenant_id' => $tenant->id, 'created_by' => $actor->id]) : GivingFund::withoutGlobalScopes()->where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($fund->id);
            if ($fund->exists && $this->hasHistory($fund)) {
                foreach (['currency', 'event_id'] as $field) {
                    if (array_key_exists($field, $validated) && $validated[$field] !== $fund->$field) {
                        throw ValidationException::withMessages([$field => 'This fund has history; its currency and event cannot be changed.']);
                    }
                }
            }
            $fund->fill($validated);
            if ($fund->event_id !== null) {
                abort_unless(\App\Models\Event::query()->where('tenant_id', $tenant->id)->whereKey($fund->event_id)->exists(), 404);
                if (GivingFund::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('event_id', $fund->event_id)->where('currency', $fund->currency)->when($fund->exists, fn ($q) => $q->whereKeyNot($fund->id))->exists()) {
                    throw ValidationException::withMessages(['event_id' => 'This event already has a fund in this currency. Use its existing fund.']);
                }
                $fund->visibility = 'private';
            }
            $fund->audit = [...($fund->audit ?? []), ['action' => $fund->exists ? 'updated' : 'created', 'by' => $actor->id, 'at' => now()->toIso8601String()]];
            $fund->save();

            return $fund;
        });
    }

    public function close(GivingFund $fund, User $actor, string $reason): void
    {
        $this->transition($fund, $actor, 'closed', $reason);
    }

    public function reopen(GivingFund $fund, User $actor, string $reason): void
    {
        $this->transition($fund, $actor, 'open', $reason);
    }

    public function archive(GivingFund $fund, User $actor, string $reason): void
    {
        $this->transition($fund, $actor, 'archived', $reason);
    }

    private function transition(GivingFund $fund, User $actor, string $state, string $reason): void
    {
        $this->authorize($fund->tenant, $actor);
        if (mb_trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Provide a reason for this change.']);
        }
        $fund->getConnection()->transaction(function () use ($fund, $actor, $state, $reason): void {
            $locked = GivingFund::withoutGlobalScopes()->where('tenant_id', $fund->tenant_id)->lockForUpdate()->findOrFail($fund->id);
            $locked->update(['state' => $state, 'audit' => [...($locked->audit ?? []), ['action' => $state, 'reason' => $reason, 'by' => $actor->id, 'at' => now()->toIso8601String()]]]);
        });
        $fund->refresh();
    }

    private function authorize(Tenant $tenant, User $actor): void
    {
        abort_unless(app(GivingRollout::class)->enabled($tenant), 404);
        abort_unless($actor->tenant_id === $tenant->id || $tenant->users()->where('users.id', $actor->id)->exists(), 403);
        Gate::forUser($actor)->authorize('create', [GivingFund::class, $tenant]);
    }

    private function hasHistory(GivingFund $fund): bool
    {
        foreach ([GivingGift::class, GivingPaymentAttempt::class, GivingContributionLink::class] as $model) {
            if ($model::withoutGlobalScopes()->where('tenant_id', $fund->tenant_id)->where('fund_id', $fund->id)->exists()) {
                return true;
            }
        }

        return false;
    }
}
