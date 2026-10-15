<?php

declare(strict_types=1);

use App\Enum\DuesChargeStatusEnum;
use App\Enum\MemberStatusEnum;
use App\Models\CommunitySettings;
use App\Models\Member;
use App\Models\MembershipType;
use App\Models\Tenant;
use App\Services\Dues\DuesCharges;
use App\Services\Dues\DuesScheduler;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/** @return array{Tenant, Member} */
function generationMember(string $period = 'daily', array $settings = [], array $member = []): array
{
    $tenant = Tenant::factory()->create();
    Artisan::call('community:enable', ['tenant' => $tenant->slug]);
    CommunitySettings::factory()->create(['tenant_id' => $tenant->id, 'generate_days_ahead' => 0, ...$settings]);
    $type = MembershipType::factory()->create(['tenant_id' => $tenant->id, 'period' => $period]);
    $record = Member::factory()->create(['tenant_id' => $tenant->id, 'membership_type_id' => $type->id, 'joined_on' => '2026-10-01', ...$member]);

    return [$tenant->fresh(), $record];
}

test('daily generation catches up missing days once', function () {
    [$tenant, $member] = generationMember(member: ['joined_on' => '2026-10-12']);
    $scheduler = app(DuesScheduler::class);
    expect($scheduler->generateFor($tenant, CarbonImmutable::parse('2026-10-15')))->toBe(3)
        ->and($scheduler->generateFor($tenant, CarbonImmutable::parse('2026-10-15')))->toBe(0)
        ->and($member->charges()->orderBy('period_start')->pluck('period_start')->map->toDateString()->all())
        ->toBe(['2026-10-13', '2026-10-14', '2026-10-15']);
});

test('catch up fills a gap before a later existing charge and preserves voids', function () {
    [$tenant, $member] = generationMember(member: ['joined_on' => '2026-10-12']);
    $service = app(DuesCharges::class);
    $service->createFor($member, CarbonImmutable::parse('2026-10-15'));
    $voided = $service->createFor($member, CarbonImmutable::parse('2026-10-14'));
    $service->void($voided, 'Not owed');
    expect(app(DuesScheduler::class)->generateFor($tenant, CarbonImmutable::parse('2026-10-15')))->toBe(1)
        ->and($voided->fresh()->status)->toBe(DuesChargeStatusEnum::Void)
        ->and($member->charges()->count())->toBe(3);
});

test('weekly generation starts at the next full week unless current period is requested', function () {
    [$tenant, $member] = generationMember('weekly', member: ['joined_on' => '2026-10-07']);
    expect(app(DuesScheduler::class)->generateFor($tenant, CarbonImmutable::parse('2026-10-15')))->toBe(1)
        ->and($member->charges()->firstOrFail()->period_start->toDateString())->toBe('2026-10-12');
    CommunitySettings::where('tenant_id', $tenant->id)->update(['charge_current_period_on_join' => true]);
    expect(app(DuesScheduler::class)->generateFor($tenant, CarbonImmutable::parse('2026-10-15')))->toBe(1)
        ->and($member->charges()->orderBy('period_start')->firstOrFail()->period_start->toDateString())->toBe('2026-10-05');
});

test('pending lapsed and exited members generate no charges', function () {
    foreach (['pending', 'lapsed', 'exited'] as $status) {
        [$tenant] = generationMember(member: ['status' => $status]);
        expect(app(DuesScheduler::class)->generateFor($tenant, CarbonImmutable::parse('2026-10-15')))->toBe(0);
    }
});

test('inactive types and types without dues generate nothing', function () {
    [$tenant, $member] = generationMember();
    $member->membershipType->update(['is_active' => false]);
    expect(app(DuesScheduler::class)->generateFor($tenant, CarbonImmutable::parse('2026-10-15')))->toBe(0);
    $member->membershipType->update(['is_active' => true, 'amount' => null, 'period' => null]);
    expect(app(DuesScheduler::class)->generateFor($tenant, CarbonImmutable::parse('2026-10-15')))->toBe(0);
});

