<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\TenantLlmConfig;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class LlmConfigController extends Controller
{
    private const array PROVIDERS = ['openai', 'anthropic', 'google'];

    public function index(): Response|RedirectResponse
    {
        $tenant = app(TenantContext::class)->getTenant();

        if (! $tenant->featureEnabled('llm_byok')) {
            return redirect()->route('tenant.dashboard')
                ->with('error', __('BYOK is not enabled for your organization. Please contact support.'));
        }

        $configs = TenantLlmConfig::where('tenant_id', $tenant->id)
            ->get()
            ->keyBy('provider');

        return Inertia::render('Tenant/LlmConfig/Index', [
            'providers' => self::PROVIDERS,
            'configs' => collect(self::PROVIDERS)->mapWithKeys(fn (string $provider): array => [
                $provider => [
                    'configured' => $configs->has($provider),
                    'is_active' => (bool) ($configs->get($provider)?->is_active ?? false),
                ],
            ]),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $tenant = app(TenantContext::class)->getTenant();

        if (! $tenant->featureEnabled('llm_byok')) {
            return redirect()->route('tenant.dashboard')
                ->with('error', __('Action unauthorized.'));
        }

        $validated = $request->validate([
            'configs' => ['array'],
            'configs.*.provider' => ['required', Rule::in(self::PROVIDERS)],
            'configs.*.api_key' => ['nullable', 'string', 'min:10'],
            'configs.*.is_active' => ['boolean'],
        ]);

        foreach ($validated['configs'] as $data) {
            $provider = $data['provider'];
            $config = TenantLlmConfig::firstOrNew([
                'tenant_id' => $tenant->id,
                'provider' => $provider,
            ]);

            if (! empty($data['api_key'])) {
                $config->api_key_encrypted = $data['api_key'];
                $config->is_active = $data['is_active'] ?? true;
                $config->save();
            }

            if ($config->exists && isset($data['is_active'])) {
                $config->is_active = (bool) $data['is_active'];
                $config->save();
            }
        }

        return redirect()->route('tenant.llm-config.index')
            ->with('success', __('LLM Configurations updated successfully.'));
    }

    public function destroy(string $provider): RedirectResponse
    {
        $tenant = app(TenantContext::class)->getTenant();

        TenantLlmConfig::where('tenant_id', $tenant->id)
            ->where('provider', $provider)
            ->delete();

        return redirect()->route('tenant.llm-config.index')
            ->with('success', __('Configuration removed.'));
    }
}
