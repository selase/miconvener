<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
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
            'enterpriseEmail' => config('mail.from.address', 'sales@miconvener.com'),
        ]);
    }
}
