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
use App\Services\Dues\DuesReport;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
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
        $today = CarbonImmutable::now(CommunitySettings::for($tenant)->timezone);
        $canReadFinance = auth()->user()->can('read finance');
        $report = $canReadFinance ? app(DuesReport::class)->summary($tenant, $today->startOfMonth()->utc(), $today->endOfDay()->utc()) : [];

        return Inertia::render('Tenant/Community/Overview', [
            'canReadFinance' => $canReadFinance,
            'joinSettings' => CommunitySettings::for($tenant)->only(['join_mode', 'public_join_enabled', 'public_membership_type_id']),
            'membershipTypes' => \App\Models\MembershipType::query()->where('tenant_id', $tenant->id)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'amount', 'currency', 'period']),
            'reviewCounts' => [
                'claims' => \App\Models\CommunityAccessClaim::query()->where('tenant_id', $tenant->id)->where('status', 'pending')->where('expires_at', '>', now())->count(),
                'joins' => \App\Models\CommunityJoinRequest::query()->where('tenant_id', $tenant->id)->whereIn('status', ['pending', 'review_required'])->count(),
                'reports' => \App\Models\CommunityWrongNumberReport::query()->where('tenant_id', $tenant->id)->where('status', 'pending')->count(),
            ],
            'recentMessages' => $tenant->featureEnabled('community_messages') && auth()->user()->can('send community messages')
                ? \App\Models\CommunityMessage::query()->where('tenant_id', $tenant->id)->when(! $canReadFinance, fn ($query) => $query->where('audience->kind', '!=', 'arrears'))->latest()->limit(3)->get(['id', 'subject', 'status', 'created_at']) : null,
            'reminderSettings' => CommunitySettings::for($tenant)->only(['reminders_enabled', 'reminder_offsets', 'frequent_digest', 'sms_monthly_limit', 'week_starts_on']),
            'counts' => [
                'members' => Member::query()->where('tenant_id', $tenant->id)->count(),
                'activeMembers' => Member::query()->where('tenant_id', $tenant->id)->where('status', MemberStatusEnum::Active)->count(),
                'openCharges' => (clone $charges)->where('status', DuesChargeStatusEnum::Open)->count(),
                'overdueCharges' => (clone $charges)->where('status', DuesChargeStatusEnum::Open)->whereDate('due_on', '<', $today->toDateString())->count(),
            ],
            'collectedThisMonth' => collect($report)
                ->filter(fn (array $amounts): bool => $amounts['collected_online_gross'] > 0)
                ->map(fn (array $amounts, string $currency): array => ['currency' => $currency, 'amount' => $amounts['collected_online_gross']])
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
