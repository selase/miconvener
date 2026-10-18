<?php

declare(strict_types=1);

use App\Models\EventRegistration;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\Support\MemberBenefitScenario;

beforeEach(fn () => Mail::fake());

function lifecycleStaff(App\Models\Tenant $tenant): User
{
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $tenant->users()->attach($user->id);
    setPermissionsTeamId($tenant->id);
    $user->givePermissionTo('update event');
    test()->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost($tenant->slug)]);

    return $user;
}

it('rechecks independent member proof before issuing a free ticket from an old signed link', function (string $change): void {
    [$tenant, $event, $member, $registration, , $attempt] = MemberBenefitScenario::make();
    $attempt->update(['state' => 'failed']);
    $registration->update(['amount' => 0, 'charged_amount' => 0, 'status' => 'confirmed', 'email_verified_at' => null]);
    if ($change === 'lapsed') {
        $member->update(['status' => 'lapsed']);
    } elseif ($change === 'contact') {
        $member->update(['email' => 'reassigned@example.test']);
    } else {
        $this->travel(2)->hours();
    }
    $url = URL::temporarySignedRoute('public.events.registrations.verify', now()->addDay(), ['subdomain' => $tenant->slug, 'event' => $event->slug, 'registration' => $registration->id, 'recipient' => $registration->memberBenefitRecipientToken()]);
    $this->get($url, ['HTTP_HOST' => eventSubdomainHost($tenant->slug)])->assertRedirect();
    expect($registration->fresh()->ticket_code)->toBeNull()->and($registration->fresh()->status)->toBe('pending_member_verification');
    Mail::assertNothingQueued();
})->with(['lapsed', 'contact', 'expired']);

it('does not let an organiser substitute for current personal proof on approval or offline payment', function (string $path): void {
    [$tenant, $event, $member, $registration, , $attempt] = MemberBenefitScenario::make();
    lifecycleStaff($tenant);
    $attempt->update(['state' => 'failed']);
    $registration->update(['status' => $path === 'approve' ? 'pending_approval' : 'pending_payment', 'offline_payment_status' => 'pending_verification']);
    $member->update(['status' => 'lapsed']);
    $this->postJson('http://'.eventSubdomainHost($tenant->slug).'/events/'.$event->id.'/registrations/'.$registration->id.'/'.$path)->assertUnprocessable();
    expect($registration->fresh()->ticket_code)->toBeNull()->and(App\Models\EventLedgerEntry::query()->count())->toBe(0);
})->with(['approve', 'approve-offline']);

it('blocks both stages of transferring a benefit-bound ticket without sending a code', function (): void {
    [$tenant, $event, , $registration] = MemberBenefitScenario::make();
    $registration->update(['status' => 'confirmed']);
    $registration->issueTicket();
    $registration->save();
    $url = 'http://'.eventSubdomainHost($tenant->slug).'/e/'.$event->slug.'/registrations/'.$registration->id.'/transfer';
    $this->withServerVariables(['HTTP_HOST' => eventSubdomainHost($tenant->slug)])->postJson($url, ['full_name' => 'Other', 'email' => 'other@example.test'])->assertUnprocessable();
    $this->postJson($url.'/confirm', ['code' => '123456'])->assertUnprocessable();
    expect(App\Models\EventRegistrationTransfer::query()->count())->toBe(0);
    Mail::assertNothingQueued();
});

it('blocks confirmed recipient replacement while allowing harmless name corrections', function (): void {
    [$tenant, $event, , $registration] = MemberBenefitScenario::make();
    lifecycleStaff($tenant);
    $registration->update(['status' => 'confirmed']);
    $registration->issueTicket();
    $registration->save();
    $url = 'http://'.eventSubdomainHost($tenant->slug).'/events/'.$event->id.'/registrations/'.$registration->id;
    $this->patchJson($url, ['full_name' => 'Corrected Name', 'email' => 'replacement@example.test'])->assertUnprocessable();
    $this->patchJson($url, ['full_name' => 'Corrected Name', 'email' => $registration->email])->assertSuccessful();
    expect($registration->fresh()->full_name)->toBe('Corrected Name')->and($registration->fresh()->email)->toBe($registration->email);
});

