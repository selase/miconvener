<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\LlmTokenUsage;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class LlmUsageController extends Controller
{
    public function index(Request $request): Response
    {
        $tenant = app(TenantContext::class)->getTenant();

        $totalUsage = DB::connection('landlord')->table('llm_token_usages')
            ->where('tenant_id', $tenant->id)
            ->selectRaw('
                SUM(total_tokens) as total_tokens,
                SUM(prompt_tokens) as prompt_tokens,
                SUM(completion_tokens) as completion_tokens,
                SUM(cost_usd) as total_cost
            ')
            ->first();

        $recentUsage = LlmTokenUsage::where('tenant_id', $tenant->id)
            ->with(['user', 'apiKey'])
            ->latest()
            ->paginate(20)
            ->through(fn (LlmTokenUsage $usage): array => [
                'id' => $usage->id,
                'model' => $usage->model,
                'total_tokens' => $usage->total_tokens,
                'cost_usd' => number_format((float) $usage->cost_usd, 4),
                'user' => $usage->user?->displayName(),
                'api_key' => $usage->apiKey?->name,
                'created_at' => $usage->created_at->format('Y-m-d H:i'),
            ]);

        return Inertia::render('Tenant/LlmUsage/Index', [
            'totalUsage' => [
                'total_tokens' => (int) ($totalUsage->total_tokens ?? 0),
                'prompt_tokens' => (int) ($totalUsage->prompt_tokens ?? 0),
                'completion_tokens' => (int) ($totalUsage->completion_tokens ?? 0),
                'total_cost' => number_format((float) ($totalUsage->total_cost ?? 0), 2),
            ],
            'recentUsage' => $recentUsage,
            'topupBalance' => $tenant->llm_topup_balance,
        ]);
    }
}
