<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\OrganisationPayoutPolicy;
use App\Services\Finance\ScheduledPayoutPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class PrepareScheduledPayoutPolicy implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public string $policyId, public ?string $afterEventId = null) {}

    public function handle(ScheduledPayoutPlanner $planner): void
    {
        $policy = OrganisationPayoutPolicy::query()->find($this->policyId);
        if ($policy !== null && $policy->status === 'approved') {
            $planner->prepare($policy, CarbonImmutable::now(), $this->afterEventId);
        }
    }
}
