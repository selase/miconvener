<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Member;
use App\Models\Tenant;
use App\Services\Events\PlatformAttendeeVerification;
use Illuminate\Support\Facades\Mail;

function memberEventFixture(array $attributes = []): array
{
    Mail::fake();
    $tenant = Tenant::factory()->create(['slug' => 'member-events']);
    foreach (['community', 'community_event_benefits'] as $key) {
        $tenant->features()->create(['feature_key' => $key, 'enabled' => true]);
    }
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id, 'members_only' => true, 'ticket_price' => 0, ...$attributes]);
    $member = Member::factory()->create(['tenant_id' => $tenant->id, 'email' => 'member@example.test']);
    test()->withServerVariables(['HTTP_HOST' => eventSubdomainHost($tenant->slug)]);

    return [$tenant, $event, $member, 'http://'.eventSubdomainHost($tenant->slug).'/e/'.$event->slug];
}

it('rejects unverified or other-person registration even for a free members only event', function (bool $otherProof): void {
    [, , $member, $url] = memberEventFixture();
    if ($otherProof) {
        $this->withSession([PlatformAttendeeVerification::SESSION_KEY => ['email' => 'other@example.test', 'expires_at' => now()->addHour()->timestamp]]);
    }
    $this->postJson($url.'/register', ['full_name' => 'Typed Name', 'email' => $member->email, 'member_id' => $member->id, 'member_price' => 0])->assertUnprocessable();
    expect(EventRegistration::query()->count())->toBe(0);
    Mail::assertNothingSent();
})->with([false, true]);

it('registers an active no-dues member and persists independently verified identity', function (): void {
    [, , $member, $url] = memberEventFixture(['ticket_price' => 10000, 'member_ticket_price' => 0]);
    $this->withSession([PlatformAttendeeVerification::SESSION_KEY => ['email' => $member->email, 'expires_at' => now()->addHour()->timestamp]])
        ->postJson($url.'/register', ['full_name' => 'Member', 'email' => $member->email])->assertRedirect();
    $registration = EventRegistration::query()->firstOrFail();
    expect($registration->member_benefit_required)->toBeTrue()->and($registration->amount)->toBe(0)
        ->and($registration->member_benefit_identity['member_id'])->toBe($member->id)
        ->and($registration->member_benefit_quote_id)->toBeNull();
});

it('gives a non-member the standard route on an unrestricted public event', function (): void {
    [, , , $url] = memberEventFixture(['members_only' => false, 'member_ticket_price' => 0]);
    $this->postJson($url.'/register', ['full_name' => 'Guest', 'email' => 'guest@example.test'])->assertRedirect();
    expect(EventRegistration::query()->firstOrFail()->member_benefit_required)->toBeFalse();
});

it('keeps approval and waitlist registration separate from payable member quotes', function (bool $approval): void {
    [$tenant, $event, $member, $url] = memberEventFixture(['requires_approval' => $approval, 'capacity' => $approval ? null : 1]);
    if (! $approval) {
        EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'status' => EventRegistration::STATUS_CONFIRMED]);
    }
    $this->withSession([PlatformAttendeeVerification::SESSION_KEY => ['email' => $member->email, 'expires_at' => now()->addHour()->timestamp]])
        ->postJson($url.'/register', ['full_name' => 'Member', 'email' => $member->email])->assertRedirect();
    $registration = EventRegistration::query()->where('email', $member->email)->firstOrFail();
    expect($registration->status)->toBe($approval ? 'pending_approval' : 'waitlisted')
        ->and($registration->member_benefit_required)->toBeTrue()->and($registration->member_benefit_quote_id)->toBeNull();
})->with([true, false]);

it('returns only private session eligibility and never membership records for a typed address', function (): void {
    [, , $member, $url] = memberEventFixture();
    $response = $this->postJson($url.'/member-benefits', ['email' => $member->email])->assertSuccessful()->assertJsonPath('eligible', false);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    $response->assertJsonMissingPath('member')->assertJsonMissingPath('member_id')->assertJsonMissingPath('status');
});

it('review serializes ordinary registration creation with member inventory changes', function (): void {
    [$tenant, $event] = Tests\Support\MemberBenefitScenario::make();
    $event->update(['members_only' => false]);
    $locks = [];
    Illuminate\Support\Facades\DB::listen(function (Illuminate\Database\Events\QueryExecuted $query) use (&$locks): void {
        if (preg_match('/from "events".*for update/i', $query->sql)) {
            $locks[] = $query->sql;
        }
    });
    $host = eventSubdomainHost($tenant->slug);
    $this->withServerVariables(['HTTP_HOST' => $host])->post('http://'.$host.'/e/'.$event->slug.'/register', ['full_name' => 'Standard Guest', 'email' => 'standard-lock@example.test'])->assertRedirect();
    expect($locks)->not->toBeEmpty();
});
