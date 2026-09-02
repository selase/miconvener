<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

final class GlobalLlmUsageController extends Controller
{
    /**
     * Display a listing of LLM usage across all tenants.
     */
    public function index(): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View
    {
        $this->authorize('read llm usage');

        $breadcrumbs = [
            ['link' => route('dashboard'), 'name' => __('Home')],
            ['link' => '#', 'name' => __('LLM Usage Dashboard')],
        ];

        // Summary Stats (Total)
        $summary = DB::connection('landlord')->table('llm_usage_summaries')
            ->select([
                DB::raw('SUM(total_total_tokens) as total_tokens'),
                DB::raw('SUM(total_cost_usd) as total_cost'),
                DB::raw('SUM(request_count) as total_requests'),
            ])
            ->first();

        // Top Tenants by Usage (Last 30 days)
        $topTenants = DB::connection('landlord')->table('llm_usage_summaries')
            ->join('tenants', 'tenants.id', '=', 'llm_usage_summaries.tenant_id')
            ->select(
                'tenants.name as tenant_name',
                'tenants.slug as tenant_slug',
                DB::raw('SUM(total_total_tokens) as total_tokens'),
                DB::raw('SUM(total_cost_usd) as total_cost')
            )
            ->where('day', '>=', now()->subDays(30))
            ->groupBy('tenants.id', 'tenants.name', 'tenants.slug')
            ->orderByDesc('total_tokens')
            ->limit(10)
            ->get();

        // Top Models by Usage
        $topModels = DB::connection('landlord')->table('llm_usage_summaries')
            ->select('model', DB::raw('SUM(total_total_tokens) as total_tokens'))
            ->groupBy('model')
            ->orderByDesc('total_tokens')
            ->get();

        // Daily Trend (Last 30 days)
        $dailyTrend = DB::connection('landlord')->table('llm_usage_summaries')
            ->select('day', DB::raw('SUM(total_total_tokens) as total_tokens'), DB::raw('SUM(total_cost_usd) as total_cost'))
            ->where('day', '>=', now()->subDays(30))
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        return view('admin.llm-usage.index', ['breadcrumbs' => $breadcrumbs, 'summary' => $summary, 'topTenants' => $topTenants, 'topModels' => $topModels, 'dailyTrend' => $dailyTrend]);
    }
}
