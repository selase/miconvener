<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\GivingFund;
use App\Services\Giving\GivingGateways;
use App\Services\Giving\GivingProgress;
use App\Services\Giving\GivingRollout;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class GivingFundController extends Controller
{
    public function index(): Response
    {
        $tenant = app(TenantContext::class)->getTenant();
        abort_unless(app(GivingRollout::class)->enabled($tenant), 404);

        return Inertia::render('Public/Giving/Index', ['organisation' => ['name' => $tenant->name], 'funds' => GivingFund::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('visibility', 'public')->whereNull('event_id')->whereIn('state', ['open', 'closed'])->orderBy('name')->get()->map(fn (GivingFund $fund) => $this->publicFund($fund))]);
    }

    public function show(Request $request, string $subdomain, string $slug): Response
    {
        $tenant = app(TenantContext::class)->getTenant();
        abort_unless(app(GivingRollout::class)->enabled($tenant), 404);
        $fund = GivingFund::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('slug', $slug)->whereIn('visibility', ['public', 'unlisted'])->whereNull('event_id')->whereIn('state', ['open', 'closed'])->firstOrFail();
        $accepting = true;
        try {
            app(GivingProgress::class)->assertOpen($fund);
        } catch (ValidationException) {
            $accepting = false;
        }
        $providers = [];
        if ($accepting && app(GivingRollout::class)->onlineEnabled($tenant)) {
            foreach (['paystack', 'stripe'] as $provider) {
                try {
                    app(GivingGateways::class)->identity($tenant, $provider, $fund->currency);
                    $providers[] = $provider;
                } catch (ValidationException) {
                }
            }
        }

        return Inertia::render('Public/Giving/Fund', ['organisation' => ['name' => $tenant->name], 'fund' => $this->publicFund($fund), 'providers' => $providers, 'accepting' => $accepting]);
    }

    /** @return array<string,mixed> */
    private function publicFund(GivingFund $fund): array
    {
        $progress = app(GivingProgress::class)->for($fund);
        $data = $fund->only(['slug', 'name', 'description', 'button_label', 'currency', 'kind', 'minimum_amount', 'state']);
        $data['url'] = route('public.giving.show', ['subdomain' => $fund->tenant->slug, 'slug' => $fund->slug]);
        $data['progress'] = match ($fund->progress) {
            'percentage' => ['percent' => $progress['percent']],'amounts' => $progress,default => null
        };
        if ($fund->progress === 'amounts') {
            $data['target'] = $fund->target;
        }

        return $data;
    }
}