test('a zero override is recorded as waived', function () {
    [$tenant, $member] = generationMember(member: ['joined_on' => '2026-10-14', 'amount_override' => 0]);
    app(DuesScheduler::class)->generateFor($tenant, CarbonImmutable::parse('2026-10-15'));
    expect($member->charges()->firstOrFail()->status)->toBe(DuesChargeStatusEnum::Waived);
});

test('reactivation starts a new eligibility window without charging the gap', function () {
    [$tenant, $member] = generationMember();
    $this->travelTo(CarbonImmutable::parse('2026-10-03 12:00'));
    $member->update(['status' => MemberStatusEnum::Lapsed]);
    expect($member->fresh()->lapsed_on->toDateString())->toBe('2026-10-03');
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00'));
    $member->update(['status' => MemberStatusEnum::Active]);
    app(DuesScheduler::class)->generateFor($tenant, CarbonImmutable::parse('2026-10-15'));
    expect($member->fresh()->active_since->toDateString())->toBe('2026-10-14')
        ->and($member->charges()->pluck('period_start')->map->toDateString()->all())->toBe(['2026-10-15']);
});

test('type changes retain existing amounts and do not double bill overlapping periods', function () {
    [$tenant, $member] = generationMember('monthly', ['charge_current_period_on_join' => true]);
    $old = app(DuesCharges::class)->createFor($member, CarbonImmutable::parse('2026-10-01'));
    $new = MembershipType::factory()->create(['tenant_id' => $tenant->id, 'period' => 'daily', 'amount' => 800]);
    $member->update(['membership_type_id' => $new->id]);
    expect(app(DuesScheduler::class)->generateFor($tenant, CarbonImmutable::parse('2026-11-01')))->toBe(1)
        ->and($old->fresh()->amount)->toBe($old->amount)
        ->and($member->charges()->whereDate('period_start', '2026-11-01')->firstOrFail()->amount)->toBe(800);
});

test('the generation horizon is bounded to the newest 400 periods', function () {
    [$tenant, $member] = generationMember(member: ['joined_on' => '2020-01-01']);
    Log::spy();
    expect(app(DuesScheduler::class)->generateFor($tenant, CarbonImmutable::parse('2026-10-15')))->toBe(400)
        ->and($member->charges()->count())->toBe(400);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => $message === 'dues.generation.window_capped')->once();
});

test('scheduler respects rollout and restores the original tenant context', function () {
    [$first] = generationMember(member: ['joined_on' => '2026-10-14']);
    [$second] = generationMember(member: ['joined_on' => '2026-10-14']);
    app(TenantContext::class)->setTenant($first);
    expect(app(DuesScheduler::class)->generateFor($second, CarbonImmutable::parse('2026-10-15')))->toBe(1)
        ->and(app(TenantContext::class)->getTenant()?->id)->toBe($first->id);
    Artisan::call('community:enable', ['tenant' => $second->slug, '--off' => true]);
    expect(app(DuesScheduler::class)->generateFor($second->fresh(), CarbonImmutable::parse('2026-10-16')))->toBe(0);
});

test('the command uses tenant local time and processes each local date once', function () {
    [$tenant, $member] = generationMember(settings: ['timezone' => 'America/New_York'], member: ['joined_on' => '2026-10-13']);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 00:45:00', 'UTC'));
    $this->artisan('dues:process', ['--tenant' => $tenant->slug])->assertSuccessful();
    expect($member->charges()->pluck('period_start')->map->toDateString()->all())->toBe(['2026-10-14']);
    $this->artisan('dues:process', ['--tenant' => $tenant->slug])->assertSuccessful();
    expect($member->charges()->count())->toBe(1);
});

