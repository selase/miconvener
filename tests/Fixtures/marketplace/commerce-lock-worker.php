<?php

declare(strict_types=1);

use App\Models\StoreOrder;
use App\Services\Marketplace\CommerceInventoryLocks;
use App\Services\Marketplace\CommerceOrderLocks;
use App\Services\Venue\VenueCalendarLocks;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

/**
 * One independent process that takes the locks of a single money or calendar
 * operation, holds them briefly and reports when it held them. Used by the
 * lock-order race tests; it changes no business data.
 */
require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['cache.default' => 'array']);
$input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
DB::connection('landlord')->statement("SET application_name = 'commerce-lock-worker'");

while (microtime(true) < $input['start_at']) {
    usleep(1000);
}

$held = null;
$hold = static function () use (&$held, $input): void {
    $enter = microtime(true);
    usleep((int) $input['hold_ms'] * 1000);
    $held = ['enter' => $enter, 'exit' => microtime(true)];
};

try {
    switch ($input['action']) {
        case 'order_lock':
            DB::connection('landlord')->transaction(function () use ($input, $hold): void {
                app(CommerceOrderLocks::class)->order(StoreOrder::query()->findOrFail($input['order']));
                $hold();
            });
            break;
        case 'tenant_lock':
            DB::connection('landlord')->transaction(function () use ($input, $hold): void {
                app(CommerceInventoryLocks::class)->tenant($input['tenant']);
                $hold();
            });
            break;
        case 'calendar_listing':
            app(VenueCalendarLocks::class)->forListing($input['listing'], $hold);
            break;
        case 'calendar_booking':
            app(VenueCalendarLocks::class)->forBooking($input['booking'], $hold);
            break;
        default:
            throw new InvalidArgumentException('Unknown action');
    }
    echo json_encode(['action' => $input['action']] + $held, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    echo json_encode(['action' => $input['action'], 'error' => $e::class.': '.$e->getMessage()], JSON_THROW_ON_ERROR);
}
