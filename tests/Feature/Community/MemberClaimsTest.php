<?php

declare(strict_types=1);

use App\Models\CommunityAccessAudit;
use App\Models\CommunityAccessClaim;
use App\Models\Member;
use App\Services\Community\MemberAccess;
use App\Services\Community\MemberClaims;
use App\Services\Community\PhoneVerification;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    [$this->tenant, $this->owner] = eventHost('claims-qa');
    Artisan::call('community:enable', ['tenant' => $this->tenant->slug]);
    $this->session = app('session.store');
    $this->session->put(PhoneVerification::SESSION_KEY, ['phone' => '+233241234567', 'expires_at' => now()->addHour()->timestamp]);
});

it('requires independent reviewed approval and consumes the original session capability once', function (): void {
    $member = Member::factory()->create(['tenant_id' => $this->tenant->id, 'phone' => '+233241234567']);
    $claims = app(MemberClaims::class);
    $claim = $claims->request($this->tenant, $this->session, 'My submitted name', 'self');
    $capability = $this->session->get('community_claim_capabilities.'.$claim->id);
    expect($claim->member_id)->toBeNull()->and(app(MemberAccess::class)->canView($this->session, $member))->toBeFalse();
    $claims->approve($claim, $member, $this->owner, 'in_person', 'Checked in person with the member.');
    $result = $claims->complete($this->session, $claim->id, $capability);
    expect($result)->toHaveKeys(['selector', 'secret'])
        ->and(app(MemberAccess::class)->canView($this->session, $member))->toBeTrue()
        ->and(CommunityAccessAudit::query()->where('member_id', $member->id)->count())->toBeGreaterThan(0);
    expect(fn () => $claims->complete($this->session, $claim->id, $capability))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('scopes a representative grant to the separately approved family member', function (): void {
    $member = Member::factory()->create(['tenant_id' => $this->tenant->id, 'phone' => '+233241234568']);
    $other = Member::factory()->create(['tenant_id' => $this->tenant->id, 'phone' => $member->phone]);
    $claims = app(MemberClaims::class);
    $claim = $claims->request($this->tenant, $this->session, 'Parent', 'representative');
    $claims->approve($claim, $member, $this->owner, 'known_contact', 'Parent authority verified separately.');
    $claims->complete($this->session, $claim->id, $this->session->get('community_claim_capabilities.'.$claim->id));
    expect(app(MemberAccess::class)->canView($this->session, $member))->toBeTrue()
        ->and(app(MemberAccess::class)->canView($this->session, $other))->toBeFalse()
        ->and(app(App\Services\Events\PlatformAttendeeVerification::class)->verifiedEmail($this->session))->toBeNull();
});

it('rejects foreign members unprivileged reviewers and forged capabilities', function (): void {
    $claims = app(MemberClaims::class);
    $claim = $claims->request($this->tenant, $this->session, 'Applicant', 'self');
    $foreign = Member::factory()->create();
    expect(fn () => $claims->approve($claim, $foreign, $this->owner, 'in_person', 'Checked separately.'))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
    $member = Member::factory()->create(['tenant_id' => $this->tenant->id, 'phone' => '+233241234567']);
    $reader = App\Models\User::factory()->create(['tenant_id' => $this->tenant->id]);
    expect(fn () => $claims->approve($claim, $member, $reader, 'in_person', 'Checked separately.'))->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
    $claims->approve($claim, $member, $this->owner, 'in_person', 'Checked separately.');
    expect(fn () => $claims->complete($this->session, $claim->id, 'forged'))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('rejects expired claims and approval without independent evidence', function (): void {
    $claims = app(MemberClaims::class);
    $claim = $claims->request($this->tenant, $this->session, 'Applicant', 'self');
    $member = Member::factory()->create(['tenant_id' => $this->tenant->id, 'phone' => '+233241234567']);
    expect(fn () => $claims->approve($claim, $member, $this->owner, 'sms_code', 'Saw an SMS.'))->toThrow(Illuminate\Validation\ValidationException::class);
    $claim->update(['expires_at' => now()->subMinute()]);
    expect(fn () => $claims->approve($claim->fresh(), $member, $this->owner, 'in_person', 'Checked separately.'))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('exposes only the current applicant request and tenant-bound staff approval over HTTP', function (): void {
    $host = mb_ltrim((string) config('session.domain'), '.');
    $member = Member::factory()->create(['tenant_id' => $this->tenant->id, 'phone' => '+233241234567', 'name' => 'Private member name']);
    $this->withServerVariables(['HTTP_HOST' => $host])->withSession([PhoneVerification::SESSION_KEY => ['phone' => $member->phone, 'expires_at' => now()->addHour()->timestamp]])
        ->postJson('/my/communities/'.$this->tenant->slug.'/claims', ['name' => 'Applicant name', 'role' => 'self'])
        ->assertCreated()->assertJsonMissing(['name' => 'Private member name']);
    $claim = CommunityAccessClaim::query()->firstOrFail();
    $this->getJson('/my/communities/'.$this->tenant->slug.'/claims/'.$claim->id)->assertOk()->assertJsonPath('claim.status', 'pending');
    $this->actingAs($this->owner)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('claims-qa')])
        ->getJson('http://'.eventSubdomainHost('claims-qa').'/community/access/claims')->assertOk()->assertJsonPath('claims.data.0.claimant_name', 'Applicant name');
    $this->postJson('http://'.eventSubdomainHost('claims-qa').'/community/access/claims/'.$claim->id.'/approve', ['member_id' => $member->id, 'method' => 'in_person', 'reason' => 'Independently checked with the member.'])->assertOk();
    $this->withServerVariables(['HTTP_HOST' => $host])->postJson('http://'.$host.'/my/communities/'.$this->tenant->slug.'/claims/'.$claim->id.'/complete')
        ->assertOk()->assertJsonStructure(['selector', 'secret'])->assertHeader('Cache-Control', 'no-store, private');
    $this->getJson('http://'.$host.'/my/communities/'.$this->tenant->slug.'/claims/'.$claim->id)->assertOk()->assertJsonMissingPath('claim.secret');
});

it('keeps the original pending request capability when phone proof expires', function (): void {
    $claim = app(MemberClaims::class)->request($this->tenant, $this->session, 'Applicant', 'self');
    $capability = $this->session->get('community_claim_capabilities.'.$claim->id);
    $this->travel(2)->hours();
    expect(app(PhoneVerification::class)->verifiedPhone($this->session))->toBeNull()
        ->and($this->session->get('community_claim_capabilities.'.$claim->id))->toBe($capability);
    expect(app(MemberClaims::class)->owns($this->session, $claim))->toBeFalse();
    $this->session->put(PhoneVerification::SESSION_KEY, ['phone' => $claim->phone, 'expires_at' => now()->addHour()->timestamp]);
    expect(app(MemberClaims::class)->owns($this->session, $claim))->toBeTrue();
});

it('records a rejected claim without creating access', function (): void {
    $claims = app(MemberClaims::class);
    $claim = $claims->request($this->tenant, $this->session, 'Applicant', 'self');
    $claims->reject($claim, $this->owner, 'Could not independently confirm this claim.');
    expect($claim->fresh()->status)->toBe('rejected');
    expect(fn () => $claims->complete($this->session, $claim->id, $this->session->get('community_claim_capabilities.'.$claim->id)))
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('does not reuse a reviewer role cached from another organisation', function (): void {
    $this->owner->load('roles', 'permissions');
    expect($this->owner->can('manage members'))->toBeTrue();
    $other = App\Models\Tenant::factory()->create();
    $other->features()->create(['feature_key' => 'community', 'enabled' => true]);
    $other->users()->attach($this->owner->id);
    $member = Member::factory()->create(['tenant_id' => $other->id, 'phone' => '+233241234567']);
    $claims = app(MemberClaims::class);
    $claim = $claims->request($other, $this->session, 'Applicant', 'self');
    expect(fn () => $claims->approve($claim, $member, $this->owner, 'in_person', 'Checked separately in person.'))
        ->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
});
