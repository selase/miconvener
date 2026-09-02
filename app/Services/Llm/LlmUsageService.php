<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Exceptions\QuotaExceededException;
use App\Models\LlmTokenUsage;
use App\Models\Tenant;
use App\Models\TenantLlmConfig;
use App\Services\Tenancy\FeatureMeteringService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

final class LlmUsageService implements \App\Contracts\LlmUsageServiceContract
{
    /**
     * Record LLM token usage.
     */
    public function record(
        string $tenantId,
        string $provider,
        string $model,
        int $promptTokens,
        int $completionTokens,
        array $context = [],
        ?string $userId = null,
        ?string $apiKeyId = null,
        ?string $ipAddress = null
    ): LlmTokenUsage {
        $cost = $this->calculateCost($model, $promptTokens, $completionTokens);
        $totalTokens = $promptTokens + $completionTokens;

        $tenant = Tenant::find($tenantId);

        // Record usage in metered billing if NOT using BYOK
        if ($tenant && ! $this->isUsingByok($tenantId)) {
            $metering = app(FeatureMeteringService::class);
            $usage = $metering->getUsage($tenant, 'llm_token_quota');

            $remainingQuota = max(0, $usage['limit'] - $usage['used']);

            if ($remainingQuota >= $totalTokens) {
                // Entirely from quota
                $metering->recordUsage($tenant, 'llm_token_quota', $totalTokens);
            } elseif ($remainingQuota > 0) {
                // Partial from quota, partial from top-up
                $metering->recordUsage($tenant, 'llm_token_quota', $remainingQuota);
                $overflow = $totalTokens - $remainingQuota;
                $this->deductFromTopup($tenant, $overflow);
            } else {
                // Entirely from top-up
                $this->deductFromTopup($tenant, $totalTokens);
            }

            // Check thresholds and notify if necessary
            $this->checkQuotaThresholds($tenant);
        }

        // Global spending check (alerting app owners)
        if ($tenant && ! $this->isUsingByok($tenantId)) {
            $this->checkGlobalSpendingLimit($cost);
        }

        return LlmTokenUsage::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'api_key_id' => $apiKeyId,
            'provider' => $provider,
            'model' => $model,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $totalTokens,
            'cost_usd' => $cost,
            'context' => $context,
            'ip_address' => $ipAddress,
        ]);
    }

    /**
     * Get the API key for the given tenant and provider.
     */
    public function getApiKey(string $tenantId, string $provider): ?string
    {
        $tenant = Tenant::find($tenantId);
        if (! $tenant) {
            return null;
        }

        // Check BYOK Feature
        if (! $tenant->featureEnabled('llm_byok')) {
            return config("services.{$provider}.api_key");
        }

        $config = TenantLlmConfig::where('tenant_id', $tenantId)
            ->where('provider', $provider)
            ->where('is_active', true)
            ->first();

        return $config ? decrypt($config->api_key_encrypted) : config("services.{$provider}.api_key");
    }

    /**
     * Check if a model is allowed for the given tenant.
     */
    public function isModelAllowed(string $tenantId, string $model): bool
    {
        $tenant = Tenant::find($tenantId);
        if (! $tenant || ! $tenant->llm_models_whitelist) {
            return true;
        }

        return in_array($model, $tenant->llm_models_whitelist);
    }

    /**
     * Check if a tenant can consume LLM tokens.
     */
    public function canConsume(string $tenantId, int $estimatedTokens = 1): bool
    {
        $tenant = Tenant::find($tenantId);
        if (! $tenant) {
            return false;
        }

        // If using BYOK, we don't enforce our own platform quota
        if ($this->isUsingByok($tenantId)) {
            return true;
        }

        if ($tenant->canUse('llm_token_quota', $estimatedTokens)) {
            return true;
        }

        return $tenant->llm_topup_balance >= $estimatedTokens;
    }

    /**
     * Ensure a tenant can consume tokens, or throw.
     */
    public function ensureCanConsume(string $tenantId, int $estimatedTokens = 1): void
    {
        if (! $this->canConsume($tenantId, $estimatedTokens)) {
            $tenant = Tenant::find($tenantId);
            $metering = app(FeatureMeteringService::class);
            $usage = $metering->getUsage($tenant, 'llm_token_quota');

            throw QuotaExceededException::forLlmTokens(
                requested: $estimatedTokens,
                remaining: max(0, ($usage['limit'] - $usage['used']) + ($tenant?->llm_topup_balance ?? 0)),
            );
        }
    }

    /**
     * Calculate increased cost based on model pricing.
     */
    public function calculateCost(string $model, int $promptTokens, int $completionTokens): float
    {
        $models = Config::get('llm.models');
        $pricing = $models[$model] ?? null;

        if (! $pricing) {
            // Fallback or log warning? For now, 0 cost if unknown model.
            return 0.0;
        }

        $inputCost = ($promptTokens / 1000) * $pricing['input_price_per_1k'];
        $outputCost = ($completionTokens / 1000) * $pricing['output_price_per_1k'];

        return round($inputCost + $outputCost, 6);
    }

    /**
     * Deduct tokens from the tenant's top-up balance.
     */
    private function deductFromTopup(Tenant $tenant, int $tokens): void
    {
        DB::connection('landlord')->table('tenants')
            ->where('id', $tenant->id)
            ->decrement('llm_topup_balance', $tokens);
    }

    /**
     * Determine if a tenant is using their own API key.
     */
    private function isUsingByok(string $tenantId): bool
    {
        $tenant = Tenant::find($tenantId);
        if (! $tenant || ! $tenant->featureEnabled('llm_byok')) {
            return false;
        }

        return TenantLlmConfig::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Check quota thresholds and send alerts.
     */
    private function checkQuotaThresholds(Tenant $tenant): void
    {
        $usage = app(FeatureMeteringService::class)->getUsage($tenant, 'llm_token_quota');

        if ($usage['limit'] <= 0) {
            return;
        }

        $percent = ($usage['used'] / $usage['limit']) * 100;

        // Logic to prevent spamming alerts
        // This could be tracked in Cache or Tenant meta
        $lastAlert = $tenant->meta['last_llm_quota_alert'] ?? 0;

        if ($percent >= 90 && $lastAlert < 90) {
            $this->sendQuotaAlert($tenant, 90);
            $tenant->update(['meta' => array_merge($tenant->meta ?? [], ['last_llm_quota_alert' => 90])]);
        } elseif ($percent >= 80 && $lastAlert < 80) {
            $this->sendQuotaAlert($tenant, 80);
            $tenant->update(['meta' => array_merge($tenant->meta ?? [], ['last_llm_quota_alert' => 80])]);
        }
    }

    private function sendQuotaAlert(Tenant $tenant, int $threshold): void
    {
        Log::warning("Tenant {$tenant->name} ({$tenant->id}) has reached {$threshold}% of their LLM token quota.");

        // Notification logic would go here
        // Notification::send($tenant->admins, new LlmQuotaExceededNotification($tenant, $threshold));
    }

    /**
     * Check global spending limits across all tenants using system keys.
     */
    private function checkGlobalSpendingLimit(float $additionalCost): void
    {
        $limit = config('llm.global_spending_limit', 500.00);
        $cacheKey = 'llm_global_monthly_spend:'.now()->format('Y-m');

        // Atomically increment the spend using a lock for floating point precision
        $lock = Cache::lock($cacheKey.':lock', 10);
        try {
            $lock->block(5);
            $currentSpend = (float) Cache::get($cacheKey, 0.0);
            $newSpend = $currentSpend + $additionalCost;
            Cache::put($cacheKey, $newSpend, now()->addMonth());
        } finally {
            $lock->release();
        }

        $percent = ($newSpend / $limit) * 100;

        $lastAlertCacheKey = 'llm_global_spend_alert_level:'.now()->format('Y-m');
        $lastAlert = Cache::get($lastAlertCacheKey, 0);

        if ($percent >= 90 && $lastAlert < 90) {
            $this->sendGlobalQuotaAlert(90, $newSpend, $limit);
            Cache::put($lastAlertCacheKey, 90, now()->addMonth());
        } elseif ($percent >= 80 && $lastAlert < 80) {
            $this->sendGlobalQuotaAlert(80, $newSpend, $limit);
            Cache::put($lastAlertCacheKey, 80, now()->addMonth());
        }
    }

    private function sendGlobalQuotaAlert(int $threshold, float $current, float $limit): void
    {
        $alertEmail = config('llm.alert_email', 'admin@example.com');
        Log::critical("GLOBAL LLM SPENDING ALERT: reached {$threshold}% of monthly budget. Current: \${$current} / Limit: \${$limit}. Check: {$alertEmail}");

        // Integration with notification system
        // Notification::route('mail', $alertEmail)->notify(new GlobalLlmQuotaAlert($threshold, $current, $limit));
    }
}
