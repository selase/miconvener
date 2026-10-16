<?php

declare(strict_types=1);

use App\Models\CommunityFollower;
use App\Models\Member;
use App\Models\Tenant;
use App\Services\Community\Following;
use App\Services\Community\PhoneVerification;
use App\Services\Events\PlatformAttendeeVerification;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    Artisan::call('community:following-enable', ['tenant' => $this->tenant->slug]);
    $this->session = app('session.store');
    $this->session->put(PlatformAttendeeVerification::SESSION_KEY, ['email' => 'follow@example.test', 'expires_at' => now()->addHour()->timestamp]);
});

it('follows independently of Community without creating a member or inferring a phone', function (): void {
    $following = app(Following::class);
    $row = $following->follow($this->tenant, $this->session, 'email', false, null);
    $again = $following->follow($this->tenant, $this->session, 'email', false, null);
    expect($row->id)->toBe($again->id)->and($this->tenant->fresh()->communityEnabled())->toBeFalse()
        ->and($following->isFollowing($this->tenant, $this->session, 'email'))->toBeTrue()
        ->and(Member::query()->count())->toBe(0)->and(CommunityFollower::query()->count())->toBe(1);
    expect(fn () => $following->follow($this->tenant, $this->session, 'sms', false, null))->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
});

it('scopes consent to each proven contact and each organisation', function (): void {
    $other = Tenant::factory()->create();
    Artisan::call('community:following-enable', ['tenant' => $other->slug]);
    $following = app(Following::class);
    $following->follow($this->tenant, $this->session, 'email', false, null);
    expect($following->isFollowing($other, $this->session, 'email'))->toBeFalse();
    $this->session->put(PhoneVerification::SESSION_KEY, ['phone' => '+233241234567', 'expires_at' => now()->addHour()->timestamp]);
    $following->follow($this->tenant, $this->session, 'sms', false, null);
    expect(CommunityFollower::query()->where('tenant_id', $this->tenant->id)->count())->toBe(2);
});

it('rejects a foreign event source and disabled Following', function (): void {
    $event = App\Models\Event::factory()->create();
    expect(fn () => app(Following::class)->follow($this->tenant, $this->session, 'email', false, $event))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
    Artisan::call('community:following-enable', ['tenant' => $this->tenant->slug, '--off' => true]);
    expect(fn () => app(Following::class)->follow($this->tenant->fresh(), $this->session, 'email', false, null))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('derives the follower address from proof rather than a forged submitted address', function (): void {
    $host = mb_ltrim((string) config('session.domain'), '.');
    $this->withServerVariables(['HTTP_HOST' => $host])->withSession($this->session->all())
        ->postJson('/my/communities/'.$this->tenant->slug.'/follow', ['channel' => 'email', 'address' => 'someone-else@example.test'])
        ->assertCreated();
    expect(CommunityFollower::query()->firstOrFail()->address)->toBe('follow@example.test');
    $this->deleteJson('/my/communities/'.$this->tenant->slug.'/follow', ['channel' => 'email'])->assertOk();
    expect(CommunityFollower::query()->firstOrFail()->unfollowed_at)->not->toBeNull();
});

it('lists followers for authorised staff with Community off and denies other staff', function (): void {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    [$tenant, $owner] = eventHost('followers-qa');
    Artisan::call('community:following-enable', ['tenant' => $tenant->slug]);
    app(Following::class)->follow($tenant, $this->session, 'email', false, null);
    $url = 'http://'.eventSubdomainHost('followers-qa').'/community/followers';
    $this->actingAs($owner)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('followers-qa')])
        ->getJson($url)->assertOk()->assertJsonPath('followers.data.0.address', 'follow@example.test');
    $reader = App\Models\User::factory()->create(['tenant_id' => $tenant->id]);
    $tenant->users()->attach($reader->id);
    $this->actingAs($reader)->getJson($url)->assertForbidden();
    $this->actingAs($owner);
    Artisan::call('community:following-enable', ['tenant' => $tenant->slug, '--off' => true]);
    $this->getJson($url)->assertNotFound();
});

it('requires verification for anonymous follow writes', function (): void {
    $this->session->flush();
    $host = mb_ltrim((string) config('session.domain'), '.');
    $this->withServerVariables(['HTTP_HOST' => $host])->postJson('/my/communities/'.$this->tenant->slug.'/follow', ['channel' => 'email'])->assertUnauthorized();
});