test('the command can explicitly run a test date and refuses invalid dates', function () {
    [$tenant, $member] = generationMember(member: ['joined_on' => '2026-10-14']);
    $this->artisan('dues:process', ['--tenant' => $tenant->slug, '--date' => '2026-10-15'])->assertSuccessful();
    expect($member->charges()->count())->toBe(1);
    $this->artisan('dues:process', ['--tenant' => $tenant->slug, '--date' => 'not-a-date'])->assertFailed();
});

test('rejoining requires permission and tenant ownership and keeps old pay links', function () {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    [$tenant, $owner] = eventHost('generation-rejoin');
    Artisan::call('community:enable', ['tenant' => $tenant->slug]);
    $member = Member::factory()->create(['tenant_id' => $tenant->id, 'status' => 'exited', 'exited_on' => '2026-10-02']);
    $token = $member->pay_token;
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00'));
    $url = route('tenant.community.members.rejoin', ['subdomain' => $tenant->slug, 'member' => $member->id]);
    $this->actingAs($owner)->withServerVariables(['HTTP_HOST' => eventSubdomainHost($tenant->slug)])->postJson($url)->assertSuccessful();
    $this->postJson($url)->assertUnprocessable();
    expect($member->fresh()->status)->toBe(MemberStatusEnum::Active)
        ->and($member->fresh()->active_since->toDateString())->toBe('2026-10-14')
        ->and($member->fresh()->exited_on)->toBeNull()
        ->and($member->fresh()->pay_token)->toBe($token);
    $foreign = Member::factory()->create();
    $this->postJson(route('tenant.community.members.rejoin', ['subdomain' => $tenant->slug, 'member' => $foreign->id]))->assertNotFound();
    $viewer = App\Models\User::factory()->create(['tenant_id' => $tenant->id]);
    $tenant->users()->attach($viewer->id);
    $this->actingAs($viewer)->postJson($url)->assertForbidden();
});

test('manual charges also reject pending lapsed and inactive memberships', function () {
    foreach (['pending', 'lapsed'] as $status) {
        [, $member] = generationMember(member: ['status' => $status]);
        expect(fn () => app(DuesCharges::class)->createFor($member, CarbonImmutable::parse('2026-10-15')))
            ->toThrow(DomainException::class, 'Only active members can be charged.');
    }
    [, $member] = generationMember();
    $member->membershipType->update(['is_active' => false]);
    expect(fn () => app(DuesCharges::class)->createFor($member->fresh(), CarbonImmutable::parse('2026-10-15')))
        ->toThrow(DomainException::class, 'This membership type is inactive.');
});

test('command refuses impossible calendar dates and does not bill banned organisations', function () {
    [$tenant, $member] = generationMember(member: ['joined_on' => '2026-10-14']);
    $this->artisan('dues:process', ['--tenant' => $tenant->slug, '--date' => '2026-02-30'])->assertFailed();
    $tenant->update(['status' => App\Enum\TenantStatusEnum::BANNED]);
    $this->artisan('dues:process', ['--date' => '2026-10-15'])->assertSuccessful();
    expect($member->charges()->count())->toBe(0);
});

test('no automatic billing occurs before local 0030', function () {
    [$tenant, $member] = generationMember(member: ['joined_on' => '2026-10-14']);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 00:15:00', 'Africa/Accra'));
    $this->artisan('dues:process', ['--tenant' => $tenant->slug])->assertSuccessful();
    expect($member->charges()->count())->toBe(0);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 00:45:00', 'Africa/Accra'));
    $this->artisan('dues:process', ['--tenant' => $tenant->slug])->assertSuccessful();
    expect($member->charges()->count())->toBe(1);
});

test('banned organisations never generate new dues through service or command', function () {
    [$tenant, $member] = generationMember(member: ['joined_on' => '2026-10-14']);
    $tenant->update(['status' => App\Enum\TenantStatusEnum::BANNED]);
    expect(app(DuesScheduler::class)->generateFor($tenant->fresh(), CarbonImmutable::parse('2026-10-15')))->toBe(0);
    $this->artisan('dues:process', ['--date' => '2026-10-15'])->assertSuccessful();
    expect($member->charges()->count())->toBe(0);
});

