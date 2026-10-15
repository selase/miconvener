<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CommunityMessage;
use App\Models\Tenant;
use App\Services\Community\CommunityMessages;
use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class DispatchCommunityMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public int $backoff = 10;

    public function __construct(public string $messageId) {}

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
            $service->dispatchDue($this->messageId);
        } finally {
            $previous === null ? $context->clear() : $context->setTenant($previous);
        }
    }
}
