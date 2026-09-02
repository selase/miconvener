<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\TenantLlmConfig;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class LlmConfigController extends Controller
{
    public function index()
    {
        $tenant = app(TenantContext::class)->getTenant();

        if (! $tenant->featureEnabled('llm_byok')) {
            return redirect()->route('tenant.dashboard')
                ->with('error', __('BYOK is not enabled for your organization. Please contact support.'));
        }

        // Fetch existing configs keyed by provider
        $configs = TenantLlmConfig::where('tenant_id', $tenant->id)
            ->get()
            ->keyBy('provider');

        return view('admin.tenant.llm-config.index', ['configs' => $configs]);
    }

    public function update(Request $request)
    {
        $tenant = app(TenantContext::class)->getTenant();

        if (! $tenant->featureEnabled('llm_byok')) {
            return redirect()->route('tenant.dashboard')
                ->with('error', __('Action unauthorized.'));
        }

        $validated = $request->validate([
            'configs' => ['array'],
            'configs.*.provider' => ['required', Rule::in(['openai', 'anthropic', 'google'])],
            'configs.*.api_key' => ['nullable', 'string', 'min:10'], // Allow null to clear? Or separate delete?
            'configs.*.is_active' => ['boolean'],
        ]);

        foreach ($validated['configs'] as $data) {
            $provider = $data['provider'];
            $config = TenantLlmConfig::firstOrNew([
                'tenant_id' => $tenant->id,
                'provider' => $provider,
            ]);

            if (! empty($data['api_key'])) {
                $config->api_key_encrypted = $data['api_key']; // Setter handles encryption
                $config->is_active = $data['is_active'] ?? true;
                $config->save();
            }

            // Handle toggling active state without re-entering key
            if ($config->exists && isset($data['is_active'])) {
                $config->is_active = (bool) $data['is_active'];
                $config->save();
            }
        }

        return redirect()->route('tenant.llm-config.index')
            ->with('success', __('LLM Configurations updated successfully.'));
    }

    public function destroy(string $provider)
    {
        $tenant = app(TenantContext::class)->getTenant();

        TenantLlmConfig::where('tenant_id', $tenant->id)
            ->where('provider', $provider)
            ->delete();

        return redirect()->route('tenant.llm-config.index')
            ->with('success', __('Configuration removed.'));
    }
}
