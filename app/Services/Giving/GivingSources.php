<?php

declare(strict_types=1);

namespace App\Services\Giving;

use App\Models\EventContribution;
use App\Models\GivingContributionLink;
use App\Models\GivingFund;
use App\Models\GivingGift;
use App\Models\Tenant;

final class GivingSources
{
    public function find(Tenant $tenant, string $type, string $id): GivingSource
    {
        return $this->resolve($tenant, $type, $id, false);
    }

    public function lock(Tenant $tenant, string $type, string $id): GivingSource
    {
        return $this->resolve($tenant, $type, $id, true);
    }

    private function resolve(Tenant $tenant, string $type, string $id, bool $lock): GivingSource
    {
        abort_unless(in_array($type, ['gift', 'event_contribution'], true), 404);
        $class = $type === 'gift' ? GivingGift::class : EventContribution::class;
        $query = $class::withoutGlobalScopes()->where('tenant_id', $tenant->id);
        if ($lock) {
            $query->lockForUpdate();
        }
        $model = $query->findOrFail($id);
        $fundId = $model instanceof GivingGift ? $model->fund_id : GivingContributionLink::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('event_contribution_id', $id)->firstOrFail()->fund_id;
        $fund = GivingFund::withoutGlobalScopes()->where('tenant_id', $tenant->id)->findOrFail($fundId);

        return new GivingSource($type, $id, $fund, $model->amount, $model->currency, $model);
    }
}
