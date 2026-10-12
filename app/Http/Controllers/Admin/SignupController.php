<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantFollowUp;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Who has signed up and how far they got, for MiConvener's own staff, with a
 * running log of following up. Open to the superadmin, marketing (who follow
 * up) and finance (who can read).
 */
final class SignupController extends Controller
{
    private const array STAGES = [
        'all' => 'All',
        'signed_up' => 'Signed up, not onboarded',
        'onboarded' => 'Onboarded',
    ];

    public function index(Request $request): View
    {
        $this->authorize('view signups');

        $stage = array_key_exists((string) $request->query('stage'), self::STAGES) ? (string) $request->query('stage') : 'all';
        $search = mb_trim((string) $request->query('q'));

        $signups = Tenant::query()
            ->with('package')
            ->when($stage === 'signed_up', fn ($query) => $query->whereNull('onboarding_completed_at'))
            ->when($stage === 'onboarded', fn ($query) => $query->whereNotNull('onboarding_completed_at'))
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('name', 'ilike', "%{$search}%")
                ->orWhere('email', 'ilike', "%{$search}%")
                ->orWhere('phone_number', 'ilike', "%{$search}%")))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        // The newest follow-up for each organisation on the page. The ids are
        // uuids, which max() cannot compare, so DISTINCT ON picks the row.
        $latest = TenantFollowUp::query()
            ->with('author')
            ->selectRaw('distinct on (tenant_id) *')
            ->whereIn('tenant_id', $signups->pluck('id'))
            ->orderBy('tenant_id')
            ->orderByDesc('created_at')
            ->get()
            ->keyBy('tenant_id');

        return view('admin.signups.index', [
            'signups' => $signups,
            'latest' => $latest,
            'stage' => $stage,
            'stages' => self::STAGES,
            'search' => $search,
            'totals' => [
                'all' => Tenant::query()->count(),
                'onboarded' => Tenant::query()->whereNotNull('onboarding_completed_at')->count(),
                'this_week' => Tenant::query()->where('created_at', '>=', now()->subDays(7))->count(),
            ],
        ]);
    }

    public function show(Tenant $tenant): View
    {
        $this->authorize('view signups');

        return view('admin.signups.show', [
            'tenant' => $tenant->load('package'),
            'followUps' => $tenant->followUps()->with('author')->get(),
            'statuses' => TenantFollowUp::STATUSES,
        ]);
    }

    public function followUp(Request $request, Tenant $tenant): RedirectResponse
    {
        $this->authorize('follow up signups');

        $validated = $request->validate([
            'status' => ['required', Rule::in(array_keys(TenantFollowUp::STATUSES))],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $tenant->followUps()->create([
            'user_id' => $request->user()->getKey(),
            'status' => $validated['status'],
            'note' => filled($validated['note'] ?? null) ? $validated['note'] : null,
        ]);

        return back()->with('success', 'Follow-up logged.');
    }
}
