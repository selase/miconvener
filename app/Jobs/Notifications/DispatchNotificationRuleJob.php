<?php

declare(strict_types=1);

namespace App\Jobs\Notifications;

use App\Jobs\Middleware\TenantAwareJob;
use App\Models\EventNotificationRule;
use App\Services\Notifications\AutomatedNotificationDispatcher;
use App\Services\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs one notification rule's whole recipient list in a worker.
 *
 * The gateway sends email with sendNow() so that its error handling, its SENT
 * status and its sent_at timestamp are claims about work actually done. That
 * makes each send a real blocking SMTP round-trip, which is correct but cannot
 * happen inside a web request: a rule aimed at a few hundred attendees would
 * run for minutes and exceed the request timeout, having already mailed some of
 * them. The loop belongs here, where taking minutes is fine.
 */
final class DispatchNotificationRuleJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Captured at construction rather than by the TenantAware trait.
     *
     * That trait captures the tenant in __sleep(), but SerializesModels defines
     * __serialize(), and PHP calls __serialize() in preference to __sleep() —
     * so the trait's capture never runs on a queued job and tenantId would
     * arrive null. TenantAwareJob middleware reads this property, so setting it
     * here in the request, where the context definitely exists, is what makes
     * the worker restore the right tenant.
     */
    public ?string $tenantId;

    public function __construct(public EventNotificationRule $rule)
    {
        $this->tenantId = app(TenantContext::class)->activeTenantId() ?? $rule->tenant_id;
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new TenantAwareJob];
    }

    public function handle(AutomatedNotificationDispatcher $dispatcher): void
    {
        $dispatcher->dispatchRule($this->rule);
    }
}
