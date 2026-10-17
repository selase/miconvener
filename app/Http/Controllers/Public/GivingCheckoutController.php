<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\Giving\StartGivingCheckoutRequest;
use App\Models\GivingFund;
use App\Models\GivingPaymentAttempt;
use App\Services\Giving\GivingAmounts;
use App\Services\Giving\GivingCheckout;
use App\Services\Giving\GivingGateways;
use App\Services\Giving\GivingPaymentConfirmer;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

final class GivingCheckoutController extends Controller
{
    public function store(StartGivingCheckoutRequest $request, string $subdomain, string $slug, GivingCheckout $checkout): JsonResponse
    {
        $tenant = app(TenantContext::class)->getTenant();
        $fund = GivingFund::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('slug', $slug)->whereIn('visibility', ['public', 'unlisted'])->whereNull('event_id')->firstOrFail();
        $input = $request->validated();
        $input['amount_minor'] = GivingAmounts::minor($input['amount']);
        $url = $checkout->start($fund, $input, $request->session(), $input['submission_key']);

        return response()->json(['checkout_url' => $url])->header('Cache-Control', 'private, no-store');
    }

    public function callback(Request $request, string $subdomain, string $reference, GivingGateways $gateways, GivingPaymentConfirmer $confirmer): RedirectResponse
    {
        $attempt = $this->attempt($reference);
        try {
            $confirmer->confirm($attempt->reference, $gateways->verify($attempt));
        } catch (Throwable) {
            $confirmer->quarantine($attempt, 'verification_unavailable');
        }

        return redirect()->route('public.giving.result', ['subdomain' => $subdomain, 'reference' => $reference])->header('Cache-Control', 'private, no-store');
    }

    public function result(Request $request, string $subdomain, string $reference): JsonResponse|\Symfony\Component\HttpFoundation\Response
    {
        $attempt = $this->attempt($reference);
        $history = app(\App\Services\Giving\GivingHistory::class);
        $allowed = $history->ownsCapability($request->session(), $attempt);
        if (! $allowed && $attempt->state === 'confirmed') {
            $history->sourceForReceipt($request->session(), $attempt->tenant, $attempt->gift_id === null ? 'event_contribution' : 'gift', $attempt->gift_id ?? $attempt->event_contribution_id);
            $allowed = true;
        }
        abort_unless($allowed, 404);
        $data = ['status' => $attempt->state, 'amount' => $attempt->amount, 'currency' => $attempt->currency, 'receiptUrl' => $attempt->state === 'confirmed' ? route('public.giving.receipt', ['subdomain' => $subdomain, 'reference' => $reference]) : null];
        $event = $attempt->event_contribution_id === null ? null : \App\Models\Event::withoutGlobalScopes()->where('tenant_id', $attempt->tenant_id)->whereIn('id', \App\Models\EventContribution::withoutGlobalScopes()->whereKey($attempt->event_contribution_id)->select('event_id'))->whereNull('deleted_at')->first();
        $data['eventUrl'] = $event === null ? null : route('public.events.show', ['subdomain' => $subdomain, 'event' => $event->slug]);
        if ($request->expectsJson()) {
            return response()->json($data)->header('Cache-Control', 'private, no-store');
        }
        $response = \Inertia\Inertia::render('Public/Giving/Result', $data)->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function attempt(string $reference): GivingPaymentAttempt
    {
        $tenant = app(TenantContext::class)->getTenant();

        return GivingPaymentAttempt::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('reference', $reference)->firstOrFail();
    }
}
