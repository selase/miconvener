<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Libraries\Helper;
use App\Mail\Events\EventRegistrationOfflineProofSubmitted;
use App\Mail\Events\EventRegistrationPendingVerification;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Services\Events\PlatformAttendeeWorkspaceAuthorizer;
use App\Services\Payment\PaystackGateway;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

final class EventCheckoutController extends Controller
{
    public function __construct(
        private readonly PlatformAttendeeWorkspaceAuthorizer $workspaceAuthorizer,
    ) {}

    public function checkout(string $subdomain, string $event, string $registration): InertiaResponse|Response|RedirectResponse
    {
        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant) {
            abort(404);
        }

        $registrationModel = EventRegistration::where('tenant_id', $tenant->id)
            ->where('id', $registration)
            ->firstOrFail();

        $workspaceUrl = $this->workspaceAuthorizer->workspaceUrl($registrationModel);

        if ($this->canClaimWorkspace($registrationModel)) {
            $this->workspaceAuthorizer->grantCheckoutAccess(request()->session(), $registrationModel->id);
        }

        if ($registrationModel->isConfirmed()) {
            return redirect($workspaceUrl);
        }

        $eventModel = $registrationModel->event;
        $paystack = $tenant->isPlatformDefaultSettlement()
            ? $this->platformGateway()
            : $this->tenantGateway($tenant);

