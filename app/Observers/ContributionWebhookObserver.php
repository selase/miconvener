<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\EventContribution;
use App\Services\Webhooks\WebhookDispatcherService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Reports a contribution to the tenant's webhooks once its money has arrived,
 * whichever path completed it.
 */
final class ContributionWebhookObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly WebhookDispatcherService $webhooks) {}

    public function created(EventContribution $contribution): void
    {
        if ($contribution->status === EventContribution::STATUS_COMPLETED) {
            $this->webhooks->dispatchContributionReceived($contribution);
        }
    }

    public function updated(EventContribution $contribution): void
    {
        if ($contribution->wasChanged('status') && $contribution->status === EventContribution::STATUS_COMPLETED) {
            $this->webhooks->dispatchContributionReceived($contribution);
        }
    }
}
