<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CommunityMessage;
use App\Models\Tenant;
use App\Services\Community\CommunityMessages;
use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class DeliverCommunityMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public int $backoff = 10;

    /** @param list<string> $deliveryIds */
    public function __construct(public string $messageId, public array $deliveryIds, public string $channel) {}

    public function handle(CommunityMessages $service): void
    {
        $message = CommunityMessage::withoutGlobalScopes()->find($this->messageId);
        $tenant = $message === null ? null : Tenant::query()->find($message->tenant_id);
        if ($tenant === null) {
            return;
        }
        $context = app(TenantContext::class);
        $previous = $context->getTenant();
        try {
            $context->setTenant($tenant);
            $service->deliver($this->messageId, $this->deliveryIds, $this->channel);
        } finally {
            $previous === null ? $context->clear() : $context->setTenant($previous);
        }
    }
}
