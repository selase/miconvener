<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Marketing\UpdateCurrencyRequest;
use App\Services\Pricing\VisitorCurrency;
use Illuminate\Http\RedirectResponse;

/**
 * The "Prices in GHS · USD" switch: remembers the visitor's choice for a year.
 */
final class CurrencyController extends Controller
{
    public function update(UpdateCurrencyRequest $request): RedirectResponse
    {
        return redirect()->back()->withCookie(cookie(VisitorCurrency::COOKIE, (string) $request->validated('currency'), 60 * 24 * 365));
    }
}
