<?php

declare(strict_types=1);
use App\Models\CommunityFollower;
use App\Services\Community\PhoneVerification;
use App\Services\Events\PlatformAttendeeVerification;

require_once dirname(__DIR__, 2).'/Fixtures/community/portal-test-setup.php';
beforeEach(fn () => setupCommunityPortalTest($this));
it('lists exact-email memberships without inferring the recorded phone or other organisations', function (): void {
    $this->withSession($this->session->all())->getJson($this->base.'/communities')->assertOk()->assertJsonCount(1, 'communities.data')->assertJsonPath('communities.data.0.id', $this->tenant->id)->assertDontSee($this->other->name);
    $this->getJson($this->base.'/communities/'.$this->tenant->slug)->assertOk()->assertJsonCount(1, 'members.data')->assertJsonPath('members.data.0.id', $this->member->id)->assertDontSee('PRIVATE STAFF NOTES')->assertDontSee($this->member->phone);
    $this->getJson($this->base.'/identity')->assertOk()->assertJsonPath('can_access_events', true)->assertJsonPath('phone', null)->assertDontSee('a@example.test');
});
it('shows explicit follows with Community off but never treats a matching phone as a membership', function (): void {
    $this->session->forget(PlatformAttendeeVerification::SESSION_KEY);
    $this->session->put(PhoneVerification::SESSION_KEY, ['phone' => '+233241234567', 'expires_at' => now()->addHour()->timestamp]);
    $this->withSession($this->session->all())->getJson($this->base.'/communities')->assertOk()->assertJsonCount(0, 'communities.data');
    CommunityFollower::factory()->create(['tenant_id' => $this->other->id, 'address' => '+233241234567', 'channel' => 'sms']);
    $this->getJson($this->base.'/communities')->assertOk()->assertJsonCount(1, 'communities.data')->assertJsonPath('communities.data.0.id', $this->other->id)->assertJsonPath('communities.data.0.following.sms', true);
    $this->getJson($this->base.'/communities/'.$this->other->slug)->assertOk()->assertJsonCount(0, 'members.data');
    $this->getJson($this->base.'/identity')->assertOk()->assertJsonPath('can_access_events', false);
});
it('retains authorised financial history after Community rollout is disabled', function (): void {
    $this->withSession($this->session->all())->getJson($this->base.'/communities/'.$this->tenant->slug.'/members/'.$this->member->id)->assertOk()->assertJsonPath('member.id', $this->member->id)->assertJsonPath('member.status', 'active')->assertJsonStructure(['member' => ['pay_url', 'membership_type', 'access_role']]);
    expect($this->tenant->communityEnabled())->toBeFalse();
    $this->postJson($this->base.'/communities/'.$this->tenant->slug.'/join', ['name' => 'Applicant', 'channel' => 'email', 'request_key' => (string) Illuminate\Support\Str::uuid()])->assertNotFound();
});
it('paginates charges and does not expose internal finance fields', function (): void {
    App\Models\DuesCharge::factory()->create(['tenant_id' => $this->tenant->id, 'member_id' => $this->member->id, 'membership_type_id' => $this->member->membership_type_id, 'note' => 'Private finance note', 'commission_amount' => 123]);
    $this->withSession($this->session->all())->getJson($this->base.'/communities/'.$this->tenant->slug.'/members/'.$this->member->id.'/charges')->assertOk()->assertJsonCount(1, 'charges.data')->assertJsonMissingPath('charges.data.0.commission_amount')->assertDontSee('Private finance note');
});

it('paginates authorised members before returning data', function (): void {
    App\Models\Member::factory()->count(21)->create(['tenant_id' => $this->tenant->id, 'email' => 'a@example.test']);
    $this->withSession($this->session->all())->getJson($this->base.'/communities/'.$this->tenant->slug)->assertOk()->assertJsonCount(20, 'members.data')->assertJsonPath('members.total', 22);
});

it('hides unavailable join actions and uses the supplied session when queried outside HTTP', function (): void {
    App\Models\CommunitySettings::for($this->tenant)->fill(['public_join_enabled' => true, 'public_membership_type_id' => null])->save();
    Illuminate\Support\Facades\Artisan::call('community:enable', ['tenant' => $this->tenant->slug]);
    $history = app(App\Services\Community\CommunityHistory::class)->communities($this->session);
    expect($history['data'][0]['actions']['join'])->toBeFalse();
});

it('supports the standard resource collection contract as well as explicit service sessions', function (): void {
    $request = Illuminate\Http\Request::create('/my/communities');
    $request->setLaravelSession($this->session);
    $rows = App\Http\Resources\Community\MyCommunityResource::collection(collect([$this->tenant]))->resolve($request);
    expect($rows[0]['id'])->toBe($this->tenant->id);
});
