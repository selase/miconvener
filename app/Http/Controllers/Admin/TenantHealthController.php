<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventNotificationLog;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantAddon;
use App\Models\TenantSendingDomain;
use App\Models\Transaction;
use App\Services\Billing\BillingNotifier;
use App\Services\Billing\EmailAllowance;
use App\Services\Sms\SmsAllowance;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Customer health: one row per organisation built from real signals (billing
 * state, last payment, messages that failed this week, domain status), and a
 * customer view that puts everything support needs about one organisation on
 * one page.
 *
 * It replaced a page whose database and storage columns were hard-coded to
 * "Healthy" and which ran a live DNS lookup per organisation on every load.
 */
final class TenantHealthController extends Controller
{
    public function index(): View
    {
        $this->authorize('access-superadmin-dashboard');

        $tenants = Tenant::query()->with('package')->orderBy('name')->get();
        $ids = $tenants->pluck('id');

        $subscriptions = $this->currentSubscriptions($ids);
        $lastPayments = Transaction::query()->successful()->whereIn('tenant_id', $ids)
            ->groupBy('tenant_id')->selectRaw('tenant_id, max(created_at) as last_paid_at')->pluck('last_paid_at', 'tenant_id');
        $failures = EventNotificationLog::withoutGlobalScopes()->whereIn('tenant_id', $ids)
            ->where('status', EventNotificationLog::STATUS_FAILED)->where('created_at', '>=', now()->subDays(7))
            ->groupBy('tenant_id')->selectRaw('tenant_id, count(*) as failed')->pluck('failed', 'tenant_id');
        $sendingDomains = TenantSendingDomain::query()->whereIn('tenant_id', $ids)->pluck('status', 'tenant_id');

        $rows = $tenants->map(function (Tenant $tenant) use ($subscriptions, $lastPayments, $failures, $sendingDomains): array {
            $billing = $this->billingState($tenant, $subscriptions->get($tenant->id));
            $failed = (int) ($failures[$tenant->id] ?? 0);
            $sending = $sendingDomains[$tenant->id] ?? null;
            $customDomainProblem = filled($tenant->custom_domain) && $tenant->custom_domain_status !== 'active';

            return [
                'tenant' => $tenant,
                'plan' => $tenant->package->name ?? 'Free',
                'billing' => $billing,
                'last_paid' => isset($lastPayments[$tenant->id]) ? BillingNotifier::date($lastPayments[$tenant->id]) : null,
                'failed_messages' => $failed,
                'sending_domain' => $sending,
                'custom_domain' => $tenant->custom_domain,
                'custom_domain_status' => $tenant->custom_domain_status,
                'needs_attention' => $billing === 'Past due' || $failed > 0 || $sending === TenantSendingDomain::STATUS_FAILED || $customDomainProblem,
            ];
        })->sortByDesc('needs_attention')->values();

        return view('admin.health.tenants', [
            'rows' => $rows,
            'attentionCount' => $rows->where('needs_attention', true)->count(),
        ]);
    }

    public function show(Tenant $tenant, SmsAllowance $sms, EmailAllowance $email): View
    {
        $this->authorize('access-superadmin-dashboard');

        $tenant->load(['package', 'sendingDomain', 'shop']);
        $subscription = $this->currentSubscriptions(collect([$tenant->id]))->get($tenant->id);
        $events = Event::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id);
        $messages = EventNotificationLog::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('created_at', '>=', now()->subDays(7))
            ->groupBy('channel', 'status')->selectRaw('channel, status, count(*) as total')->get();
        $owner = $tenant->users()->orderBy('tenant_user.created_at')->first();

        return view('admin.tenants.customer', [
            'tenant' => $tenant,
            'owner' => $owner,
            'teamSize' => $tenant->users()->count(),
            'billing' => $this->billingState($tenant, $subscription),
            'subscription' => $subscription,
            'agreedPrice' => $tenant->package ? $tenant->agreedPriceFor($tenant->package) : null,
            'lastPayment' => Transaction::query()->successful()->where('tenant_id', $tenant->id)->latest()->first(),
            'smsRemaining' => $sms->remaining($tenant),
            'emailRemaining' => $email->remaining($tenant),
            'messages' => $messages,
            'activeAddons' => TenantAddon::query()->where('tenant_id', $tenant->id)->active()->count(),
            'eventCount' => (clone $events)->count(),
            'nextEvent' => (clone $events)->where('starts_at', '>=', now()->startOfDay())->orderBy('starts_at')->first(),
            'lastEvent' => (clone $events)->where('starts_at', '<', now()->startOfDay())->orderByDesc('starts_at')->first(),
        ]);
    }

    /**
     * @param  Collection<int, mixed>  $tenantIds
     * @return Collection<string, Subscription>
     */
    private function currentSubscriptions(Collection $tenantIds): Collection
    {
        return Subscription::query()
            ->whereIn('tenant_id', $tenantIds)
            ->whereIn('provider_status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE])
            ->orderBy('id')
            ->get()
            ->keyBy('tenant_id');
    }

    private function billingState(Tenant $tenant, ?Subscription $subscription): string
    {
        $package = $tenant->package;

        return match (true) {
            ! $package instanceof Package || $package->isFree() => 'Free plan',
            (bool) $tenant->billing_complimentary => 'Complimentary',
            $subscription?->isPastDue() === true => 'Past due',
            $subscription !== null => 'Paid',
            default => 'Not paid yet',
        };
    }
}