test('initial settings creation tolerates another scheduler creating the row first', function () {
    [$tenant] = generationMember(member: ['joined_on' => '2026-10-14']);
    CommunitySettings::withoutGlobalScopes()->where('tenant_id', $tenant->id)->delete();
    $inserted = false;
    Illuminate\Support\Facades\DB::listen(function ($query) use ($tenant, &$inserted): void {
        if (! $inserted && str_starts_with($query->sql, 'select') && str_contains($query->sql, '"community_settings"')) {
            $inserted = true;
            CommunitySettings::factory()->create(['tenant_id' => $tenant->id, 'generate_days_ahead' => 0]);
        }
    });
    $this->artisan('dues:process', ['--tenant' => $tenant->slug, '--date' => '2026-10-15'])->assertSuccessful();
    expect($inserted)->toBeTrue()
        ->and(CommunitySettings::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('rejoining or reactivation always starts next period even when initial joins include the current period', function () {
    foreach (['exited', 'lapsed'] as $status) {
        [$tenant, $member] = generationMember('monthly', ['charge_current_period_on_join' => true]);
        $this->travelTo(CarbonImmutable::parse('2026-10-02 12:00'));
        $member->update(['status' => $status]);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00'));
        $member->update(['status' => 'active', 'rejoined_on' => $status === 'exited' ? '2026-10-20' : null]);
        expect(app(DuesScheduler::class)->generateFor($tenant, CarbonImmutable::parse('2026-10-20')))->toBe(0)
            ->and(app(DuesScheduler::class)->generateFor($tenant, CarbonImmutable::parse('2026-11-01')))->toBe(1)
            ->and($member->charges()->firstOrFail()->period_start->toDateString())->toBe('2026-11-01');
    }
});

test('exit and lapse cancel unpaid advance charges while preserving past and paid charges', function () {
    foreach (['exited', 'lapsed'] as $status) {
        [$tenant, $member] = generationMember(settings: ['generate_days_ahead' => 7], member: ['joined_on' => '2026-09-30']);
        app(DuesScheduler::class)->generateFor($tenant, CarbonImmutable::parse('2026-10-01'));
        $paid = $member->charges()->whereDate('period_start', '2026-10-05')->firstOrFail();
        app(DuesCharges::class)->recordOffline($paid, (int) App\Models\User::factory()->create()->id, 'Prepaid');
        $this->travelTo(CarbonImmutable::parse('2026-10-02 12:00'));
        $member->update(['status' => $status]);
        expect($member->charges()->where('status', DuesChargeStatusEnum::Open)->pluck('period_start')->map->toDateString()->all())->toBe(['2026-10-01'])
            ->and($paid->fresh()->status)->toBe(DuesChargeStatusEnum::Paid)
            ->and($member->charges()->where('status', DuesChargeStatusEnum::Void)->count())->toBe(6);
        $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00'));
        $member->update(['status' => 'active', 'rejoined_on' => $status === 'exited' ? '2026-10-10' : null]);
        app(DuesScheduler::class)->generateFor($tenant, CarbonImmutable::parse('2026-10-11'));
        expect($member->charges()->where('status', DuesChargeStatusEnum::Void)->count())->toBe(6);
    }
});

test('a stale active member cannot create a manual charge after exit or lapse', function () {
    foreach (['exited', 'lapsed'] as $status) {
        [, $member] = generationMember();
        $stale = $member->fresh(['membershipType']);
        $member->update(['status' => $status]);
        expect(fn () => app(DuesCharges::class)->createFor($stale, CarbonImmutable::parse('2026-10-20')))
            ->toThrow(DomainException::class);
        expect($member->charges()->count())->toBe(0);
    }
});
