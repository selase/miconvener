<?php

declare(strict_types=1);

use App\Enum\MemberStatusEnum;
use App\Models\CommunitySettings;
use App\Models\Member;
use App\Models\MembershipType;
use App\Services\Community\MembershipJoining;
use App\Services\Events\PlatformAttendeeVerification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    [$this->tenant, $this->owner] = eventHost('approval-qa');
    Artisan::call('community:enable', ['tenant' => $this->tenant->slug]);
    $this->type = MembershipType::factory()->create(['tenant_id' => $this->tenant->id]);
    CommunitySettings::for($this->tenant)->fill(['public_join_enabled' => true, 'public_membership_type_id' => $this->type->id])->save();
    $this->session = app('session.store');
    $this->session->put(PlatformAttendeeVerification::SESSION_KEY, ['email' => 'join@example.test', 'expires_at' => now()->addHour()->timestamp]);
    $this->join = app(MembershipJoining::class)->submit($this->tenant, $this->session, ['name' => 'Applicant', 'channel' => 'email'], (string) Str::uuid());
});

it('approves the already counted pending member once at the cap', function (): void {
    Member::factory()->count(49)->create(['tenant_id' => $this->tenant->id]);
    $service = app(MembershipJoining::class);
    $member = $service->approve($this->join, $this->owner);
    expect($member->status)->toBe(MemberStatusEnum::Active)->and($service->approve($this->join, $this->owner)->id)->toBe($member->id)
        ->and(Member::count())->toBe(50)->and($this->join->fresh()->status)->toBe('approved');
});

it('blocks approval over the cap and rejects without deleting history', function (): void {
    Member::factory()->count(50)->create(['tenant_id' => $this->tenant->id]);
    $service = app(MembershipJoining::class);
    expect(fn () => $service->approve($this->join, $this->owner))->toThrow(DomainException::class);
    $service->reject($this->join, $this->owner, 'Application withdrawn by the applicant.');
    expect(Member::findOrFail($this->join->member_id)->status)->toBe(MemberStatusEnum::Exited)
        ->and($this->join->fresh()->status)->toBe('rejected');
    expect(fn () => $service->approve($this->join->fresh(), $this->owner))->toThrow(DomainException::class);
});

it('requires staff permission and a still active local type', function (): void {
    $reader = App\Models\User::factory()->create(['tenant_id' => $this->tenant->id]);
    expect(fn () => app(MembershipJoining::class)->approve($this->join, $reader))->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
    $this->type->update(['is_active' => false]);
    expect(fn () => app(MembershipJoining::class)->approve($this->join, $this->owner))->toThrow(DomainException::class);
});

it('requires an explicit distinct-person decision for ambiguity and never grants historical phone access', function (): void {
    $historic = Member::findOrFail($this->join->member_id);
    $historic->update(['status' => MemberStatusEnum::Exited, 'phone' => '+233241234567']);
    $this->session->put(App\Services\Community\PhoneVerification::SESSION_KEY, ['phone' => $historic->phone, 'expires_at' => now()->addHour()->timestamp]);
    $service = app(MembershipJoining::class);
    $ambiguous = $service->submit($this->tenant, $this->session, ['name' => 'Another Applicant', 'channel' => 'sms'], (string) Str::uuid());
    expect(fn () => $service->approve($ambiguous, $this->owner))->toThrow(Illuminate\Validation\ValidationException::class);
    $distinct = $service->approve($ambiguous, $this->owner, true, 'Confirmed a different person through independent checks.');
    expect($distinct->id)->not->toBe($historic->id)->and($historic->fresh()->status)->toBe(MemberStatusEnum::Exited)
        ->and($ambiguous->fresh()->member_access_version)->toBeNull();
});

it('validates join settings and exposes review endpoints only to this organisations staff', function (): void {
    expect(fn () => app(App\Services\Community\JoinSettings::class)->update($this->tenant, $this->owner, ['join_mode' => 'immediate', 'public_join_enabled' => true, 'public_membership_type_id' => MembershipType::factory()->create()->id]))->toThrow(Illuminate\Validation\ValidationException::class);
    $url = 'http://'.eventSubdomainHost('approval-qa').'/community/people/joins';
    $this->actingAs($this->owner)->getJson($url)->assertOk()->assertJsonPath('requests.data.0.id', $this->join->id);
    $this->postJson($url.'/'.$this->join->id.'/approve')->assertOk();
    $this->patchJson('http://'.eventSubdomainHost('approval-qa').'/community/settings/joining', ['join_mode' => 'invitation_only', 'public_join_enabled' => false, 'public_membership_type_id' => $this->type->id])->assertOk();
    $reader = App\Models\User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->tenant->users()->attach($reader->id);
    $this->actingAs($reader)->getJson($url)->assertForbidden();
});

it('lets staff explicitly select an active replacement type when the requested type was retired', function (): void {
    $this->type->update(['is_active' => false]);
    $replacement = MembershipType::factory()->create(['tenant_id' => $this->tenant->id]);
    $member = app(MembershipJoining::class)->approve($this->join, $this->owner, false, 'Reviewed the replacement membership type with the applicant.', $replacement->id);
    expect($member->status)->toBe(MemberStatusEnum::Active)->and($member->membership_type_id)->toBe($replacement->id)
        ->and($this->join->fresh()->membership_type_id)->toBe($this->type->id);
});
