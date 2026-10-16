<?php

declare(strict_types=1);

use App\Enum\MemberStatusEnum;
use App\Models\CommunityJoinRequest;
use App\Models\CommunitySettings;
use App\Models\Member;
use App\Models\MembershipType;
use App\Services\Community\MembershipJoining;
use App\Services\Community\PhoneVerification;
use App\Services\Dues\DuesScheduler;
use App\Services\Events\PlatformAttendeeVerification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    [$this->tenant] = eventHost('join-qa');
    Artisan::call('community:enable', ['tenant' => $this->tenant->slug]);
    $this->type = MembershipType::factory()->create(['tenant_id' => $this->tenant->id]);
    CommunitySettings::for($this->tenant)->fill(['public_join_enabled' => true, 'public_membership_type_id' => $this->type->id])->save();
    $this->session = app('session.store');
    $this->session->put(PlatformAttendeeVerification::SESSION_KEY, ['email' => 'join@example.test', 'expires_at' => now()->addHour()->timestamp]);
});

it('creates one pending member and no dues or marketing consent for repeated requests', function (): void {
    $key = (string) Str::uuid();
    $service = app(MembershipJoining::class);
    $first = $service->submit($this->tenant, $this->session, ['name' => 'Applicant', 'channel' => 'email', 'status' => 'active', 'amount_override' => 0, 'email' => 'forged@example.test'], $key);
    $again = $service->submit($this->tenant, $this->session, ['name' => 'Changed', 'channel' => 'email'], $key);
    $member = Member::findOrFail($first->member_id);
    expect($again->id)->toBe($first->id)->and($member->status)->toBe(MemberStatusEnum::Pending)
        ->and($member->email)->toBe('join@example.test')->and($member->amount_override)->toBeNull()
        ->and(Member::count())->toBe(1)->and(App\Models\CommunityFollower::count())->toBe(0);
    app(DuesScheduler::class)->generateFor($this->tenant, CarbonImmutable::today()->addMonths(2));
    expect($member->charges()->count())->toBe(0);
});

it('makes immediate joins active with next full period dues', function (): void {
    CommunitySettings::for($this->tenant)->update(['join_mode' => 'immediate']);
    $request = app(MembershipJoining::class)->submit($this->tenant, $this->session, ['name' => 'Applicant', 'channel' => 'email'], (string) Str::uuid());
    $member = Member::findOrFail($request->member_id);
    expect($request->status)->toBe('approved')->and($member->status)->toBe(MemberStatusEnum::Active);
    app(DuesScheduler::class)->generateFor($this->tenant, CarbonImmutable::today());
    expect($member->charges()->where('period_start', '<=', CarbonImmutable::today()->toDateString())->count())->toBe(0);
});

it('closes public joining in invitation mode when disabled or without an active local type', function (string $case): void {
    match ($case) {
        'invitation' => CommunitySettings::for($this->tenant)->update(['join_mode' => 'invitation_only']),
        'disabled' => CommunitySettings::for($this->tenant)->update(['public_join_enabled' => false]),
        'inactive' => $this->type->update(['is_active' => false]),
        'foreign' => CommunitySettings::for($this->tenant)->update(['public_membership_type_id' => MembershipType::factory()->create()->id]),
        'community_off' => Artisan::call('community:enable', ['tenant' => $this->tenant->slug, '--off' => true]),
    };
    expect(fn () => app(MembershipJoining::class)->submit($this->tenant->fresh(), $this->session, ['name' => 'Applicant', 'channel' => 'email'], (string) Str::uuid()))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(Member::count())->toBe(0);
})->with(['invitation', 'disabled', 'inactive', 'foreign', 'community_off']);

it('keeps historic contact matches in private review without creating or granting a member', function (): void {
    $historic = Member::factory()->create(['tenant_id' => $this->tenant->id, 'phone' => '+233241234567', 'status' => MemberStatusEnum::Exited]);
    $this->session->put(PhoneVerification::SESSION_KEY, ['phone' => $historic->phone, 'expires_at' => now()->addHour()->timestamp]);
    $request = app(MembershipJoining::class)->submit($this->tenant, $this->session, ['name' => 'Different Person', 'channel' => 'sms'], (string) Str::uuid());
    expect($request->status)->toBe('review_required')->and($request->member_id)->toBeNull()
        ->and(Member::count())->toBe(1)->and(app(App\Services\Community\MemberAccess::class)->canView($this->session, $historic))->toBeFalse();
});

