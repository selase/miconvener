<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Marketing\UpdateCurrencyRequest;
use App\Services\Pricing\VisitorCurrency;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cookie;

/**
 * The "show prices in USD / GHS" switch for visitors in Ghana. Choosing USD is
 * remembered for a year; choosing GHS from Ghana clears it. Asking for GHS from
 * anywhere else does nothing: the Ghana price is not something a visitor picks.
 */
final class CurrencyController extends Controller
{
    public function update(UpdateCurrencyRequest $request, VisitorCurrency $currency): RedirectResponse
    {
        $response = redirect()->back();

        if ($request->validated('currency') === 'USD') {
            return $response->withCookie(cookie(VisitorCurrency::COOKIE, 'USD', 60 * 24 * 365));
        }

        return $currency->inGhana($request) ? $response->withCookie(Cookie::forget(VisitorCurrency::COOKIE)) : $response;
    }
}
