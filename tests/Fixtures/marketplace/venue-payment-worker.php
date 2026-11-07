<?php

declare(strict_types=1);

use App\Models\StorePaymentAttempt;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VenueBooking;
use App\Services\Marketplace\StorePaymentConfirmer;
use App\Services\Marketplace\StorePaymentEvidence;
use App\Services\Marketplace\VenueAvailabilityService;
use App\Services\Marketplace\VenueBookingApprovals;
use App\Services\Tenancy\TenantContext;
use App\Services\Venue\VenueCalendarLocks;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * One independent process performing a real venue payment or calendar
 * operation. The race test points several of these at the same booking while it
 * holds the venue's lock, then releases them together.
 */
require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['cache.default' => 'array', 'session.driver' => 'array', 'services.settlement.paystack.secret_key' => 'sk_test_venue_race']);
DB::connection('landlord')->statement("SET application_name = 'venue-payment-worker'");
$input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
echo "READY\n";
flush();

try {
    switch ($input['action']) {
        case 'record_confirm':
            $attempt = StorePaymentAttempt::query()->findOrFail($input['attempt']);
            $capture = app(StorePaymentEvidence::class)->record($attempt, $input['evidence']);
            app(StorePaymentConfirmer::class)->confirm($capture);
            echo 'CONFIRMED';
            break;
        case 'legacy_conflict':
            Illuminate\Support\Facades\Mail::fake();
            app(App\Services\Marketplace\VenueBookingService::class)->confirmBookingPayment('review-legacy-conflict', ['amount' => 30000, 'fees' => 150, 'metadata' => ['venue_booking_id' => $input['booking']]]);
            echo 'LEGACY_RECORDED';
            break;
        case 'legacy_resolve':
            Illuminate\Support\Facades\Http::fake([
                '*api.paystack.co/transaction/verify/*' => Illuminate\Support\Facades\Http::response(['status' => true, 'data' => ['id' => 9001, 'reference' => $input['reference'], 'status' => 'success', 'amount' => $input['amount'], 'fees' => 150, 'currency' => 'GHS', 'paid_at' => now()->subHour()->toIso8601String(), 'metadata' => ['venue_booking_id' => $input['booking']]]]),
                '*api.paystack.co/refund' => Illuminate\Support\Facades\Http::response(['data' => ['id' => 55001, 'status' => 'pending']]),
            ]);
            $resolution = App\Models\VenuePaymentResolution::query()->findOrFail($input['resolution']);
            app(TenantContext::class)->setTenant(Tenant::query()->findOrFail($resolution->tenant_id));
            try {
                app(App\Services\Marketplace\VenuePaymentReconciliation::class)->resolve($resolution, User::query()->findOrFail($input['actor']), 'refund', 'Return the late payment.', $input['key']);
                echo 'RESOLVED';
            } catch (ValidationException) {
                echo 'REFUSED';
            }
            break;
        case 'payout_reserve':
            $tenant = Tenant::query()->findOrFail($input['tenant']);
            app(TenantContext::class)->setTenant($tenant);
            try {
                app(App\Services\Marketplace\StorePayouts::class)->reserve($tenant, User::query()->findOrFail($input['actor']), ['amount' => $input['amount'], 'currency' => 'GHS', 'payout_account_id' => $input['account'], 'operation_key' => $input['key']]);
                echo 'RESERVED';
            } catch (ValidationException) {
                echo 'REFUSED';
            }
            break;
        case 'refund_request':
            Illuminate\Support\Facades\Http::fake(['*api.paystack.co/refund' => Illuminate\Support\Facades\Http::response(['data' => ['id' => 55001, 'status' => 'pending']])]);
            $order = App\Models\StoreOrder::query()->findOrFail($input['order']);
            app(TenantContext::class)->setTenant(Tenant::query()->findOrFail($order->tenant_id));
            $refund = app(App\Services\Marketplace\StoreRefunds::class)->request($order, User::query()->findOrFail($input['host']), $input['key'], 'Cancelled by the planner.');
            echo 'REFUND_'.$refund->status;
            break;
        case 'occupy':
            $rival = VenueBooking::query()->findOrFail($input['booking']);
            $won = app(VenueCalendarLocks::class)->forBooking($rival->id, function ($listing, VenueBooking $locked): bool {
                if (! app(VenueAvailabilityService::class)->isSlotAvailable($listing, $locked->starts_at, $locked->ends_at, $locked->id)) {
                    return false;
                }
                $locked->update(['status' => VenueBooking::STATUS_CONFIRMED]);

                return true;
            });
            echo $won ? 'OCCUPIED' : 'TAKEN';
            break;
        case 'release':
            $booking = VenueBooking::query()->findOrFail($input['booking']);
            app(TenantContext::class)->setTenant(Tenant::query()->findOrFail($booking->tenant_id));
            try {
                app(VenueBookingApprovals::class)->release($booking, User::query()->findOrFail($input['host']), 'Dates needed elsewhere.', (string) Str::uuid());
                echo 'RELEASED';
            } catch (ValidationException) {
                echo 'REFUSED';
            }
            break;
        default:
            throw new InvalidArgumentException('Unknown action');
    }
} catch (Throwable $e) {
    echo 'ERROR '.$e::class.': '.$e->getMessage();
}
