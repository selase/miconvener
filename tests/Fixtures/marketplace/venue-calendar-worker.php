<?php

declare(strict_types=1);

use App\Models\Member;
use App\Models\Shop;
use App\Models\StoreListing;
use App\Models\User;
use App\Models\VenueBooking;
use App\Services\Marketplace\VenueBookingApprovals;
use App\Services\Marketplace\VenueBookingService;
use App\Services\Tenancy\TenantContext;
use App\Services\Venue\InternalBookings;
use App\Services\Venue\VenueBlackouts;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['cache.default' => 'array', 'mail.default' => 'array']);
Queue::fake();
Mail::fake();
$input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
$shop = Shop::query()->findOrFail($input['shop']);
$listing = StoreListing::query()->withStaffOnly()->findOrFail($input['listing']);
setPermissionsTeamId($shop->tenant_id);
app(TenantContext::class)->setTenant($shop->tenant);
$from = CarbonImmutable::parse($input['from']);
$to = CarbonImmutable::parse($input['to']);
$listing->getConnection()->statement("SET application_name = 'venue-calendar-worker'");
echo "READY\n";
flush();
try {
    switch ($input['action']) {
        case 'public_create':
            app(VenueBookingService::class)->createBooking($listing, [
                'planner_name' => 'Race Planner', 'planner_email' => 'race@planner.test', 'event_type' => 'Race',
                'guest_count' => 5, 'layout_style' => 'banquet', 'starts_at' => $from->toDateTimeString(), 'ends_at' => $to->toDateTimeString(),
                'agreed_to_terms' => true,
            ]);
            echo 'CREATED';
            break;
        case 'blackout':
            app(VenueBlackouts::class)->create($shop, $listing->id, $from, $to, 'Race maintenance');
            echo 'BLACKOUT';
            break;
        case 'blackout_remove':
            app(VenueBlackouts::class)->remove($shop, $input['booking']);
            echo 'REMOVED';
            break;
        case 'host_hold':
            app(VenueBookingService::class)->placeHostHold($shop, $input['booking']);
            echo 'HELD';
            break;
        case 'internal_request':
            app(InternalBookings::class)->request($shop->tenant, $listing, Member::query()->findOrFail($input['member']), $from, $to, 'Race request');
            echo 'REQUESTED';
            break;
        case 'internal_approve':
            app(InternalBookings::class)->approve(VenueBooking::query()->findOrFail($input['booking']), User::query()->findOrFail($input['actor']));
            echo 'APPROVED';
            break;
        case 'internal_cancel':
            app(InternalBookings::class)->cancel(VenueBooking::query()->findOrFail($input['booking']), User::query()->findOrFail($input['actor']));
            echo 'CANCELLED';
            break;
        case 'confirm_payment':
            $booking = app(VenueBookingService::class)->confirmBookingPayment($input['reference'], ['amount' => (int) $input['amount'], 'fees' => 0, 'metadata' => ['booking_reference' => $input['booking_reference']]]);
            echo $booking->isConfirmed() && $booking->paystack_reference === $input['reference'] ? 'CONFIRMED' : 'CONFLICT';
            break;
        case 'approve':
            app(VenueBookingApprovals::class)->approve(VenueBooking::query()->findOrFail($input['booking']), User::query()->findOrFail($input['actor']), 24, (string) Illuminate\Support\Str::uuid());
            echo 'APPROVED';
            break;
        case 'renew':
            app(VenueBookingApprovals::class)->renew(VenueBooking::query()->findOrFail($input['booking']), User::query()->findOrFail($input['actor']), 24, (string) Illuminate\Support\Str::uuid());
            echo 'RENEWED';
            break;
        default:
            echo 'UNKNOWN';
    }
} catch (ValidationException) {
    echo 'UNAVAILABLE';
}