        // If the event allows offline payments OR this registration already has an offline status/proof,
        // render the interactive Checkout page allowing the attendee to select Paystack or Offline methods.
        if ($eventModel->allowsOfflinePayments() || $registrationModel->offline_payment_status !== null) {
            return Inertia::render('Public/Events/Checkout', [
                'event' => [
                    'name' => $eventModel->name,
                    'slug' => $eventModel->slug,
                    'currency' => $eventModel->currency,
                    'starts_at' => $eventModel->starts_at->toIso8601String(),
                    'ends_at' => $eventModel->ends_at->toIso8601String(),
                    'location_type' => $eventModel->location_type,
                    'address' => $eventModel->address,
                    'hero_image_url' => Helper::storageUrl($eventModel->hero_image_path),
                    'allow_offline_payments' => $eventModel->allowsOfflinePayments(),
                    'offline_payment_instructions' => $eventModel->offline_payment_instructions,
                    'offline_payment_bank_name' => $eventModel->offline_payment_bank_name,
                    'offline_payment_account_name' => $eventModel->offline_payment_account_name,
                    'offline_payment_account_number' => $eventModel->offline_payment_account_number,
                    'offline_payment_momo_number' => $eventModel->offline_payment_momo_number,
                    'offline_payment_momo_network' => $eventModel->offline_payment_momo_network,
                ],
                'registration' => [
                    'id' => $registrationModel->id,
                    'full_name' => $registrationModel->full_name,
                    'email' => $registrationModel->email,
                    'phone' => $registrationModel->phone,
                    'amount' => $registrationModel->amount,
                    'charged_amount' => $registrationModel->effectiveChargedAmount(),
                    'currency' => $registrationModel->currency,
                    'status' => $registrationModel->status,
                    'payment_method' => $registrationModel->payment_method,
                    'offline_payment_status' => $registrationModel->offline_payment_status,
                    'offline_payment_reference' => $registrationModel->offline_payment_reference,
                    'offline_payment_notes' => $registrationModel->offline_payment_notes,
                    'offline_payment_submitted_at' => $registrationModel->offline_payment_submitted_at?->toIso8601String(),
                    'has_proof' => filled($registrationModel->offline_payment_proof_path),
                    'proof_url' => filled($registrationModel->offline_payment_proof_path) ? route('public.events.checkout.proof', [
                        'subdomain' => $tenant->slug,
                        'event' => $eventModel->slug,
                        'registration' => $registrationModel->id,
                    ]) : null,
                    'ticket_type' => $registrationModel->ticketType ? [
                        'id' => $registrationModel->ticketType->id,
                        'name' => $registrationModel->ticketType->name,
                        'price' => $registrationModel->ticketType->price,
                        'description' => $registrationModel->ticketType->description,
                    ] : null,
                ],
                'paystack_available' => (bool) $paystack,
                'workspace_url' => $workspaceUrl,
            ]);
        }

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
            $workspaceUrl,
            [
                'event_registration_id' => $registrationModel->id,
                'tenant_id' => $tenant->id,
                'type' => 'event_ticket',
                'source' => config('services.paystack.metadata_source'),
            ]
        );

        return Inertia::location($checkoutUrl);
    }

    public function payWithPaystack(string $subdomain, string $event, string $registration): Response|RedirectResponse
    {
        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant) {
            abort(404);
        }

        $registrationModel = EventRegistration::where('tenant_id', $tenant->id)
            ->where('id', $registration)
            ->firstOrFail();

        $workspaceUrl = $this->workspaceAuthorizer->workspaceUrl($registrationModel);

        if ($this->canClaimWorkspace($registrationModel)) {
            $this->workspaceAuthorizer->grantCheckoutAccess(request()->session(), $registrationModel->id);
        }

        if ($registrationModel->isConfirmed()) {
            return redirect($workspaceUrl);
        }

        $paystack = $tenant->isPlatformDefaultSettlement()
            ? $this->platformGateway()
            : $this->tenantGateway($tenant);

        if (! $paystack) {
            return redirect()->back()->with('error', 'Online payment via Paystack is currently unavailable for this event.');
        }

        $customerId = $paystack->createCustomer($registrationModel->email, $registrationModel->full_name);

        $checkoutUrl = $paystack->createOneTimeCheckoutSession(
            $customerId,
            $registrationModel->effectiveChargedAmount(),
            $registrationModel->currency,
            $workspaceUrl,
            [
                'event_registration_id' => $registrationModel->id,
                'tenant_id' => $tenant->id,
                'type' => 'event_ticket',
                'source' => config('services.paystack.metadata_source'),
            ]
        );

        return Inertia::location($checkoutUrl);
    }

    public function submitOfflineProof(Request $request, string $subdomain, string $event, string $registration): RedirectResponse
    {
        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant) {
            abort(404);
        }

        $registrationModel = EventRegistration::where('tenant_id', $tenant->id)
            ->where('id', $registration)
            ->firstOrFail();

        if (! $this->canClaimWorkspace($registrationModel)) {
            abort(403, 'Unauthorized to submit payment proof for this registration.');
        }

        $eventModel = $registrationModel->event;
        if (! $eventModel || $eventModel->slug !== $event || ! $eventModel->allowsOfflinePayments()) {
            abort(403, 'Offline payments are not enabled for this event.');
        }

        if ($registrationModel->isConfirmed()) {
            return redirect($this->workspaceAuthorizer->workspaceUrl($registrationModel));
        }

        if ($registrationModel->status === EventRegistration::STATUS_CANCELLED) {
            return redirect()->back()->with('error', 'This registration has been cancelled.');
        }

        if ($registrationModel->effectiveChargedAmount() <= 0) {
            return redirect()->back()->with('error', 'This registration does not require payment.');
        }

        $validated = $request->validate([
            'proof_file' => ['required', 'file', 'mimes:png,jpg,jpeg,webp,pdf', 'max:10240'],
            'payment_method' => ['required', 'string', Rule::in([
                EventRegistration::PAYMENT_METHOD_OFFLINE_BANK,
                EventRegistration::PAYMENT_METHOD_OFFLINE_MOMO,
                EventRegistration::PAYMENT_METHOD_OFFLINE_CASH,
            ])],
            'offline_payment_reference' => ['nullable', 'string', 'max:128'],
            'offline_payment_notes' => ['nullable', 'string', 'max:500'],
        ], [
            'proof_file.required' => 'Please upload an image or PDF of your payment slip or transfer confirmation.',
            'proof_file.mimes' => 'The payment proof must be an image (PNG, JPG, WebP) or PDF file.',
            'proof_file.max' => 'The payment proof file size cannot exceed 10MB.',
        ]);

        $disk = Event::uploadDisk();
        $storedPath = Helper::processUploadedFile($request, 'proof_file', 'proof', "events/{$registrationModel->event_id}/proofs", $disk);

        if ($registrationModel->offline_payment_proof_path) {
            Helper::deleteFile($registrationModel->offline_payment_proof_path, $disk);
        }

        $registrationModel->update([
            'payment_method' => $validated['payment_method'],
            'offline_payment_status' => EventRegistration::OFFLINE_STATUS_PENDING_VERIFICATION,
            'offline_payment_proof_path' => $storedPath,
            'offline_payment_reference' => $validated['offline_payment_reference'] ?? null,
            'offline_payment_notes' => $validated['offline_payment_notes'] ?? null,
            'offline_payment_submitted_at' => now(),
        ]);

        Mail::to($registrationModel->email)->queue(new EventRegistrationPendingVerification($registrationModel));

        if (filled($tenant->email)) {
            Mail::to($tenant->email)->queue(new EventRegistrationOfflineProofSubmitted($registrationModel));
        }

        return redirect()->back()->with('success', 'Your proof of payment has been submitted. The organizer will review your payment and email your confirmed ticket.');
    }

    public function downloadProof(string $subdomain, string $event, string $registration): Response
    {
        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant) {
            abort(404);
        }

        $registrationModel = EventRegistration::where('tenant_id', $tenant->id)
            ->where('id', $registration)
            ->firstOrFail();

        if (! $this->canClaimWorkspace($registrationModel)) {
            abort(403, 'Unauthorized to view this payment proof.');
        }

        if (! $registrationModel->offline_payment_proof_path) {
            abort(404, 'No payment proof uploaded.');
        }

        $expectedPrefix = "events/{$registrationModel->event_id}/proofs/";
        if (! str_starts_with($registrationModel->offline_payment_proof_path, $expectedPrefix) || str_contains($registrationModel->offline_payment_proof_path, '..')) {
            abort(403, 'Invalid proof storage path.');
        }

        $disk = Event::uploadDisk();
        if (! Storage::disk($disk)->exists($registrationModel->offline_payment_proof_path)) {
            abort(404, 'Proof file not found on storage.');
        }

        $mime = Storage::disk($disk)->mimeType($registrationModel->offline_payment_proof_path) ?: 'application/octet-stream';
        $ext = pathinfo($registrationModel->offline_payment_proof_path, PATHINFO_EXTENSION);

        return Storage::disk($disk)->response(
            $registrationModel->offline_payment_proof_path,
            "payment-proof-{$registrationModel->id}.{$ext}",
            [
                'Content-Type' => $mime,
                'Cache-Control' => 'private, no-store',
            ]
        );
    }

    /**
     * Whether this browser may be handed workspace access for a registration
     * it is about to pay for.
     */
    private function canClaimWorkspace(EventRegistration $registration): bool
    {
        if ($this->workspaceAuthorizer->canAccess(request()->session(), $registration)) {
            return true;
        }

        if (request()->hasValidSignature()) {
            return true;
        }

        return $this->workspaceAuthorizer->registeredInThisSession(request()->session(), $registration->id);
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
