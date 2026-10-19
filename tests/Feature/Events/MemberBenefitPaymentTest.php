<?php

declare(strict_types=1);

use App\Mail\Events\EventRegistrationConfirmed;
use App\Models\EventLedgerEntry;
use App\Models\LedgerTransaction;
use App\Services\Events\MemberBenefitPayments;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Support\MemberBenefitScenario;

beforeEach(fn () => Mail::fake());

it('records captured money once and fulfils one ticket using frozen terms', function (string $provider): void {
    [, $event, , $registration, , $attempt] = MemberBenefitScenario::make($provider);
    $payment = MemberBenefitScenario::payment($attempt);
    $event->update(['platform_fee_percentage' => 25]);
    $confirmer = app(MemberBenefitPayments::class);
    $confirmer->confirm($attempt, $payment);
    $confirmer->confirm($attempt->fresh(), $payment);
    expect($registration->fresh()->status)->toBe('confirmed')->and($registration->fresh()->ticket_code)->not->toBeNull()
        ->and($attempt->fresh()->state)->toBe('fulfilled')
        ->and(EventLedgerEntry::query()->where('registration_id', $registration->id)->where('type', 'charge')->count())->toBe(1)
        ->and(EventLedgerEntry::query()->where('registration_id', $registration->id)->firstOrFail()->commission_amount)->toBe($attempt->platform_fee_amount);
    expect(App\Models\MerchantTransaction::withoutGlobalScopes()->where('provider_transaction_id', $attempt->fresh()->provider_reference)->count())->toBe($provider === 'platform' ? 0 : 1);
    Mail::assertQueued(EventRegistrationConfirmed::class, 1);
})->with(['platform', 'own-paystack', 'stripe']);

it('honours capture inside the quote when webhook delivery is late and membership has changed', function (string $status): void {
    [, , $member, $registration, $quote, $attempt] = MemberBenefitScenario::make();
    $payment = MemberBenefitScenario::payment($attempt);
    $payment['paid_at'] = $quote->expires_at->subSecond();
    $member->update(['status' => $status]);
    $this->travel(2)->days();
    app(MemberBenefitPayments::class)->confirm($attempt, $payment);
    expect($registration->fresh()->status)->toBe('confirmed')->and($attempt->fresh()->state)->toBe('fulfilled');
})->with(['lapsed', 'exited']);

it('retains a truthful paid review with no ticket for late uncertain revoked cancelled or sold-out captures', function (string $reason): void {
    [$tenant, $event, $member, $registration, $quote, $attempt] = MemberBenefitScenario::make();
    $payment = MemberBenefitScenario::payment($attempt);
    if ($reason === 'late') {
        $payment['paid_at'] = $quote->expires_at;
    } elseif ($reason === 'missing time') {
        $payment['paid_at'] = null;
    } elseif ($reason === 'identity') {
        $member->update(['email' => 'different@example.test']);
    } elseif ($reason === 'cancelled') {
        $event->update(['status' => 'cancelled']);
    } else {
        $event->update(['capacity' => 1]);
        App\Models\EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'status' => 'confirmed']);
    }
    app(MemberBenefitPayments::class)->confirm($attempt, $payment);
    expect($attempt->fresh()->state)->toBe('paid_review')->and($registration->fresh()->ticket_code)->toBeNull()
        ->and($registration->fresh()->status)->toBe('pending_benefit_review')
        ->and(EventLedgerEntry::query()->where('registration_id', $registration->id)->count())->toBe(1);
    Mail::assertNothingQueued();
})->with(['late', 'missing time', 'identity', 'cancelled', 'capacity']);

it('refuses mismatched account reference provider amount or currency without issuing a ticket', function (string $field): void {
    [, , , $registration, , $attempt] = MemberBenefitScenario::make();
    $payment = MemberBenefitScenario::payment($attempt);
    $payment[$field] = $field === 'amount' ? 1 : 'wrong';
    expect(fn () => app(MemberBenefitPayments::class)->confirm($attempt, $payment))->toThrow(ValidationException::class);
    expect($registration->fresh()->ticket_code)->toBeNull()->and(LedgerTransaction::query()->count())->toBe(0);
    if (in_array($field, ['amount', 'currency'], true)) {
        expect($attempt->fresh()->state)->toBe('unknown')->and($attempt->fresh()->observed_payment[$field])->toBe($payment[$field]);
    }
})->with(['provider', 'account_key', 'reference', 'amount', 'currency']);