it('invalidates unfulfilled identity and quotes after a staff email correction without granting the new mailbox access', function (): void {
    [$tenant, $event, , $registration, $quote, $attempt] = MemberBenefitScenario::make();
    lifecycleStaff($tenant);
    $attempt->update(['state' => 'failed']);
    $url = 'http://'.eventSubdomainHost($tenant->slug).'/events/'.$event->id.'/registrations/'.$registration->id;
    $this->patchJson($url, ['full_name' => 'Corrected', 'email' => 'corrected@example.test'])->assertSuccessful();
    expect($registration->fresh()->member_benefit_identity)->toBeNull()->and($registration->fresh()->member_benefit_quote_id)->toBeNull()
        ->and($registration->fresh()->email_verified_at)->toBeNull()->and($quote->fresh()->state)->toBe('invalidated');
});

it('holds a lapsed waitlist promotion without rolling back the cancelled ticket', function (): void {
    [$tenant, $event, $member, $waiting, , $attempt] = MemberBenefitScenario::make();
    lifecycleStaff($tenant);
    $attempt->update(['state' => 'failed']);
    $waiting->update(['amount' => 0, 'charged_amount' => 0, 'status' => 'waitlisted', 'waitlist_position' => 1]);
    $current = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'status' => 'confirmed', 'amount' => 0]);
    $member->update(['status' => 'lapsed']);
    $this->postJson('http://'.eventSubdomainHost($tenant->slug).'/events/'.$event->id.'/registrations/'.$current->id.'/cancel')->assertSuccessful();
    expect($current->fresh()->status)->toBe('cancelled')->and($waiting->fresh()->ticket_code)->toBeNull()
        ->and($waiting->fresh()->status)->toBe('pending_member_verification');
});

it('keeps an issued member ticket valid after lapse and rollout off', function (): void {
    [$tenant, , $member, $registration] = MemberBenefitScenario::make();
    $registration->update(['status' => 'confirmed']);
    $registration->issueTicket();
    $registration->save();
    $ticket = $registration->ticket_code;
    $member->update(['status' => 'lapsed']);
    $tenant->features()->where('feature_key', 'community_event_benefits')->update(['enabled' => false]);
    expect($registration->fresh()->isConfirmed())->toBeTrue()->and($registration->fresh()->ticket_code)->toBe($ticket);
});

it('reverifies a held no-dues free ticket with fresh email proof and issues it only once', function (): void {
    [$tenant, $event, $member, $registration, , $attempt, $session] = MemberBenefitScenario::make();
    $attempt->update(['state' => 'failed']);
    $event->update(['member_ticket_price' => 0]);
    $registration->update(['amount' => 0, 'charged_amount' => 0, 'status' => 'pending_member_verification',
        'member_benefit_identity' => [...$registration->member_benefit_identity, 'release_reason' => 'promoted']]);
    $this->travel(2)->hours();
    $session->put(App\Services\Events\PlatformAttendeeVerification::SESSION_KEY, ['email' => $member->email, 'expires_at' => now()->addHour()->timestamp]);
    $host = eventSubdomainHost($tenant->slug);
    $url = 'http://'.$host.'/e/'.$event->slug.'/checkout/'.$registration->id.'/member-benefits/reverify';
    $this->withServerVariables(['HTTP_HOST' => $host])->withSession($session->all())->postJson($url)->assertSuccessful();
    $ticket = $registration->fresh()->ticket_code;
    $this->postJson($url)->assertSuccessful();
    expect($ticket)->not->toBeNull()->and($registration->fresh()->ticket_code)->toBe($ticket)->and($registration->fresh()->status)->toBe('confirmed');
    Mail::assertQueued(App\Mail\Events\EventRegistrationConfirmed::class, 1);
});

it('does not let free confirmation consume capacity that has already been taken', function (): void {
    [$tenant, $event, , $registration, , $attempt] = MemberBenefitScenario::make();
    $attempt->update(['state' => 'failed']);
    $event->update(['capacity' => 1]);
    $registration->update(['amount' => 0, 'charged_amount' => 0, 'status' => 'confirmed', 'email_verified_at' => null]);
    EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'status' => 'confirmed']);
    $url = URL::temporarySignedRoute('public.events.registrations.verify', now()->addDay(), ['subdomain' => $tenant->slug, 'event' => $event->slug, 'registration' => $registration->id, 'recipient' => $registration->memberBenefitRecipientToken()]);
    $this->get($url, ['HTTP_HOST' => eventSubdomainHost($tenant->slug)])->assertRedirect();
    expect($registration->fresh()->ticket_code)->toBeNull()->and($registration->fresh()->status)->toBe('pending_member_verification');
});

