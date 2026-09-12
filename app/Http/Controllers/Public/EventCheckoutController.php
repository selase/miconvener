<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Services\Payment\PaystackGateway;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class EventCheckoutController extends Controller
{
    public function checkout(string $subdomain, string $event, string $registration): Response|RedirectResponse
    {
        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant) {
            abort(404);
        }

        $registrationModel = EventRegistration::where('tenant_id', $tenant->id)
            ->where('id', $registration)
            ->firstOrFail();

        if ($registrationModel->isConfirmed()) {
            return redirect()->route('public.events.confirmation', [
                'subdomain' => $tenant->slug,
                'event' => $event,
                'registration' => $registrationModel->id,
            ]);
        }

        $paystack = $tenant->isPlatformDefaultSettlement()
            ? $this->platformGateway()
            : $this->tenantGateway($tenant);

        if (! $paystack) {
            $hasOtherActiveGateway = ! $tenant->isPlatformDefaultSettlement() && TenantPaymentGateway::where('tenant_id', $tenant->id)
                ->where('is_active', true)
                ->exists();

            $message = $hasOtherActiveGateway
                ? 'This event currently only accepts payments via Paystack, which is not configured for this organizer.'
                : 'This event is not currently accepting payments.';

            return redirect()->back()->with('error', $message);
        }

        $customerId = $paystack->createCustomer($registrationModel->email, $registrationModel->full_name);

        $checkoutUrl = $paystack->createOneTimeCheckoutSession(
            $customerId,
            $registrationModel->effectiveChargedAmount(),
            $registrationModel->currency,
            route('public.events.confirmation', [
                'subdomain' => $tenant->slug,
                'event' => $event,
                'registration' => $registrationModel->id,
            ]),
            [
                'event_registration_id' => $registrationModel->id,
                'tenant_id' => $tenant->id,
                'type' => 'event_ticket',
                'source' => config('services.paystack.metadata_source'),
            ]
        );

        // The attendee arrives here from an Inertia form submission, which follows
        // redirects with XHR. A plain redirect to Paystack's domain is therefore
        // blocked by CORS and the attendee sees only a network error. Inertia\'s
        // location response tells the client to perform a full page visit instead,
        // and degrades to an ordinary 302 for non-Inertia requests.
        return Inertia::location($checkoutUrl);
    }

    private function tenantGateway(Tenant $tenant): ?PaystackGateway
    {
        $gateway = TenantPaymentGateway::where('tenant_id', $tenant->id)
            ->where('provider', 'paystack')
            ->where('is_active', true)
            ->first();

        return $gateway ? new PaystackGateway(['secret_key' => $gateway->api_key_encrypted]) : null;
    }

    private function platformGateway(): ?PaystackGateway
    {
        $secret = config('services.settlement.paystack.secret_key');

        return $secret ? new PaystackGateway(['secret_key' => $secret]) : null;
    }
}
