<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    $this->tenant = Tenant::factory()->create(['slug' => 'hosts', 'isolation_mode' => 'shared']);
    $this->host = 'hosts.'.mb_ltrim((string) config('session.domain'), '.');
    $this->event = Event::factory()->published()->create(['tenant_id' => $this->tenant->id]);
    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    setPermissionsTeamId($this->tenant->id);
    $this->admin->assignRole('Org Admin');
    $this->tenant->users()->attach($this->admin->id);
    $this->saveHosts = fn (array $data) => $this->actingAs($this->admin)->postJson("http://{$this->host}/events/{$this->event->id}/hosts", $data, ['HTTP_HOST' => $this->host]);
    $this->publicHosts = fn () => $this->get("http://{$this->host}/e/{$this->event->slug}", ['HTTP_HOST' => $this->host]);
});

test('empty host credits retain the managing organisation fallback', function (): void {
    ($this->publicHosts)()->assertOk()->assertInertia(fn (Assert $page) => $page->where('event.hosts', [])->where('org.name', $this->tenant->name));
});

test('hosts are ordered credits independent of tenant identity and may return to fallback', function (): void {
    ($this->saveHosts)(['version' => 0, 'hosts' => [['name' => 'Ada', 'type' => 'individual', 'website_url' => 'https://example.com'], ['name' => 'Friends', 'type' => 'organisation']]])->assertOk()->assertJsonPath('version', 1);
    ($this->publicHosts)()->assertInertia(fn (Assert $page) => $page->where('event.hosts.0.name', 'Ada')->where('event.hosts.1.name', 'Friends')->where('org.name', $this->tenant->name)->missing('event.hosts.0.tenant_id')->missing('event.hosts.0.logo_path'));
    ($this->saveHosts)(['version' => 0, 'hosts' => []])->assertConflict();
    expect($this->event->hosts()->count())->toBe(2);
    ($this->saveHosts)(['version' => 1, 'hosts' => []])->assertOk();
    expect($this->event->hosts()->count())->toBe(0)->and($this->event->fresh()->tenant_id)->toBe($this->tenant->id);
});

test('host updates reject unsafe and unowned references', function (array $host): void {
    ($this->saveHosts)(['version' => 0, 'hosts' => [$host]])->assertUnprocessable();
})->with([
    [['name' => 'Ada', 'type' => 'individual', 'website_url' => 'javascript:alert(1)']],
    [['name' => 'Ada', 'type' => 'individual', 'website_url' => 'http://example.com']],
    [['name' => 'Ada', 'type' => 'individual', 'logo_path' => 'other/logo.png']],
    [['name' => 'Ada', 'type' => 'individual', 'id' => 'c272b073-8139-49b4-a98b-0b65e3ca509b']],
    [['name' => str_repeat('a', 161), 'type' => 'individual']],
    [['name' => 'Ada', 'type' => 'unknown']],
]);

test('host credits are withheld on private and draft event pages', function (): void {
    ($this->saveHosts)(['version' => 0, 'hosts' => [['name' => 'Secret host', 'type' => 'individual']]])->assertOk();
    $this->event->update(['visibility' => Event::VISIBILITY_PRIVATE]);
    ($this->publicHosts)()->assertInertia(fn (Assert $page) => $page->where('event.hosts', []));
    $this->event->update(['status' => Event::STATUS_DRAFT]);
    ($this->publicHosts)()->assertNotFound();
});

test('hosts require event update permission and tenant ownership', function (): void {
    $reader = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->tenant->users()->attach($reader->id);
    $this->actingAs($reader)->postJson("http://{$this->host}/events/{$this->event->id}/hosts", ['version' => 0, 'hosts' => []], ['HTTP_HOST' => $this->host])->assertForbidden();
    $foreign = Event::factory()->create();
    $this->actingAs($this->admin)->postJson("http://{$this->host}/events/{$foreign->id}/hosts", ['version' => 0, 'hosts' => []], ['HTTP_HOST' => $this->host])->assertNotFound();
});

test('logos survive reorder and soft deletion but retire after permanent event purge', function (): void {
    Illuminate\Support\Facades\Storage::fake('public');
    ($this->saveHosts)(['version' => 0, 'hosts' => [['name' => 'Ada', 'type' => 'individual', 'logo' => Illuminate\Http\UploadedFile::fake()->image('logo.png')]]])->assertOk();
    $host = $this->event->hosts()->firstOrFail();
    $path = $host->logo_path;
    Illuminate\Support\Facades\Storage::disk('public')->assertExists($path);
    ($this->saveHosts)(['version' => 1, 'hosts' => [['name' => 'Friends', 'type' => 'organisation'], ['id' => $host->id, 'name' => 'Ada', 'type' => 'individual']]])->assertOk();
    expect($host->fresh()->logo_path)->toBe($path)->and($host->fresh()->sort_order)->toBe(1);
    $this->event->delete();
    Illuminate\Support\Facades\Storage::disk('public')->assertExists($path);
    $this->event->restore();
    $this->event->forceDelete();
    // Exercise the deferred cleanup after the enclosing test transaction commits.
    $callbacks = app('db.transactions')->getPendingTransactions()->last()->getCallbacks();
    foreach ($callbacks as $callback) {
        $callback();
    }
    Illuminate\Support\Facades\Storage::disk('public')->assertMissing($path);
});