it('continues an original captured payment after rollout and settlement mode changes', function (): void {
    [$tenant, , , $registration, , $attempt] = MemberBenefitScenario::make();
    $tenant->features()->where('feature_key', 'community_event_benefits')->update(['enabled' => false]);
    $tenant->update(['settlement_mode' => 'own_gateway']);
    app(MemberBenefitPayments::class)->confirm($attempt, MemberBenefitScenario::payment($attempt));
    expect($registration->fresh()->status)->toBe('confirmed')->and($attempt->fresh()->settlement_mode)->toBe('platform_default');
});

it('records an unexpected old capture alongside a newer attempt without issuing two tickets', function (): void {
    [, , , $registration, $quote, $old] = MemberBenefitScenario::make();
    $old->update(['state' => 'failed']);
    $current = App\Models\EventMemberBenefitAttempt::factory()->forQuote($quote)->create(['state' => 'open', 'account_key' => $old->account_key]);
    $service = app(MemberBenefitPayments::class);
    $service->confirm($old->fresh(), MemberBenefitScenario::payment($old));
    expect($old->fresh()->state)->toBe('paid_extra')->and($registration->fresh()->ticket_code)->toBeNull();
    $service->confirm($current, MemberBenefitScenario::payment($current));
    expect($registration->fresh()->status)->toBe('confirmed')->and($current->fresh()->state)->toBe('fulfilled')
        ->and(EventLedgerEntry::query()->where('registration_id', $registration->id)->count())->toBe(2);
    Mail::assertQueued(EventRegistrationConfirmed::class, 1);
});

it('does not hide a valid current ticket when an old extra capture has no fee evidence', function (): void {
    [, , , $registration, $quote, $old] = MemberBenefitScenario::make();
    $old->update(['state' => 'failed']);
    $current = App\Models\EventMemberBenefitAttempt::factory()->forQuote($quote)->create(['state' => 'open', 'account_key' => $old->account_key]);
    $service = app(MemberBenefitPayments::class);
    $service->confirm($current, MemberBenefitScenario::payment($current));
    $ticket = $registration->fresh()->ticket_code;
    $payment = MemberBenefitScenario::payment($old);
    $payment['gateway_fee'] = null;
    $service->confirm($old->fresh(), $payment);
    expect($old->fresh()->state)->toBe('paid_extra')->and($old->fresh()->is_extra_capture)->toBeTrue()
        ->and($registration->fresh()->status)->toBe('confirmed')->and($registration->fresh()->ticket_code)->toBe($ticket);
});

it('preserves independent and unlimited ticket type capacity for member payments', function (bool $unlimited): void {
    [$tenant, $event, , $registration, , $attempt] = MemberBenefitScenario::make('platform', true);
    $event->update(['capacity' => 1]);
    if ($unlimited) {
        $registration->ticketType->update(['capacity' => null]);
        $other = $registration->ticketType;
    } else {
        $other = App\Models\EventTicketType::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);
    }
    App\Models\EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'ticket_type_id' => $other->id, 'status' => 'confirmed']);
    app(MemberBenefitPayments::class)->confirm($attempt, MemberBenefitScenario::payment($attempt));
    expect($attempt->fresh()->state)->toBe('fulfilled')->and($registration->fresh()->ticket_code)->not->toBeNull();
})->with([false, true]);

it('retires only a proven uncaptured failed attempt and never downgrades captured evidence', function (string $state): void {
    [, , , $registration, , $attempt] = MemberBenefitScenario::make();
    if ($state === 'fulfilled') {
        app(MemberBenefitPayments::class)->confirm($attempt, MemberBenefitScenario::payment($attempt));
    } elseif ($state === 'observed') {
        $attempt->update(['state' => 'unknown', 'observed_payment' => ['amount' => 5000]]);
    }
    app(MemberBenefitPayments::class)->recordFailure($attempt->fresh(), ['status' => 'failed', 'provider' => $attempt->provider, 'account_key' => $attempt->account_key, 'reference' => $attempt->reference]);
    expect($attempt->fresh()->state)->toBe(match ($state) {
        'fulfilled' => 'fulfilled', 'observed' => 'unknown', default => 'failed'
    });
    expect($registration->fresh()->ticket_code !== null)->toBe($state === 'fulfilled');
})->with(['open', 'fulfilled', 'observed']);
