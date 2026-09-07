<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Opcodes\LogViewer\Facades\LogViewer;
use RuntimeException;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        if ($this->app->environment('local')) {
            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
            $this->app->register(TelescopeServiceProvider::class);
        }

        $this->app->singleton(\App\Contracts\Secrets\SecretsProvider::class, function ($app): \App\Services\Secrets\AwsKmsSecretsProvider|\App\Services\Secrets\LocalSecretsProvider {
            $config = config('secrets');
            $driver = $config['default'] ?? 'local';

            return match ($driver) {
                'aws' => new \App\Services\Secrets\AwsKmsSecretsProvider($config['providers']['aws']),
                default => new \App\Services\Secrets\LocalSecretsProvider($config['providers']['local']['path'] ?? null),
            };
        });

        $this->app->singleton(\App\Services\Tenancy\TenantContext::class);
        $this->app->singleton(\App\Services\Tenancy\TenantDatabaseManager::class);
        $this->app->singleton(\App\Services\Tenancy\TenantStorageManager::class);
        $this->app->singleton(\App\Services\Tenancy\FeatureService::class);

        // Domain service interfaces
        $this->app->bind(\App\Contracts\InvoicingServiceContract::class, \App\Services\Tenancy\InvoicingService::class);
        $this->app->bind(\App\Contracts\UsageServiceContract::class, \App\Services\Tenancy\UsageService::class);
        $this->app->bind(\App\Contracts\LlmUsageServiceContract::class, \App\Services\Llm\LlmUsageService::class);
        $this->app->bind(\App\Contracts\TenantMigratorContract::class, \App\Services\Tenancy\TenantMigrator::class);

        $this->app->bind(\Stripe\StripeClient::class, fn (): \Stripe\StripeClient => new \Stripe\StripeClient(config('services.stripe.secret') ?? ''));

        $this->app->bind(\App\Contracts\PaymentGateway::class, function ($app) {
            $request = $app->make('request');
            $context = $request->attributes->get('payment_context', 'platform');
            $tenantContext = $app->make(\App\Services\Tenancy\TenantContext::class);

            if ($context === 'commerce' && $tenant = $tenantContext->getTenant()) {
                $gatewayConfig = \App\Models\TenantPaymentGateway::where('tenant_id', $tenant->id)
                    ->where('is_active', true)
                    ->first();

                if ($gatewayConfig) {
                    $config = [
                        'secret_key' => $gatewayConfig->api_key_encrypted,
                        'public_key' => $gatewayConfig->public_key_encrypted,
                    ];

                    return match ($gatewayConfig->provider) {
                        'paystack' => new \App\Services\Payment\PaystackGateway($config),
                        'stripe' => new \App\Services\Payment\StripeGateway($app->make(\Stripe\StripeClient::class), $config),
                        default => $app->make(\App\Services\Payment\PaystackGateway::class),
                    };
                }
            }

            $driver = config('services.payment.default', 'paystack');

            return match ($driver) {
                'paystack' => $app->make(\App\Services\Payment\PaystackGateway::class),
                default => $app->make(\App\Services\Payment\StripeGateway::class),
            };
        });

        $this->app->bind(\App\Contracts\SettlementGateway::class, function ($app) {
            $driver = config('services.settlement.default', 'paystack');

            return match ($driver) {
                'paystack' => $app->make(\App\Services\Settlement\PaystackSettlementGateway::class),
                default => throw new RuntimeException("Unsupported settlement driver: {$driver}"),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('migrations/landlord'));

        $this->registerBladeDirectives();

        /**
         * Everything strict, all the time.
         * [prevent lazy loading, prevent silently discarding attributes, prevent accessing missing attributes]
         */
        Model::shouldBeStrict();

        // In production, merely log lazy loading violations.
        if ($this->app->isProduction()) {
            Model::handleLazyLoadingViolationUsing(function ($model, $relation): void {
                $class = $model::class;

                info("Attempted to lazy load [{$relation}] on model [{$class}].");
            });
        }

        // Set default password rule
        Password::defaults(function () {
            $rule = Password::min(8);

            return $this->app
                ->isProduction()
                ? $rule->mixedCase()->letters()->numbers()->symbols()->uncompromised()
                : $rule;
        });

        // Authorizing users to access the log-viewer
        LogViewer::auth(fn ($request): bool => $request->user()
            && in_array($request->user()->email, [
                'hiselase@gmail.com',
                'dev@wearepurpledot.com',
            ]));

        $this->configureQueue();
    }

    private function registerBladeDirectives(): void
    {
        \Illuminate\Support\Facades\Blade::if('feature', fn (string $key) => app(\App\Services\Tenancy\FeatureService::class)->enabled($key));
    }

    private function configureQueue(): void
    {
        \Illuminate\Support\Facades\Queue::createPayloadUsing(function ($connection, $queue, $payload): array {
            $tenantId = app(\App\Services\Tenancy\TenantContext::class)->activeTenantId();

            return $tenantId ? ['tenant_id' => $tenantId] : [];
        });

        \Illuminate\Support\Facades\Queue::before(function (\Illuminate\Queue\Events\JobProcessing $event): void {
            /** @var array<string, mixed> $payload */
            $payload = $event->job->payload();

            if (isset($payload['tenant_id'])) {
                /** @var \App\Models\Tenant|null $tenant */
                $tenant = \App\Models\Tenant::find($payload['tenant_id']);

                if ($tenant instanceof \App\Models\Tenant) {
                    app(\App\Services\Tenancy\TenantContext::class)->setTenant($tenant);

                    setPermissionsTeamId($tenant->id);

                    \Illuminate\Support\Facades\Log::withContext([
                        'tenant_id' => (string) $tenant->id,
                    ]);

                    if ($tenant->requiresDedicatedDb()) {
                        app(\App\Services\Tenancy\TenantDatabaseManager::class)->configure($tenant);
                    } else {
                        app(\App\Services\Tenancy\TenantDatabaseManager::class)->configureShared();
                    }

                    app(\App\Services\Tenancy\TenantStorageManager::class)->configure($tenant);
                }
            }
        });
    }
}