test('host updates honor event staff scope even with explicit update permission', function (): void {
    $staff = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->tenant->users()->attach($staff->id);
    $staff->assignRole('Event Staff');
    $staff->givePermissionTo('update event');
    $assigned = Event::factory()->create(['tenant_id' => $this->tenant->id]);
    App\Models\EventStaffAssignment::create(['tenant_id' => $this->tenant->id, 'user_id' => $staff->id, 'event_id' => $assigned->id]);
    $this->actingAs($staff)->postJson("http://{$this->host}/events/{$this->event->id}/hosts", ['version' => 0, 'hosts' => []], ['HTTP_HOST' => $this->host])->assertNotFound();
    $this->actingAs($staff)->postJson("http://{$this->host}/events/{$assigned->id}/hosts", ['version' => 0, 'hosts' => [['name' => 'Ada', 'type' => 'individual']]], ['HTTP_HOST' => $this->host])->assertOk();
});

test('an invalid retained host makes the complete replacement atomic', function (): void {
    ($this->saveHosts)(['version' => 0, 'hosts' => [['name' => 'Original', 'type' => 'individual']]])->assertOk();
    $other = Event::factory()->create(['tenant_id' => $this->tenant->id]);
    $foreign = App\Models\EventHost::factory()->create(['tenant_id' => $this->tenant->id, 'event_id' => $other->id]);
    ($this->saveHosts)(['version' => 1, 'hosts' => [['name' => 'New', 'type' => 'individual'], ['id' => $foreign->id, 'name' => 'Foreign', 'type' => 'organisation']]])->assertUnprocessable();
    expect($this->event->hosts()->pluck('name')->all())->toBe(['Original'])->and((int) $this->event->fresh()->hosts_version)->toBe(1);
});

test('ten hosts are accepted but eleven and omitted lists are rejected', function (): void {
    $hosts = array_fill(0, 10, ['name' => 'Friend', 'type' => 'individual']);
    ($this->saveHosts)(['version' => 0, 'hosts' => $hosts])->assertOk();
    ($this->saveHosts)(['version' => 1, 'hosts' => [...$hosts, $hosts[0]]])->assertUnprocessable();
    ($this->saveHosts)(['version' => 1])->assertUnprocessable();
    ($this->saveHosts)(['version' => 1, 'hosts' => ''])->assertOk();
    expect($this->event->hosts()->count())->toBe(0);
});

test('console exposes the current version and accepts logo removal without changing tenant profile', function (): void {
    Illuminate\Support\Facades\Storage::fake('public');
    ($this->saveHosts)(['version' => 0, 'hosts' => [['name' => 'Ada', 'type' => 'individual', 'logo' => Illuminate\Http\UploadedFile::fake()->image('logo.png')]]])->assertOk();
    $host = $this->event->hosts()->firstOrFail();
    ($this->saveHosts)(['version' => 1, 'hosts' => [['id' => $host->id, 'name' => 'Ada', 'type' => 'individual', 'remove_logo' => true]]])->assertOk()->assertJsonPath('hosts.0.logo_url', null);
    $this->actingAs($this->admin)->get("http://{$this->host}/events/{$this->event->id}", ['HTTP_HOST' => $this->host])->assertInertia(fn (Assert $page) => $page->where('event.hosts_version', 2)->where('event.hosts.0.name', 'Ada'));
    expect($this->tenant->fresh()->name)->toBe($this->tenant->name);
});

test('event lists load host credits in one bounded query', function (): void {
    Event::factory()->count(3)->create(['tenant_id' => $this->tenant->id]);
    $queries = [];
    Illuminate\Support\Facades\DB::connection('landlord')->listen(function ($query) use (&$queries): void {
        if (str_starts_with($query->sql, 'select') && str_contains($query->sql, '"event_hosts"')) {
            $queries[] = $query->sql;
        }
    });
    $this->actingAs($this->admin)->get("http://{$this->host}/events", ['HTTP_HOST' => $this->host])->assertOk();
    expect($queries)->toHaveCount(1);
});
