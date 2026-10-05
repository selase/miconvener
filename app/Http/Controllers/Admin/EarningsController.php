<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Finance\PlatformEarnings;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * What MiConvener earned in a period: commission from the ledger plus what
 * organisations paid us for plans and add-ons. See PlatformEarnings.
 */
final class EarningsController extends Controller
{
    /**
     * @var array<string, string>
     */
    private const array PERIODS = [
        'this_month' => 'This month',
        'last_month' => 'Last month',
        'last_90_days' => 'Last 90 days',
        'this_year' => 'This year',
        'all_time' => 'All time',
    ];

    public function index(Request $request, PlatformEarnings $earnings): View
    {
        $this->authorize('access-superadmin-dashboard');

        $period = array_key_exists((string) $request->query('period'), self::PERIODS) ? (string) $request->query('period') : 'this_month';
        [$from, $to] = $this->range($period);

        return view('admin.billing.earnings.index', [
            'period' => $period,
            'periods' => self::PERIODS,
            'from' => $from,
            'to' => $to,
            'earnings' => $earnings->between($from, $to),
        ]);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function range(string $period): array
    {
        $now = CarbonImmutable::now();

        return match ($period) {
            'last_month' => [$now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth()],
            'last_90_days' => [$now->subDays(90)->startOfDay(), $now],
            'this_year' => [$now->startOfYear(), $now],
            'all_time' => [CarbonImmutable::create(2020), $now],
            default => [$now->startOfMonth(), $now],
        };
    }
}
