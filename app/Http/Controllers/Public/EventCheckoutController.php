<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\EventRegistration;
use App\Models\TenantPaymentGateway;
use App\Services\Payment\PaystackGateway;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;

final class EventCheckoutController extends Controller
{
    public function checkout(string $subdomain, string $event, string $registration): RedirectResponse
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

        $gateway = TenantPaymentGateway::where('tenant_id', $tenant->id)
            ->where('provider', 'paystack')
            ->where('is_active', true)
            ->first();

        if (! $gateway) {
            return redirect()->back()->with('error', 'This event is not currently accepting payments.');
        }

        $paystack = new PaystackGateway(['secret_key' => $gateway->api_key_encrypted]);
        $customerId = $paystack->createCustomer($registrationModel->email, $registrationModel->full_name);

        $checkoutUrl = $paystack->createOneTimeCheckoutSession(
            $customerId,
            $registrationModel->amount,
            $registrationModel->currency,
            route('public.events.confirmation', [
                'subdomain' => $tenant->slug,
                'event' => $event,
                'registration' => $registrationModel->id,
            ]),
            [
                'event_registration_id' => $registrationModel->id,
                'type' => 'event_ticket',
            ]
        );

        return redirect($checkoutUrl);
    }
}
