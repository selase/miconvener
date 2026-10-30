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
config(['cache.default' => 'array', 'session.driver' => 'array']);
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
