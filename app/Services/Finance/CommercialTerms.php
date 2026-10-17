<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enum\CommercialStreamEnum;
use App\Models\CommercialTermsChange;
use App\Models\CommercialTermsOverride;
use App\Models\Event;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

final class CommercialTerms
{
    public function for(Tenant $tenant, CommercialStreamEnum $stream): ResolvedTerms
    {
        if ($stream === CommercialStreamEnum::Tickets) {
            $event = new Event;
            $event->setRelation('tenant', $tenant);
            $resolver = app(PlatformFeeResolver::class);

            return new ResolvedTerms($resolver->percentageFor($event), $resolver->capAmountFor($event), $resolver->bearerFor($event),
                $tenant->platform_fee_percentage !== null ? 'override' : ($tenant->package !== null ? 'plan' : 'platform'));
        }

        $override = CommercialTermsOverride::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('stream', $stream)->first();
        if ($override !== null) {
            return new ResolvedTerms((float) $override->rate, $override->cap_amount, 'organizer', 'override');
        }

        $package = $tenant->package;
        $prefix = match ($stream) {
            CommercialStreamEnum::Dues => 'dues',
            CommercialStreamEnum::Giving => 'giving',
            default => 'contributions',
        };
        $freeRate = (float) config($stream === CommercialStreamEnum::Giving ? 'services.giving.free_plan_fee_percentage' : 'services.dues.free_plan_fee_percentage', 2);
        $rate = $package === null
            ? (in_array($stream, [CommercialStreamEnum::Dues, CommercialStreamEnum::Giving], true)
                ? $freeRate
                : (float) config("services.{$prefix}.platform_fee_percentage", 1))
            : ($package->isFree() ? $freeRate : 1.0);

        return new ResolvedTerms($rate, null, 'organizer', $package === null ? 'platform' : 'plan');
    }

    public function commission(ResolvedTerms $terms, int $amount): int
    {
        $basisPoints = (int) round($terms->rate * 100, 0, PHP_ROUND_HALF_UP);
        $commission = intdiv(max(0, $amount) * max(0, $basisPoints) + 5000, 10000);

        return max(0, $terms->capAmount === null ? $commission : min($commission, $terms->capAmount));
    }

    /** Integer cumulative proportional refund, including amounts whose product would overflow. */
    public function refundedCommission(int $originalPrincipal, int $originalCommission, int $cumulativeRefundedPrincipal): int
    {
        if ($originalPrincipal <= 0 || $originalCommission < 0 || $originalCommission > $originalPrincipal || $cumulativeRefundedPrincipal < 0 || $cumulativeRefundedPrincipal > $originalPrincipal) {
            throw new InvalidArgumentException('Invalid original or cumulative refund amount.');
        }
        $quotient = 0;
        $remainder = 0;
        $partQuotient = intdiv($originalCommission, $originalPrincipal);
        $partRemainder = $originalCommission % $originalPrincipal;
        $remaining = $cumulativeRefundedPrincipal;
        while ($remaining > 0) {
            if ($remaining % 2 === 1) {
                $quotient += $partQuotient;
                $remainder += $partRemainder;
                $quotient += intdiv($remainder, $originalPrincipal);
                $remainder %= $originalPrincipal;
            }
            $remaining = intdiv($remaining, 2);
            if ($remaining > 0) {
                $partQuotient *= 2;
                $partRemainder *= 2;
                $partQuotient += intdiv($partRemainder, $originalPrincipal);
                $partRemainder %= $originalPrincipal;
            }
        }

        return min($originalCommission, $quotient + ($remainder >= intdiv($originalPrincipal, 2) + $originalPrincipal % 2 ? 1 : 0));
    }

    public function setOverride(Tenant $tenant, CommercialStreamEnum $stream, ?float $rate, ?int $cap, string $reason, User $by): void
    {
        Gate::forUser($by)->authorize('access-superadmin-dashboard');
        if ($stream === CommercialStreamEnum::Tickets) {
            throw new InvalidArgumentException('Ticket terms are managed through the existing ticket settings.');
        }
        if (($rate !== null && (! is_finite($rate) || $rate < 0 || $rate > 100 || round($rate, 2) !== $rate)) || ($cap !== null && $cap < 0) || mb_trim($reason) === '') {
            throw new InvalidArgumentException('Provide a valid rate, cap and reason.');
        }
        DB::connection('landlord')->transaction(function () use ($tenant, $stream, $rate, $cap, $reason, $by): void {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $query = CommercialTermsOverride::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('stream', $stream);
            $old = $query->first();
            CommercialTermsChange::query()->create([
                'tenant_id' => $tenant->id, 'stream' => $stream, 'old_rate' => $old?->rate, 'new_rate' => $rate,
                'old_cap' => $old?->cap_amount, 'new_cap' => $rate === null ? null : $cap, 'reason' => mb_trim($reason), 'changed_by' => $by->id,
            ]);
            if ($rate === null) {
                $query->delete();
            } else {
                CommercialTermsOverride::query()->withoutGlobalScopes()->updateOrCreate(['tenant_id' => $tenant->id, 'stream' => $stream], [
                    'rate' => $rate, 'cap_amount' => $cap, 'reason' => mb_trim($reason), 'set_by' => $by->id,
                ]);
            }
        });
    }
}
