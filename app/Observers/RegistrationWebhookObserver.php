<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\EventRegistration;
use App\Services\Webhooks\WebhookDispatcherService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Sends a tenant's registration webhooks from the model, so every path that
 * registers, confirms, cancels or checks someone in reports it: checkout,
 * free registration, the console, imports and the door scanner alike. It runs
 * after the transaction commits, so a rolled-back change is never reported.
 */
final class RegistrationWebhookObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly WebhookDispatcherService $webhooks) {}

    public function created(EventRegistration $registration): void
    {
        $this->webhooks->dispatchRegistrationCreated($registration);

        if ($registration->status === EventRegistration::STATUS_CONFIRMED) {
            $this->webhooks->dispatchRegistrationConfirmed($registration);
        }
    }

    public function updated(EventRegistration $registration): void
    {
        if ($registration->wasChanged('offline_payment_proof_path') && filled($registration->offline_payment_proof_path)) {
            $this->webhooks->dispatchOfflinePaymentSubmitted($registration);
        }

        if (! $registration->wasChanged('status')) {
            return;
        }

        $previous = $registration->getOriginal('status');

        match ($registration->status) {
            // Undoing a check-in returns the ticket to confirmed; it was
            // confirmed already, so that is not reported a second time.
            EventRegistration::STATUS_CONFIRMED => $previous === EventRegistration::STATUS_CHECKED_IN
                ? null
                : $this->webhooks->dispatchRegistrationConfirmed($registration),
            EventRegistration::STATUS_CANCELLED => $this->webhooks->dispatchRegistrationCancelled($registration),
            EventRegistration::STATUS_CHECKED_IN => $this->webhooks->dispatchTicketCheckedIn($registration),
            default => null,
        };
    }
}
