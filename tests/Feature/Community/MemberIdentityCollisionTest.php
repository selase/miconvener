<?php

declare(strict_types=1);
use App\Models\Member;
use App\Models\MembershipType;
use App\Services\Community\MemberIdentity;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    Cache::flush();
    [$this->tenant,$this->owner] = eventHost('collisions');
    Artisan::call('community:enable', ['tenant' => $this->tenant->slug]);
    $this->type = MembershipType::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Regular']);
    $this->actingAs($this->owner);
    $this->url = 'http://'.eventSubdomainHost('collisions').'/community/people/import';
});
function collisionPreview(object $test, string $rows): array
{
    return $test->postJson($test->url.'/preview', ['file' => UploadedFile::fake()->createWithContent('members.csv', "name,phone,email,membership_type,joined_on\n".$rows)])->assertOk()->json();
}
it('keeps every tenant-bound candidate when family names and phones collide', function (): void {
    $a = Member::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Alex Mensah', 'phone' => '+233241234567', 'email' => 'a@example.test']);
    $b = Member::factory()->create(['tenant_id' => $this->tenant->id, 'name' => $a->name, 'phone' => $a->phone, 'email' => 'b@example.test']);
    $foreign = Member::factory()->create(['name' => $a->name, 'phone' => $a->phone]);
    $rows = app(MemberIdentity::class)->candidates($this->tenant, $a->name, $a->phone, null);
    expect(array_column($rows, 'id'))->toContain($a->id, $b->id)->not->toContain($foreign->id);
});
it('requires a reviewed decision for phone collisions and can explicitly create a distinct person', function (): void {
    $historic = Member::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Alex', 'phone' => '+233241234567', 'email' => 'old@example.test']);
    $preview = collisionPreview($this, "Alex,0241234567,new@example.test,Regular,\n");
    expect($preview['rows'][0]['status'])->toBe('review_required')->and($preview['rows'][0]['candidates'][0]['id'])->toBe($historic->id);
    $payload = ['token' => $preview['token'], 'existing_policy' => 'update'];
    $this->postJson($this->url.'/confirm', $payload)->assertUnprocessable();
    $preview = collisionPreview($this, "Alex,0241234567,new@example.test,Regular,\n");
    $this->postJson($this->url.'/confirm', ['token' => $preview['token'], 'existing_policy' => 'update', 'decisions' => ['2' => ['action' => 'create_distinct']]])->assertOk()->assertJsonPath('created', 1)->assertJsonPath('updated', 0);
    expect($historic->fresh()->email)->toBe('old@example.test')->and(Member::where('tenant_id', $this->tenant->id)->count())->toBe(2);
});
it('rejects foreign chosen members and stale previews instead of guessing a different match', function (): void {
    $member = Member::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Alex', 'phone' => '+233241234567']);
    $foreign = Member::factory()->create();
    $preview = collisionPreview($this, "Alex,0241234567,new@example.test,Regular,\n");
    $this->postJson($this->url.'/confirm', ['token' => $preview['token'], 'existing_policy' => 'skip', 'decisions' => ['2' => ['action' => 'update', 'member_id' => $foreign->id]]])->assertUnprocessable();
    $preview = collisionPreview($this, "Alex,0241234567,new@example.test,Regular,\n");
    $member->update(['phone' => '+233241234568']);
    $this->postJson($this->url.'/confirm', ['token' => $preview['token'], 'existing_policy' => 'skip', 'decisions' => ['2' => ['action' => 'update', 'member_id' => $member->id]]])->assertStatus(409);
    expect($member->fresh()->phone)->toBe('+233241234568');
});
it('uses contact-change safeguards when an explicit reviewed import updates a member', function (): void {
    $member = Member::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Alex', 'phone' => '+233241234567', 'email' => 'old@example.test']);
    $preview = collisionPreview($this, "Alex,0241234567,new@example.test,Regular,\n");
    Illuminate\Support\Facades\Queue::fake();
    $this->postJson($this->url.'/confirm', ['token' => $preview['token'], 'existing_policy' => 'skip', 'decisions' => ['2' => ['action' => 'update', 'member_id' => $member->id]]])->assertOk()->assertJsonPath('updated', 1);
    expect($member->fresh()->access_version)->toBe(2)->and(App\Models\CommunityContactNotice::where('member_id', $member->id)->count())->toBe(3);
});

