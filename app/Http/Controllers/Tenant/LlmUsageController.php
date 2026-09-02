<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\LlmTokenUsage;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class LlmUsageController extends Controller
{
    public function index(Request $request): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View
    {
        $tenant = app(TenantContext::class)->getTenant();

        // Summary Statistics
        $totalUsage = DB::connection('landlord')->table('llm_token_usages')
            ->where('tenant_id', $tenant->id)
            ->selectRaw('
                SUM(total_tokens) as total_tokens,
                SUM(prompt_tokens) as prompt_tokens,
                SUM(completion_tokens) as completion_tokens,
                SUM(cost_usd) as total_cost
            ')
            ->first();

        // Usage over time (last 30 days)
        $usageTrend = DB::connection('landlord')->table('llm_token_usages')
            ->where('tenant_id', $tenant->id)
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('DATE(created_at) as date, SUM(total_tokens) as tokens, SUM(cost_usd) as cost')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Recent Activity
        $recentUsage = LlmTokenUsage::where('tenant_id', $tenant->id)
            ->with(['user', 'apiKey'])
            ->latest()
            ->paginate(20);

        $tokenPacks = config('llm.token_packs', []);
        $topupBalance = $tenant->llm_topup_balance;

        $breadcrumbs = [
            ['link' => route('tenant.dashboard'), 'name' => __('Home')],
            ['link' => '#', 'name' => __('LLM Usage')],
        ];

        return view('admin.tenant.llm-usage.index', ['totalUsage' => $totalUsage, 'usageTrend' => $usageTrend, 'recentUsage' => $recentUsage, 'tokenPacks' => $tokenPacks, 'topupBalance' => $topupBalance, 'breadcrumbs' => $breadcrumbs]);
    }
}