it('cancels a fulfilled member payment through asynchronous original-account refund recovery', function (): void {
    config(['services.settlement.paystack.secret_key' => 'sk_test_lifecycle_cancel']);
    [$tenant, $event, , $registration, , $attempt] = MemberBenefitScenario::make();
    lifecycleStaff($tenant);
    $attempt->update(['account_key' => app(App\Services\Giving\GivingGateways::class)->platformIdentity()]);
    app(App\Services\Events\MemberBenefitPayments::class)->confirm($attempt, MemberBenefitScenario::payment($attempt));
    Illuminate\Support\Facades\Http::fake(['api.paystack.co/refund' => Illuminate\Support\Facades\Http::response(['data' => ['id' => 42, 'status' => 'pending']])]);
    $this->postJson('http://'.eventSubdomainHost($tenant->slug).'/events/'.$event->id.'/registrations/'.$registration->id.'/cancel')->assertSuccessful();
    expect($attempt->fresh()->state)->toBe('refund_pending')->and($registration->fresh()->status)->toBe('cancelled');
    Illuminate\Support\Facades\Http::assertSentCount(1);
});

it('accepts an active independently verified member at free confirmation', function (): void {
    [$tenant, $event, , $registration, , $attempt] = MemberBenefitScenario::make();
    $attempt->update(['state' => 'failed']);
    $event->update(['member_ticket_price' => 0]);
    $registration->update(['amount' => 0, 'charged_amount' => 0, 'status' => 'confirmed', 'email_verified_at' => null]);
    $url = URL::temporarySignedRoute('public.events.registrations.verify', now()->addDay(), ['subdomain' => $tenant->slug, 'event' => $event->slug, 'registration' => $registration->id, 'recipient' => $registration->memberBenefitRecipientToken()]);
    $this->get($url, ['HTTP_HOST' => eventSubdomainHost($tenant->slug)])->assertRedirect();
    expect($registration->fresh()->status)->toBe('confirmed')->and($registration->fresh()->ticket_code)->not->toBeNull();
});

it('does not honour an old zero-price member registration after the member base price changes', function (): void {
    [$tenant, $event, , $registration, , $attempt] = MemberBenefitScenario::make();
    $attempt->update(['state' => 'failed']);
    $registration->update(['amount' => 0, 'charged_amount' => 0, 'status' => 'confirmed', 'email_verified_at' => null]);
    $url = URL::temporarySignedRoute('public.events.registrations.verify', now()->addDay(), ['subdomain' => $tenant->slug, 'event' => $event->slug, 'registration' => $registration->id, 'recipient' => $registration->memberBenefitRecipientToken()]);
    $this->get($url, ['HTTP_HOST' => eventSubdomainHost($tenant->slug)])->assertRedirect();
    expect($registration->fresh()->ticket_code)->toBeNull()->and($registration->fresh()->status)->toBe('pending_member_verification');
});

it('requires refreshed proof for a late offline approval and posts it once after reverification', function (): void {
    [$tenant, $event, $member, $registration, , $attempt, $session] = MemberBenefitScenario::make();
    lifecycleStaff($tenant);
    $attempt->update(['state' => 'failed']);
    $registration->update(['offline_payment_status' => 'pending_verification', 'payment_method' => 'offline_cash']);
    $this->travel(2)->hours();
    $host = eventSubdomainHost($tenant->slug);
    $approval = 'http://'.$host.'/events/'.$event->id.'/registrations/'.$registration->id.'/approve-offline';
    $this->postJson($approval)->assertUnprocessable();
    $session->put(App\Services\Events\PlatformAttendeeVerification::SESSION_KEY, ['email' => $member->email, 'expires_at' => now()->addHour()->timestamp]);
    $this->withSession($session->all())->postJson('http://'.$host.'/e/'.$event->slug.'/checkout/'.$registration->id.'/member-benefits/reverify')->assertSuccessful();
    $this->postJson($approval)->assertSuccessful();
    $this->postJson($approval)->assertUnprocessable();
    expect($registration->fresh()->status)->toBe('confirmed')->and(App\Models\EventLedgerEntry::query()->count())->toBe(1);
});

