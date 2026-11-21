<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Permission;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

it('authorizes secondary staff and denies their revoked membership on fresh subscriptions', function (string $channel): void {
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $user = User::factory()->create();
    $user->tenants()->attach($tenant->id);
    setPermissionsTeamId($tenant->id);
    Permission::query()->firstOrCreate(['name' => 'read event', 'guard_name' => 'web'], ['category' => 'event']);
    $user->givePermissionTo('read event');
    require base_path('routes/channels.php');
    $authorize = Broadcast::getChannels()[$channel];

    expect($authorize($user, $event->id))->toBeTrue();
    $user->allTenants()->updateExistingPivot($tenant->id, ['status' => 'revoked']);
    expect($authorize($user, $event->id))->toBeFalse();
})->with(['event.{eventId}.sessions', 'event.{eventId}.service-requests']);
