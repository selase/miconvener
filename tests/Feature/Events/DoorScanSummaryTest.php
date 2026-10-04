<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventStaffLink;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Events\DoorCheckIn;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

/**
 * What the console shows from the door log: entries per day, and the tickets
 * admitted twice in one day -- possible only while a door was offline.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    $this->tenant = Tenant::factory()->create(['slug' => 'summary', 'isolation_mode' => 'shared']);
    $this->host = 'summary.'.mb_ltrim((string) config('session.domain'), '.');
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    setPermissionsTeamId($this->tenant->id);
    $this->user->assignRole('Org Superadmin');
    $this->tenant->users()->attach($this->user->id);

    $this->event = Event::factory()->published()->create([
        'tenant_id' => $this->tenant->id,
        'starts_at' => Carbon::parse('2026-11-02 08:00', 'Africa/Accra'),
        'ends_at' => Carbon::parse('2026-11-03 17:00', 'Africa/Accra'),
    ]);
    $gate = EventStaffLink::factory()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id, 'name' => 'Gate A – Kwame']);
    $this->guest = EventRegistration::factory()->create([
        'tenant_id' => $this->tenant->id,
        'event_id' => $this->event->id,
        'status' => 'confirmed',
        'full_name' => 'Ama Owusu',
    ]);
    $door = app(DoorCheckIn::class);

    $this->travelTo(Carbon::parse('2026-11-02 09:00', 'Africa/Accra'));
    $door->checkIn($this->guest, staffLink: $gate);
    // A second door, offline, also let her in five minutes earlier.
    $door->checkIn($this->guest->fresh(), scannedAt: now()->subMinutes(5), clientScanId: 'off-1', wasOffline: true);
    $this->travelTo(Carbon::parse('2026-11-03 09:00', 'Africa/Accra'));
    $door->checkIn($this->guest->fresh(), staffLink: $gate);
});

test('the console shows entries per day and tickets used twice in a day', function (): void {
    $data = $this->actingAs($this->user)
        ->getJson("http://{$this->host}/events/{$this->event->id}/data/door-scans", ['HTTP_HOST' => $this->host])
        ->assertOk()
        ->json();

    expect($data['days'])->toBe([
        ['day' => '2026-11-02', 'number' => 1, 'admitted' => 1],
        ['day' => '2026-11-03', 'number' => 2, 'admitted' => 1],
    ])
        ->and($data['used_twice'])->toHaveCount(1)
        ->and($data['used_twice'][0]['name'])->toBe('Ama Owusu')
        ->and($data['used_twice'][0]['number'])->toBe(1)
        ->and(collect($data['used_twice'][0]['entries'])->pluck('by')->all())->toContain('Gate A – Kwame');
});

test('the check-in export says how many days each guest came and when a ticket was used twice', function (): void {
    $csv = $this->actingAs($this->user)
        ->get("http://{$this->host}/events/{$this->event->id}/reports/checkins", ['HTTP_HOST' => $this->host])
        ->assertOk()
        ->streamedContent();

    $rows = array_map('str_getcsv', array_filter(explode("\n", $csv)));
    $header = $rows[0];
    $ama = collect($rows)->first(fn (array $row): bool => $row[0] === 'Ama Owusu');

    expect($header)->toContain('Days attended', 'Used twice on')
        ->and($ama[array_search('Days attended', $header, true)])->toBe('2')
        ->and($ama[array_search('Used twice on', $header, true)])->toBe('2026-11-02');
});

test('someone who cannot see the event cannot read the door log', function (): void {
    $outsider = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->tenant->users()->attach($outsider->id);

    $this->actingAs($outsider)
        ->getJson("http://{$this->host}/events/{$this->event->id}/data/door-scans", ['HTTP_HOST' => $this->host])
        ->assertForbidden();
});
