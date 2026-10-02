<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventContribution;
use App\Models\Tenant;
use App\Services\Events\EventContributionService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class EventContributionController extends Controller
{
    public function __construct(
        private readonly EventContributionService $contributionService,
    ) {}

    public function store(Request $request, string $subdomain, string $event): Response|RedirectResponse
    {
        $tenant = $this->getTenant();

        $eventModel = Event::where('tenant_id', $tenant->id)
            ->where('slug', $event)
            ->published()
            ->firstOrFail();

        if (! $eventModel->allowsContributions()) {
            return redirect()->route('public.events.show', [
                'subdomain' => $tenant->slug,
                'event' => $eventModel->slug,
            ])->with('error', 'This event is not currently accepting voluntary contributions.');
        }

        $minAmountPesewas = $eventModel->contribution_min_amount_pesewas ?? 100;
        $minAmountGhs = $minAmountPesewas / 100;

        $validated = $request->validate([
            'contributor_name' => ['required', 'string', 'max:255'],
            'contributor_email' => ['nullable', 'email', 'max:255'],
            'contributor_phone' => ['nullable', 'string', 'max:50'],
            'amount' => ['required', 'numeric', 'min:'.$minAmountGhs, 'max:100000'],
            'tribute_message' => ['nullable', 'string', 'max:1000'],
            'is_anonymous' => ['sometimes', 'boolean'],
        ]);

        $callbackUrl = route('public.events.contributions.callback', [
            'subdomain' => $tenant->slug,
            'event' => $eventModel->slug,
            'contribution' => ':id',
        ]);

        try {
            $checkoutUrl = $this->contributionService->initiateContribution($eventModel, $validated, $callbackUrl);

            return Inertia::location($checkoutUrl);
        } catch (Throwable $e) {
            Log::error('Contribution checkout initialization failed', [
                'event_id' => $eventModel->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->back()->with('error', $e->getMessage() ?: 'Unable to initiate contribution payment.');
        }
    }

    public function callback(Request $request, string $subdomain, string $event, string $contribution): RedirectResponse
    {
        $tenant = $this->getTenant();

        $eventModel = Event::where('tenant_id', $tenant->id)
            ->where('slug', $event)
            ->firstOrFail();

        /** @var EventContribution $contributionModel */
        $contributionModel = EventContribution::where('tenant_id', $tenant->id)
            ->where('event_id', $eventModel->id)
            ->where('id', $contribution)
            ->firstOrFail();

        if ($contributionModel->isCompleted()) {
            return redirect()->route('public.events.show', [
                'subdomain' => $tenant->slug,
                'event' => $eventModel->slug,
            ])->with('success', 'Thank you for your generous contribution!');
        }

        $reference = (string) ($request->query('reference') ?: $request->query('trxref') ?: $contributionModel->payment_reference);

        if ($reference === '') {
            return redirect()->route('public.events.show', [
                'subdomain' => $tenant->slug,
                'event' => $eventModel->slug,
            ])->with('error', 'No payment reference received from payment gateway.');
        }

        $gateway = $this->contributionService->resolveGateway($tenant);
        if (! $gateway) {
            return redirect()->route('public.events.show', [
                'subdomain' => $tenant->slug,
                'event' => $eventModel->slug,
            ])->with('error', 'Payment gateway configuration could not be resolved.');
        }

        try {
            $verification = $gateway->verifyTransaction($reference);

            if (($verification['status'] ?? null) === 'success') {
                $verificationMetadata = \App\Http\Controllers\Billing\WebhookController::metadata($verification['metadata'] ?? null);

                $matchesContribution = (
                    ((string) ($verificationMetadata['event_contribution_id'] ?? '')) === (string) $contributionModel->id
                    || ((string) ($verification['reference'] ?? '')) === $contributionModel->payment_reference
                    || ((string) ($verification['reference'] ?? '')) === $reference
                );

                $verifiedAmount = (int) ($verification['amount'] ?? 0);
                $verifiedCurrency = mb_strtoupper((string) ($verification['currency'] ?? ''));
                $expectedCurrency = mb_strtoupper((string) ($eventModel->currency ?: 'GHS'));

                if (! $matchesContribution || $verifiedAmount < $contributionModel->amount || $verifiedCurrency !== $expectedCurrency) {
                    Log::warning('Paystack verification metadata or amount mismatch for contribution', [
                        'contribution_id' => $contributionModel->id,
                        'reference' => $reference,
                        'verification' => $verification,
                    ]);

                    return redirect()->route('public.events.show', [
                        'subdomain' => $tenant->slug,
                        'event' => $eventModel->slug,
                    ])->with('error', 'Payment verification failed: transaction details did not match this contribution.');
                }

                $this->contributionService->confirmContributionPayment($reference, [
                    ...$verification,
                    'metadata' => $verificationMetadata,
                    'amount' => $verifiedAmount,
                    'fees' => (int) ($verification['fees'] ?? 0),
                ]);

                return redirect()->route('public.events.show', [
                    'subdomain' => $tenant->slug,
                    'event' => $eventModel->slug,
                ])->with('success', 'Thank you for your generous contribution!');
            }

            return redirect()->route('public.events.show', [
                'subdomain' => $tenant->slug,
                'event' => $eventModel->slug,
            ])->with('warning', 'Payment could not be verified automatically. If money was debited, your contribution will reflect shortly.');
        } catch (Throwable $e) {
            Log::error('Event contribution payment verification exception', [
                'contribution_id' => $contributionModel->id,
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('public.events.show', [
                'subdomain' => $tenant->slug,
                'event' => $eventModel->slug,
            ])->with('info', 'We are finalizing your payment with Paystack. Your contribution will appear momentarily.');
        }
    }

    private function getTenant(): Tenant
    {
        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant) {
            abort(404);
        }

        return $tenant;
    }
}
