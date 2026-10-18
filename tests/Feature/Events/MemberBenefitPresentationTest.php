<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\MemberBenefitScenario;

beforeEach(fn () => Mail::fake());

it('publishes safe event member capabilities without membership identity', function (): void {
    [$tenant, $event] = MemberBenefitScenario::make();
    $host = eventSubdomainHost($tenant->slug);
    $this->withServerVariables(['HTTP_HOST' => $host])->get('http://'.$host.'/e/'.$event->slug)->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('event.member_benefits.enabled', true)->where('event.member_benefits.members_only', true)->missing('event.member_benefit_identity'));
});

it('pages original member payment sources privately behind event read permission', function (): void {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    [$tenant, $event, , , $quote] = MemberBenefitScenario::make();
    App\Models\EventMemberBenefitAttempt::factory()->count(12)->forQuote($quote)->create(['state' => 'failed']);
    $user = App\Models\User::factory()->create(['tenant_id' => $tenant->id]);
    $tenant->users()->attach($user->id);
    setPermissionsTeamId($tenant->id);
    $host = eventSubdomainHost($tenant->slug);
    $url = 'http://'.$host.'/events/'.$event->id.'/member-payments';
    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => $host])->getJson($url)->assertForbidden();
    $user->givePermissionTo('read event');
    $response = $this->getJson($url)->assertSuccessful()->assertJsonCount(10, 'data')->assertJsonMissingPath('data.0.account_key')->assertJsonMissingPath('data.0.identity')->assertJsonMissingPath('data.0.checkout_url');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    $this->getJson($url.'?page=2')->assertSuccessful()->assertJsonCount(3, 'data');
    $other = App\Models\Event::factory()->create();
    $this->getJson('http://'.$host.'/events/'.$other->id.'/member-payments')->assertNotFound();
    $tenant->features()->where('feature_key', 'community_event_benefits')->update(['enabled' => false]);
    $this->getJson($url)->assertSuccessful();
    $this->get('http://'.$host.'/events/'.$event->id.'/guests')->assertSuccessful()->assertInertia(fn (Assert $page) => $page->has('registrations.0.member_verification_url'));
});

it('explains the personal-ticket transfer restriction in the private workspace payload', function (): void {
    [$tenant, , , $registration, , , $session] = MemberBenefitScenario::make();
    $this->withSession($session->all())->get('http://miconvener.test/my/events/'.$registration->id)->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('registration.member_benefit_required', true)->where('registration.can_transfer', false)->missing('registration.member_benefit_identity'));
});

it('reveals an invite ticket member price only with its access code and current personal proof', function (): void {
    [$tenant, $event, $member, , , , $session] = MemberBenefitScenario::make();
    $type = App\Models\EventTicketType::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'price' => 10000, 'member_price' => 0, 'access_code' => 'MEMBERINVITE']);
    $host = eventSubdomainHost($tenant->slug);
    $url = 'http://'.$host.'/e/'.$event->slug.'/member-benefits';
    $this->withServerVariables(['HTTP_HOST' => $host])->withSession($session->all());
    $this->postJson($url, ['email' => $member->email, 'ticket_type_id' => $type->id])->assertSuccessful()->assertJsonCount(0, 'ticket_prices');
    $this->postJson($url, ['email' => $member->email, 'ticket_type_id' => $type->id, 'access_code' => 'MEMBERINVITE'])->assertSuccessful()
        ->assertJsonPath('ticket_prices.0.member_price', 0)->assertJsonStructure(['verified_until']);
});

it('creates members-only registration atomically with a new event', function (): void {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    [$tenant] = MemberBenefitScenario::make();
    $user = App\Models\User::factory()->create(['tenant_id' => $tenant->id]);
    $tenant->users()->attach($user->id);
    setPermissionsTeamId($tenant->id);
    $user->givePermissionTo('create event');
    $host = eventSubdomainHost($tenant->slug);
    $response = $this->actingAs($user)->withServerVariables(['HTTP_HOST' => $host])->postJson('http://'.$host.'/events', [
        'name' => 'Community members gathering', 'starts_at' => now()->addDay()->toIso8601String(), 'ends_at' => now()->addDays(2)->toIso8601String(),
        'timezone' => 'Africa/Accra', 'location_type' => 'in_person', 'address' => 'Accra', 'ticket_price' => 0, 'currency' => 'GHS', 'status' => 'draft',
        'members_only' => true, 'member_ticket_price' => 0,
    ])->assertSuccessful();
    $created = App\Models\Event::query()->findOrFail($response->json('id'));
    expect($created->members_only)->toBeTrue()->and($created->member_ticket_price)->toBe(0);
});

it('provides a private receipt without offering another charge for an issued member ticket', function (bool $archived): void {
    [$tenant, $event, , $registration, , $attempt, $session] = MemberBenefitScenario::make();
    app(App\Services\Events\MemberBenefitPayments::class)->confirm($attempt, MemberBenefitScenario::payment($attempt));
    if ($archived) {
        $event->delete();
    }
    $host = eventSubdomainHost($tenant->slug);
    $this->withServerVariables(['HTTP_HOST' => $host])->withSession($session->all())->get('http://'.$host.'/e/'.$event->slug.'/checkout/'.$registration->id.'?review=1')->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('member_benefit.payment_state', 'fulfilled')->where('registration.status', 'confirmed'));
})->with([false, true]);

it('does not show a scannable member QR after a refund is requested', function (): void {
    config(['services.settlement.paystack.secret_key' => 'sk_test_member_cancel_qr']);
    [, , , $registration, , $attempt, $session] = MemberBenefitScenario::make();
    $attempt->update(['account_key' => app(App\Services\Giving\GivingGateways::class)->platformIdentity()]);
    app(App\Services\Events\MemberBenefitPayments::class)->confirm($attempt, MemberBenefitScenario::payment($attempt));
    Illuminate\Support\Facades\Http::fake(['api.paystack.co/refund' => Illuminate\Support\Facades\Http::response(['data' => ['id' => 42, 'status' => 'pending']])]);
    app(App\Services\Events\MemberBenefitRefunds::class)->request($attempt->fresh());
    $this->withSession($session->all())->get('http://miconvener.test/my/events/'.$registration->id)->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('registration.status', 'cancelled')->where('registration.qr_image', null)->has('registration.member_payment_review_url'));
});