it('requires proof and prevents cap overflow', function (): void {
    $this->session->flush();
    expect(fn () => app(MembershipJoining::class)->submit($this->tenant, $this->session, ['name' => 'Applicant', 'channel' => 'email'], (string) Str::uuid()))->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
    $this->session->put(PlatformAttendeeVerification::SESSION_KEY, ['email' => 'join@example.test', 'expires_at' => now()->addHour()->timestamp]);
    Member::factory()->count(50)->create(['tenant_id' => $this->tenant->id]);
    expect(fn () => app(MembershipJoining::class)->submit($this->tenant, $this->session, ['name' => 'Applicant', 'channel' => 'email'], (string) Str::uuid()))->toThrow(DomainException::class);
    expect(CommunityJoinRequest::count())->toBe(0);
});

it('returns only the submitting browsers generic request state and no historic details', function (): void {
    $member = Member::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'join@example.test', 'notes' => 'Private staff information']);
    $host = mb_ltrim((string) config('session.domain'), '.');
    $base = 'http://'.$host.'/my/communities/'.$this->tenant->slug;
    $response = $this->withSession($this->session->all())->postJson($base.'/join', ['name' => 'Applicant', 'channel' => 'email', 'request_key' => (string) Str::uuid()]);
    $response->assertCreated()->assertJsonPath('request.status', 'pending')->assertJsonMissing(['member_id' => $member->id])->assertDontSee('Private staff information');
    $id = $response->json('request.id');
    $this->getJson($base.'/joins/'.$id)->assertOk();
    $this->flushSession();
    $this->withSession([PlatformAttendeeVerification::SESSION_KEY => ['email' => 'join@example.test', 'expires_at' => now()->addHour()->timestamp]])->getJson($base.'/joins/'.$id)->assertNotFound();
});

it('offers a phone recovery credential only once for a newly approved unambiguous record', function (): void {
    $this->session->put(PhoneVerification::SESSION_KEY, ['phone' => '+233241234567', 'expires_at' => now()->addHour()->timestamp]);
    CommunitySettings::for($this->tenant)->update(['join_mode' => 'immediate']);
    $service = app(MembershipJoining::class);
    $row = $service->submit($this->tenant, $this->session, ['name' => 'New Person', 'channel' => 'sms'], (string) Str::uuid());
    $credential = $service->credential($this->session, $row);
    expect($credential)->toHaveKeys(['selector', 'secret'])->and(app(App\Services\Community\MemberAccess::class)->canView($this->session, Member::findOrFail($row->member_id)))->toBeTrue();
    expect(fn () => $service->credential($this->session, $row))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($this->session->get(PlatformAttendeeVerification::SESSION_KEY)['email'])->toBe('join@example.test');
});

it('treats legacy formatted phone contacts as ambiguous instead of granting fresh access', function (): void {
    Member::factory()->create(['tenant_id' => $this->tenant->id, 'phone' => '024 123 4567']);
    $this->session->put(PhoneVerification::SESSION_KEY, ['phone' => '+233241234567', 'expires_at' => now()->addHour()->timestamp]);
    $row = app(MembershipJoining::class)->submit($this->tenant, $this->session, ['name' => 'Applicant', 'channel' => 'sms'], (string) Str::uuid());
    expect($row->status)->toBe('review_required')->and($row->member_id)->toBeNull()->and(Member::count())->toBe(1);
});

it('does not disclose or issue a recovery credential before staff approves a phone membership', function (): void {
    $this->session->put(PhoneVerification::SESSION_KEY, ['phone' => '+233241234567', 'expires_at' => now()->addHour()->timestamp]);
    $service = app(MembershipJoining::class);
    $row = $service->submit($this->tenant, $this->session, ['name' => 'Applicant', 'channel' => 'sms'], (string) Str::uuid());
    expect(fn () => $service->credential($this->session, $row))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
    $host = mb_ltrim((string) config('session.domain'), '.');
    $this->withSession($this->session->all())->getJson('http://'.$host.'/my/communities/'.$this->tenant->slug.'/joins/'.$row->id)->assertOk()->assertJsonPath('request.can_save_recovery', false);
});

it('clears join request capabilities on explicit removal of phone access', function (): void {
    $this->session->put('community_join_capabilities.request', 'private');
    app(PhoneVerification::class)->forget($this->session);
    expect($this->session->has('community_join_capabilities'))->toBeFalse();
});
