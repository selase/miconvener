<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Mail\VerifiedSenderTransport;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Opcodes\LogViewer\Facades\LogViewer;
use RuntimeException;
use WeakMap;

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

        $this->app->scoped(\App\Services\Design\ArtifactFontRegistry::class);
        $this->app->singleton(\App\Services\Tenancy\TenantContext::class);
        $this->app->singleton(\App\Services\Tenancy\TenantDatabaseManager::class);
        $this->app->singleton(\App\Services\Tenancy\TenantStorageManager::class);
        $this->app->singleton(\App\Services\Tenancy\FeatureService::class);

        $this->app->singleton(\Aws\SesV2\SesV2Client::class, function (): \Aws\SesV2\SesV2Client {
            $ses = (array) config('services.ses');
            $options = ['version' => 'latest', 'region' => $ses['region'] ?? 'us-east-1'];

            if (filled($ses['key'] ?? null) && filled($ses['secret'] ?? null)) {
                $options['credentials'] = ['key' => $ses['key'], 'secret' => $ses['secret']];
            }

            return new \Aws\SesV2\SesV2Client($options);
        });

        // Domain service interfaces
        $this->app->bind(\App\Contracts\InvoicingServiceContract::class, \App\Services\Tenancy\InvoicingService::class);
        $this->app->bind(\App\Contracts\DnsTxtResolver::class, \App\Services\Venue\NativeDnsTxtResolver::class);
        $this->app->bind(\App\Contracts\UsageServiceContract::class, \App\Services\Tenancy\UsageService::class);
        $this->app->bind(\App\Contracts\LlmUsageServiceContract::class, \App\Services\Llm\LlmUsageService::class);
        $this->app->bind(\App\Contracts\TenantMigratorContract::class, \App\Services\Tenancy\TenantMigrator::class);

        $this->app->bind(\Stripe\StripeClient::class, fn (): \Stripe\StripeClient => new \Stripe\StripeClient(config('services.stripe.secret') ?? ''));

        $this->app->bind(\App\Services\Sms\OmnichannelSmsGateway::class, fn (): \App\Services\Sms\OmnichannelSmsGateway => \App\Services\Sms\OmnichannelSmsGateway::fromConfig());

        $this->app->bind(\App\Contracts\SmsGateway::class, function ($app): \App\Contracts\SmsGateway {
            return match (config('services.sms.driver')) {
                'omnichannel' => filled(config('services.omnichannel.token'))
                    ? $app->make(\App\Services\Sms\OmnichannelSmsGateway::class)
                    : new \App\Services\Sms\NullSmsGateway,
                default => new \App\Services\Sms\NullSmsGateway,
            };
        });

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
                        'stripe' => $app->make(\App\Services\Payment\StripeConnect::class)->gatewayFor($gatewayConfig),
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
        $mail = $this->app->make(MailManager::class);
        $mail->extend('resend-verified-smtp', fn (array $config): VerifiedSenderTransport => new VerifiedSenderTransport(
            $mail->createSymfonyTransport($config['smtp']),
            (string) $config['verified_from'],
        ));

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

        /*
         * Production logs carry request data, errors and stack traces, so only
         * the application superadmin may read them — the same gate as the
         * superadmin dashboard. Never an email match: tenant admins set their
         * team members' addresses and users.email is unique globally, so an
         * unclaimed address on a list is a value any tenant can take.
         */
        LogViewer::auth(fn ($request): bool => $request->user() !== null
            && Gate::forUser($request->user())->allows('access-superadmin-dashboard'));

        $this->configureQueue();
    }

    private function registerBladeDirectives(): void
    {
        \Illuminate\Support\Facades\Blade::if('feature', fn (string $key) => app(\App\Services\Tenancy\FeatureService::class)->enabled($key));
    }

    private function configureQueue(): void
    {
        $this->app->singleton(\App\Jobs\Middleware\TenantAwareJob::class);
        $scope = $this->app->make(\App\Jobs\Middleware\TenantAwareJob::class);
        $attempts = new WeakMap;

        \Illuminate\Support\Facades\Queue::createPayloadUsing(function ($connection, $queue, $payload): array {
            $tenantId = app(\App\Services\Tenancy\TenantContext::class)->activeTenantId();

            return $tenantId ? ['tenant_id' => $tenantId] : [];
        });

        \Illuminate\Support\Facades\Queue::before(function (\Illuminate\Queue\Events\JobProcessing $event) use ($scope, $attempts): void {
            $payload = $event->job->payload();
            $restoreParent = $scope->hasActiveScope()
                || config("queue.connections.{$event->connectionName}.driver") === 'sync';

            $scope->enter($payload['tenant_id'] ?? null, $restoreParent, function (Closure $cleanup) use ($attempts, $event): void {
                $attempts[$event->job] = $cleanup;
            });
        });

        /*
         * Both Worker and SyncQueue emit JobAttempted in finally, after failed
         * callbacks as well as successful handlers. Earlier exception events
         * would restore a parent while the child is still running its failure
         * callbacks. The job key keeps nested attempts independent.
         */
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Queue\Events\JobAttempted::class, function (\Illuminate\Queue\Events\JobAttempted $event) use ($attempts): void {
            if (isset($attempts[$event->job])) {
                $cleanup = $attempts[$event->job];
                unset($attempts[$event->job]);
                $cleanup();
            }
        });
    }
}
