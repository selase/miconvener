<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Enum\DuesChargeStatusEnum;
use App\Enum\MemberStatusEnum;
use App\Http\Controllers\Controller;
use App\Models\CommunitySettings;
use App\Models\DuesCharge;
use App\Models\Member;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Community landing page: how many members there are, what is owed and
 * what came in this month, with links into People, Money and Payouts.
 */
final class CommunityOverviewController extends Controller
{
    public function index(): Response
    {
        $this->authorize('manage members');
        $tenant = $this->getTenant();

        $charges = DuesCharge::query()->where('tenant_id', $tenant->id);

        return Inertia::render('Tenant/Community/Overview', [
            'reminderSettings' => CommunitySettings::for($tenant)->only(['reminders_enabled', 'reminder_offsets', 'frequent_digest', 'sms_monthly_limit', 'week_starts_on']),
            'counts' => [
                'members' => Member::query()->where('tenant_id', $tenant->id)->count(),
                'activeMembers' => Member::query()->where('tenant_id', $tenant->id)->where('status', MemberStatusEnum::Active)->count(),
                'openCharges' => (clone $charges)->where('status', DuesChargeStatusEnum::Open)->count(),
                'overdueCharges' => (clone $charges)->where('status', DuesChargeStatusEnum::Open)->whereDate('due_on', '<', now()->toDateString())->count(),
            ],
            'collectedThisMonth' => (clone $charges)
                ->where('status', DuesChargeStatusEnum::Paid)
                ->where('payment_method', 'online')
                ->where('paid_at', '>=', now()->startOfMonth())
                ->selectRaw('currency, sum(amount) as amount')
                ->groupBy('currency')
                ->orderBy('currency')
                ->get()
                ->map(fn (DuesCharge $row): array => ['currency' => (string) $row->currency, 'amount' => (int) $row->getAttribute('amount')])
                ->values(),
        ]);
    }

    private function getTenant(): Tenant
    {
        $tenant = app(TenantContext::class)->getTenant();

        if (! $tenant instanceof Tenant) {
            abort(403, 'Tenant context not resolved.');
        }

        return $tenant;
    }
}
