<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Http\Controllers\Billing\WebhookController;
use App\Models\EventMemberBenefitAttempt;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Services\Giving\GivingGateways;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/** Dispatch only after the controller has authenticated the provider notification. */
final class MemberBenefitNotifications
{
    public function __construct(private readonly MemberBenefitGateways $gateways, private readonly MemberBenefitPayments $payments) {}

    /** @param array<string, mixed> $data */
    public function paystack(array $data, string $accountKey, ?Tenant $tenant = null): bool
    {
        $metadata = WebhookController::metadata($data['metadata'] ?? null);
        $attempt = EventMemberBenefitAttempt::withoutGlobalScopes()->where('provider', 'paystack')->where('reference', (string) ($data['reference'] ?? ''))->first();
        if ($attempt === null) {
            return $this->isBound($metadata, $tenant);
        }
        if (! hash_equals($attempt->account_key, $accountKey) || ($tenant !== null && $tenant->id !== $attempt->tenant_id)
            || ($tenant === null && $attempt->settlement_mode !== Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT)) {
            throw ValidationException::withMessages(['payment' => 'The captured payment account does not match its checkout.']);
        }
        $this->payments->confirm($attempt, [
            'status' => 'success', 'provider' => 'paystack', 'account_key' => $accountKey, 'reference' => $attempt->reference,
            'provider_reference' => $attempt->reference, 'amount' => $data['amount'] ?? null,
            'currency' => mb_strtoupper((string) ($data['currency'] ?? '')), 'gateway_fee' => $data['fees'] ?? null,
            'commission_received' => is_numeric($data['fees_split']['subaccount'] ?? null) ? (int) $data['fees_split']['subaccount'] : 0,
            'paid_at' => $this->gateways->completionTime($data['paid_at'] ?? null),
        ]);

        return true;
    }

    public function stripe(Tenant $tenant, TenantPaymentGateway $gateway, object $session, ?int $completionTimestamp): bool
    {
        $metadata = isset($session->metadata) ? $session->metadata->toArray() : [];
        $attempt = EventMemberBenefitAttempt::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('provider', 'stripe')
            ->where('provider_session_id', $session->id)->first();
        if ($attempt === null) {
            return $this->isBound($metadata, $tenant);
        }
        if (! hash_equals($attempt->account_key, app(GivingGateways::class)->identityForRow($gateway))) {
            throw ValidationException::withMessages(['payment' => 'The captured payment account does not match its checkout.']);
        }
        $verified = $this->gateways->verify($attempt, $completionTimestamp !== null ? CarbonImmutable::createFromTimestampUTC($completionTimestamp) : null);
        if (($verified['status'] ?? null) === 'success') {
            $this->payments->confirm($attempt, $verified);
        }

        return true;
    }

    /** @param array<string, mixed> $metadata */
    private function isBound(array $metadata, ?Tenant $tenant): bool
    {
        if (isset($metadata['member_benefit_attempt_id']) || isset($metadata['member_benefit_reference'])) {
            return true;
        }
        $registration = EventRegistration::withoutGlobalScopes()->where('id', $metadata['event_registration_id'] ?? null)
            ->when($tenant !== null, fn ($query) => $query->where('tenant_id', $tenant->id))->first();

        return $registration?->member_benefit_required ?? false;
    }
}
