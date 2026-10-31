<?php

declare(strict_types=1);
use App\Models\Event;
use App\Models\Member;
use App\Models\Tenant;
use App\Services\Community\PhoneVerification;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'public-community']);
    Artisan::call('community:following-enable', ['tenant' => $this->tenant->slug]);
    $this->url = 'http://'.eventSubdomainHost($this->tenant->slug).'/community/about';
});
it('shows public organisation and upcoming events without membership or private contact data', function (): void {
    $event = Event::factory()->published()->create(['tenant_id' => $this->tenant->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]);
    $private = Event::factory()->published()->create(['tenant_id' => $this->tenant->id, 'visibility' => Event::VISIBILITY_PRIVATE]);
    $member = Member::factory()->create(['tenant_id' => $this->tenant->id, 'notes' => 'Private staff note']);
    $this->get($this->url)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Public/Community/Organisation')->where('organisation.name', $this->tenant->name)->has('events', 1)->where('events.0.id', $event->id)->where('identity', null))
        ->assertDontSee($member->name)->assertDontSee($member->email)->assertDontSee('Private staff note')->assertDontSee($private->name);
});
it('keeps tenant follow actions verified and usable without Community through same-origin public routes', function (): void {
    $this->postJson('http://'.eventSubdomainHost($this->tenant->slug).'/community/follow', ['channel' => 'sms', 'resume_marketing' => true])->assertUnauthorized();
    $url = 'http://'.eventSubdomainHost($this->tenant->slug).'/community/follow';
    $this->withSession([PhoneVerification::SESSION_KEY => ['phone' => '+233241234567', 'expires_at' => now()->addHour()->timestamp]])
        ->postJson($url, ['channel' => 'sms', 'resume_marketing' => true])->assertForbidden();
    $this->withSession([App\Services\Events\PlatformAttendeeVerification::SESSION_KEY => ['email' => 'follower@example.test', 'expires_at' => now()->addHour()->timestamp]])
        ->postJson($url, ['channel' => 'sms', 'resume_marketing' => true])->assertForbidden();
    $this->postJson($url, ['channel' => 'email', 'resume_marketing' => true])->assertCreated();
    $this->postJson($url, ['channel' => 'sms', 'resume_marketing' => true])->assertCreated();
    $this->deleteJson($url, ['channel' => 'sms'])->assertOk();
});
it('keeps phone-only page loading out of email history and validates organisation return URLs', function (): void {
    $host = mb_ltrim((string) config('session.domain'), '.');
    $return = 'http://'.eventSubdomainHost($this->tenant->slug).'/community/about';
    $this->withSession([PhoneVerification::SESSION_KEY => ['phone' => '+233241234567', 'expires_at' => now()->addHour()->timestamp]])
        ->get('http://'.$host.'/my?organiser='.$this->tenant->slug.'&return_to='.urlencode($return))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('communityPhoneVerified', true)->where('verifiedEmail', null)->where('initialHistory', null)->where('communityReturnUrl', $return));
    $this->get('http://'.$host.'/my?organiser='.$this->tenant->slug.'&return_to='.urlencode('https://evil.example/community/about'))->assertInertia(fn (Assert $page) => $page->where('communityReturnUrl', null));
});

it('returns only this browsers own request statuses for its current phone proof', function (): void {
    Artisan::call('community:enable', ['tenant' => $this->tenant->slug]);
    $session = app('session.store');
    $session->put(PhoneVerification::SESSION_KEY, ['phone' => '+233241234567', 'expires_at' => now()->addHour()->timestamp]);
    $mine = app(App\Services\Community\MemberClaims::class)->request($this->tenant, $session, 'My submitted name', 'self');
    App\Models\CommunityAccessClaim::factory()->create(['tenant_id' => $this->tenant->id, 'phone' => '+233241234567', 'claimant_name' => 'Another browser private request']);
    $host = mb_ltrim((string) config('session.domain'), '.');
    $this->withSession($session->all())->getJson('http://'.$host.'/my/communities/'.$this->tenant->slug.'/options')->assertOk()->assertJsonCount(1, 'requests.claims')->assertJsonPath('requests.claims.0.id', $mine->id)->assertDontSee('Another browser private request');
});

it('opens Followers independently of Community and limits announcement-only staff to purpose-scoped pages', function (): void {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    $owner = App\Models\User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->tenant->users()->attach($owner->id);
    setPermissionsTeamId($this->tenant->id);
    $owner->assignRole('Org Superadmin');
    $base = 'http://'.eventSubdomainHost($this->tenant->slug).'/community/followers';
    $this->actingAs($owner)->get($base)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Tenant/Community/Followers')->where('communityEnabled', false));
    $sender = App\Models\User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->tenant->users()->attach($sender->id);
    setPermissionsTeamId($this->tenant->id);
    $sender->givePermissionTo('send community messages');
    $this->actingAs($sender)->get($base)->assertForbidden();
    $this->get($base.'/announcements')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Tenant/Community/Followers')->where('canManage', false)->where('followers', null));
});

it('allows a proved current phone holder to report wrong-number messages after rollout is paused', function (): void {
    Artisan::call('community:following-enable', ['tenant' => $this->tenant->slug, '--off' => true]);
    $host = mb_ltrim((string) config('session.domain'), '.');
    $this->withSession([PhoneVerification::SESSION_KEY => ['phone' => '+233241234567', 'expires_at' => now()->addHour()->timestamp]])
        ->postJson('http://'.$host.'/my/communities/'.$this->tenant->slug.'/wrong-number', ['note' => 'Messages for somebody else. Please review.'])->assertOk()->assertJsonPath('accepted', true);
});
it('bounds staff member search to the current tenant and requires manage-members permission', function (): void {
    Artisan::call('community:enable', ['tenant' => $this->tenant->slug]);
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    $owner = App\Models\User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->tenant->users()->attach($owner->id);
    setPermissionsTeamId($this->tenant->id);
    $owner->assignRole('Org Superadmin');
    $own = Member::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Search Person']);
    $other = Member::factory()->create(['name' => 'Search Person Foreign']);
    $url = 'http://'.eventSubdomainHost($this->tenant->slug).'/community/access/member-options?search=Search';
    $this->actingAs($owner)->getJson($url)->assertOk()->assertJsonCount(1, 'members.data')->assertJsonPath('members.data.0.id', $own->id)->assertDontSee($other->id);
    $reader = App\Models\User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->tenant->users()->attach($reader->id);
    $this->actingAs($reader)->getJson($url)->assertForbidden();
});