it('requires a fresh staff-only candidate acknowledgement before manually creating a distinct member', function (): void {
    $member = Member::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Alex', 'phone' => '+233241234567', 'email' => 'old@example.test']);
    $base = 'http://'.eventSubdomainHost('collisions').'/community/people';
    $data = ['name' => 'Alex', 'phone' => '+233241234567', 'email' => 'new@example.test', 'membership_type_id' => $this->type->id];
    $this->postJson($base.'/members', $data)->assertUnprocessable();
    $review = $this->postJson($base.'/member-candidates', $data)->assertOk()->json();
    $this->postJson($base.'/members', [...$data, 'confirm_distinct' => true, 'duplicate_revision' => 'forged'])->assertUnprocessable();
    $this->postJson($base.'/members', [...$data, 'confirm_distinct' => true, 'duplicate_revision' => $review['revision']])->assertCreated();
    $reader = App\Models\User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->tenant->users()->attach($reader->id);
    $this->actingAs($reader)->postJson($base.'/member-candidates', $data)->assertForbidden();
});

it('never allows a duplicate exact email through a distinct-person acknowledgement', function (): void {
    $member = Member::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'old@example.test']);
    $preview = collisionPreview($this, "Different,0241234567,old@example.test,Regular,\n");
    $this->postJson($this->url.'/confirm', ['token' => $preview['token'], 'existing_policy' => 'skip', 'decisions' => ['2' => ['action' => 'create_distinct']]])->assertUnprocessable();
    expect(Member::where('tenant_id', $this->tenant->id)->count())->toBe(1);
});

it('rejects an altered cached preview and preserves every member', function (): void {
    $preview = collisionPreview($this, "New,0241234567,new@example.test,Regular,\n");
    $key = 'community.import.preview:'.$preview['token'];
    $stored = Cache::get($key);
    $stored['rows'][0]['email'] = 'tampered@example.test';
    Cache::put($key, $stored, now()->addMinutes(30));
    $this->postJson($this->url.'/confirm', ['token' => $preview['token'], 'existing_policy' => 'skip'])->assertStatus(409);
    expect(Member::where('tenant_id', $this->tenant->id)->count())->toBe(0);
});

it('refuses rollback when reviewed distinct people now share name and phone without deleting data', function (): void {
    $a = Member::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Alex', 'phone' => '+233241234567']);
    $b = Member::factory()->create(['tenant_id' => $this->tenant->id, 'name' => $a->name, 'phone' => $a->phone]);
    $migration = require database_path('migrations/landlord/2026_10_19_100600_allow_reviewed_shared_member_identities.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'no member has been deleted or merged');
    expect($a->fresh()->phone)->toBe($a->phone)->and($b->fresh()->id)->toBe($b->id);
});

it('refuses an update when the selected member changes before its row lock is acquired', function (): void {
    $member = Member::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Alex', 'phone' => '+233241234567', 'email' => 'old@example.test']);
    $preview = collisionPreview($this, "Alex,0241234567,new@example.test,Regular,\n");
    $changed = false;
    $member->getConnection()->beforeExecuting(function (string $query) use ($member, &$changed): void {
        if (! $changed && str_contains($query, '"members"') && str_contains($query, 'for update')) {
            $changed = true;
            Member::withoutGlobalScopes()->whereKey($member->id)->update(['email' => 'changed-during-review@example.test', 'access_version' => 2]);
        }
    });
    $this->postJson($this->url.'/confirm', ['token' => $preview['token'], 'existing_policy' => 'skip', 'decisions' => ['2' => ['action' => 'update', 'member_id' => $member->id]]])->assertStatus(409);
    expect($changed)->toBeTrue()->and($member->fresh()->email)->toBe('old@example.test');
});

it('does not bypass exact-email duplicate safeguards through join review', function (): void {
    $member = Member::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'same@example.test']);
    App\Models\CommunitySettings::for($this->tenant)->fill(['public_join_enabled' => true, 'public_membership_type_id' => $this->type->id])->save();
    $session = app('session.store');
    $session->put(App\Services\Events\PlatformAttendeeVerification::SESSION_KEY, ['email' => $member->email, 'expires_at' => now()->addHour()->timestamp]);
    $joining = app(App\Services\Community\MembershipJoining::class);
    $row = $joining->submit($this->tenant, $session, ['name' => 'Another Person', 'channel' => 'email'], (string) Illuminate\Support\Str::uuid());
    expect(fn () => $joining->approve($row, $this->owner, true, 'Reviewed but this exact email is already recorded.'))->toThrow(Illuminate\Validation\ValidationException::class);
    expect(Member::where('tenant_id', $this->tenant->id)->count())->toBe(1);
});

it('refuses an oversized collision row instead of silently truncating candidates or growing the preview without bounds', function (): void {
    Member::factory()->count(26)->create(['tenant_id' => $this->tenant->id, 'phone' => '+233241234567']);
    $preview = collisionPreview($this, "New Person,0241234567,new@example.test,Regular,\n");
    expect($preview['rows'][0]['status'])->toBe('error')->and($preview['rows'][0]['errors'][0])->toContain('25 possible matches')
        ->and($preview['rows'][0]['candidates'])->toBe([])
        ->and(count(app(MemberIdentity::class)->candidates($this->tenant, 'New Person', '+233241234567', 'new@example.test')))->toBe(26);
});
