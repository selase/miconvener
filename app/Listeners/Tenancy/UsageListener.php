<?php

declare(strict_types=1);

namespace App\Listeners\Tenancy;

use App\Models\Tenant;
use App\Services\Tenancy\UsageService;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;

final readonly class UsageListener
{
    public function __construct(
        private UsageService $usageService
    ) {}

    /**
     * Handle the JobProcessed event.
     */
    public function handleProcessed(JobProcessed $event): void
    {
        $this->recordUsage($event, true);
    }

    /**
     * Handle the JobFailed event.
     */
    public function handleFailed(JobFailed $event): void
    {
        $this->recordUsage($event, false);
    }

    /**
     * Record usage if a tenant ID is present.
     */
    private function recordUsage(\Illuminate\Queue\Events\JobProcessed|\Illuminate\Queue\Events\JobFailed $event, bool $success): void
    {
        $job = $event->job;
        $payload = $job->payload();

        // Try to get tenantId from job instance if it's unserialized
        // Or from the payload (data.command is the serialized job object)
        $command = unserialize($payload['data']['command']);
        $tenantId = $command->tenantId ?? null;

        if ($tenantId) {
            $tenant = Tenant::find($tenantId);
            if ($tenant) {
                // Job duration is harder to get exactly from events
                // but we can estimate or use a wrapper.
                // For now, let's assume 0 if we can't measure it easily here
                // (Optimally we'd use a Job Middleware for exact runtime)
                $runtimeMs = 0;

                $this->usageService->recordJob(
                    $tenant,
                    $command::class,
                    $success,
                    $runtimeMs
                );
            }
        }
    }
}
