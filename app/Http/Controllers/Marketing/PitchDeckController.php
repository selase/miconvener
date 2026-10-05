<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Package;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class PitchDeckController extends Controller
{
    public function __invoke(Request $request): Factory|View
    {
        return view('marketing.deck', [
            'currency' => config('services.paystack.currency', 'GHS'),
            'brandName' => config('product-page.brand.name', 'MiConvener'),
            'commissionPlans' => $this->commissionPlans(),
            'enterpriseEmail' => config('mail.from.address', 'sales@miconvener.com'),
        ]);
    }

    /**
     * The commission each self-serve paid plan charges, for the calculator.
     *
     * @return list<array{name: string, percentage: float, cap: float}>
     */
    private function commissionPlans(): array
    {
        return Package::query()
            ->whereIn('slug', ['starter', 'growth'])
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Package $package): array => [
                'name' => $package->name,
                'percentage' => (float) $package->default_platform_fee_percentage,
                'cap' => $package->default_platform_fee_cap_amount / 100,
            ])
            ->all();
    }
}