it('does not start an online charge while member offline proof is awaiting review', function (): void {
    config(['services.settlement.paystack.secret_key' => 'sk_test_no_mixed_member']);
    [, , , $registration, , $attempt, $session] = MemberBenefitScenario::make();
    $attempt->update(['state' => 'failed']);
    $registration->update(['offline_payment_status' => 'pending_verification', 'payment_method' => 'offline_cash']);
    Illuminate\Support\Facades\Http::fake(['api.paystack.co/customer' => Illuminate\Support\Facades\Http::response(['data' => ['customer_code' => 'CUS_member']]),
        'api.paystack.co/customer/*' => Illuminate\Support\Facades\Http::response(['data' => ['email' => $registration->email]]),
        'api.paystack.co/transaction/initialize' => Illuminate\Support\Facades\Http::response(['data' => ['authorization_url' => 'https://checkout.paystack.com/mixed']])]);
    expect(fn () => app(App\Services\Events\MemberBenefitCheckout::class)->start($session, $registration->fresh(), 'paystack', 5000, 'GHS'))->toThrow(Illuminate\Validation\ValidationException::class);
    Illuminate\Support\Facades\Http::assertNothingSent();
});

it('does not approve offline proof while an original online attempt is unresolved', function (): void {
    [$tenant, $event, , $registration] = MemberBenefitScenario::make();
    lifecycleStaff($tenant);
    $registration->update(['offline_payment_status' => 'pending_verification', 'payment_method' => 'offline_cash']);
    $this->postJson('http://'.eventSubdomainHost($tenant->slug).'/events/'.$event->id.'/registrations/'.$registration->id.'/approve-offline')->assertUnprocessable();
    expect($registration->fresh()->ticket_code)->toBeNull();
});

it('does not accept offline proof while an online member attempt is unresolved', function (): void {
    [$tenant, $event, , $registration, , , $session] = MemberBenefitScenario::make();
    $event->update(['allow_offline_payments' => true]);
    Illuminate\Support\Facades\Storage::fake(App\Models\Event::uploadDisk());
    $host = eventSubdomainHost($tenant->slug);
    $url = 'http://'.$host.'/e/'.$event->slug.'/checkout/'.$registration->id.'/offline-proof';
    $this->withServerVariables(['HTTP_HOST' => $host])->withSession($session->all())->post($url, [
        'proof_file' => Illuminate\Http\UploadedFile::fake()->create('proof.pdf', 1, 'application/pdf'), 'payment_method' => 'offline_cash',
    ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('payment');
});

it('revokes old browser and signed-link access when an unfulfilled member recipient is corrected', function (): void {
    [$tenant, $event, , $registration, , $attempt, $session] = MemberBenefitScenario::make();
    $attempt->update(['state' => 'failed']);
    $authorizer = app(App\Services\Events\PlatformAttendeeWorkspaceAuthorizer::class);
    $authorizer->grantCheckoutAccess($session, $registration->id);
    $authorizer->rememberRegistered($session, $registration->id);
    $mail = new App\Mail\Events\EventRegistrationVerifyEmail($registration);
    $url = $mail->content()->with['verifyUrl'];
    expect($authorizer->canAccess($session, $registration))->toBeTrue();
    lifecycleStaff($tenant);
    $this->patchJson('http://'.eventSubdomainHost($tenant->slug).'/events/'.$event->id.'/registrations/'.$registration->id, ['full_name' => 'Corrected', 'email' => 'corrected@example.test'])->assertSuccessful();
    expect($authorizer->canAccess($session, $registration->fresh()))->toBeFalse()
        ->and($authorizer->registeredInThisSession($session, $registration->id))->toBeFalse();
    $this->withSession($session->all())->get($url, ['HTTP_HOST' => eventSubdomainHost($tenant->slug)])->assertForbidden();
});

it('cannot turn a stale checkout authorisation into a grant for a corrected member recipient', function (): void {
    [, , , $registration, , , $session] = MemberBenefitScenario::make();
    $oldToken = $registration->memberBenefitRecipientToken();
    $registration->update(['email' => 'new-recipient@example.test', 'member_benefit_access_version' => 2]);
    $authorizer = app(App\Services\Events\PlatformAttendeeWorkspaceAuthorizer::class);
    $authorizer->grantCheckoutAccess($session, $registration->id, 30, $oldToken);
    expect($authorizer->canAccess($session, $registration->fresh()))->toBeFalse();
});
