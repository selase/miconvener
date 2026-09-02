<?php

declare(strict_types=1);

namespace App\Jobs\Billing;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ProcessPaystackWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(public array $payload)
    {
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $event = $this->payload['event'] ?? null;

        if (! $event) {
            return;
        }

        match ($event) {
            'charge.success' => ProcessSuccessfulPaystackCharge::dispatch($this->payload),
            'invoice.payment_failed' => ProcessFailedPaystackCharge::dispatch($this->payload),
            'subscription.disable', 'subscription.not_renew' => ProcessDisabledPaystackSubscription::dispatch($this->payload),
            default => null,
        };
    }
}
