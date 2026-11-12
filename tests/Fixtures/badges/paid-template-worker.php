<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['cache.default' => 'array', 'session.driver' => 'array', 'services.settlement.paystack.secret_key' => 'sk_test_badge_races']);
Illuminate\Support\Facades\Queue::fake();
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\DB::connection('landlord')->statement("SET application_name = 'paid-badge-worker'");
$input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
echo "READY\n";
flush();
try {
    if ($input['action'] === 'confirm') {
        $capture = App\Models\StorePaymentObservation::query()->findOrFail($input['capture']);
        app(App\Services\Marketplace\StorePaymentConfirmer::class)->confirm($capture);
        echo 'CONFIRMED';
    } elseif ($input['action'] === 'quote') {
        config(['services.badge_templates.seller_tenant_id' => $input['seller'], 'services.badge_templates.commercial_approval' => 'race-test-terms']);
        $event = App\Models\Event::query()->findOrFail($input['event']);
        setPermissionsTeamId($event->tenant_id);
        app(App\Services\Tenancy\TenantContext::class)->setTenant($event->tenant);
        $purchase = app(App\Services\Badges\BadgeTemplatePurchases::class)->quote($event, App\Models\BadgeLibraryVersion::query()->findOrFail($input['version']), App\Models\User::query()->findOrFail($input['actor']), 'race-payer@example.test', $input['key']);
        echo 'QUOTED:'.$purchase->id;
    } elseif ($input['action'] === 'use') {
        $event = App\Models\Event::query()->findOrFail($input['event']);
        setPermissionsTeamId($event->tenant_id);
        app(App\Services\Tenancy\TenantContext::class)->setTenant($event->tenant);
        app(App\Services\Badges\BadgeTemplateAccess::class)->claimUse($event, App\Models\User::query()->findOrFail($input['actor']), [], 'badge_pdf', $input['key']);
        echo 'USED';
    } elseif ($input['action'] === 'refund') {
        setPermissionsTeamId(null);
        app(App\Services\Badges\BadgeTemplateRefunds::class)->request(App\Models\BadgeTemplatePurchase::query()->findOrFail($input['purchase']), App\Models\User::query()->findOrFail($input['actor']), $input['key'], 'Concurrent unused refund');
        echo 'REFUND';
    }
} catch (Illuminate\Validation\ValidationException) {
    echo 'REFUSED';
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error).' '.$error->getCode());
    exit(1);
}
