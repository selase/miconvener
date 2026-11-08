<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventOperationPillar;
use App\Models\EventStaffAssignment;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
    $this->tenant = Tenant::factory()->create(['slug' => 'secondary-scope', 'isolation_mode' => 'shared']);
    $this->event = Event::factory()->published()->create(['tenant_id' => $this->tenant->id]);
    $this->actor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    setPermissionsTeamId($this->tenant->id);
    $this->actor->assignRole('Event Organiser');
    $this->tenant->users()->attach($this->actor->id);
    EventStaffAssignment::create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id, 'user_id' => $this->actor->id]);
    $this->base = 'http://secondary-scope.'.mb_ltrim((string) config('session.domain'), '.').'/events/'.$this->event->id;
    $this->actingAs($this->actor);
});

it('limits operation owner options and forged owners to the event team and existing owners', function () {
    $unrelated = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $assigned = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $existing = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $foreign = User::factory()->create();
    $this->tenant->users()->attach([$unrelated->id, $assigned->id, $existing->id]);
    EventStaffAssignment::create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id, 'user_id' => $assigned->id]);
    EventOperationPillar::seedDefaultsForEvent($this->event);
    $pillar = $this->event->operationPillars()->firstOrFail();
    $task = $this->event->operationTasks()->create(['tenant_id' => $this->tenant->id, 'pillar_id' => $pillar->id, 'title' => 'Existing', 'owner_id' => $existing->id, 'estimated_budget' => 12000, 'actual_budget' => 5000]);
    $response = $this->getJson($this->base.'/operations')->assertOk();
    $response->assertJsonMissingPath('summary.total_estimated_budget')->assertJsonMissingPath('summary.total_actual_budget');
    $returnedTask = collect($response->json('pillars'))->flatMap(fn ($pillar) => $pillar['tasks'])->firstWhere('id', $task->id);
    expect($returnedTask)->not->toHaveKeys(['estimated_budget', 'actual_budget']);
    foreach (['estimated_budget', 'actual_budget'] as $field) {
        $this->postJson($this->base.'/operations/tasks', ['pillar_id' => $pillar->id, 'title' => 'Budget', $field => 1])->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->putJson($this->base.'/operations/tasks/'.$task->id, [$field => 1])->assertUnprocessable()->assertJsonValidationErrors($field);
    }
    expect(collect($response->json('team_members'))->pluck('id')->sort()->values()->all())->toBe(collect([$this->actor->id, $assigned->id, $existing->id])->sort()->values()->all());
    foreach ([$unrelated->id, $foreign->id] as $ownerId) {
        $this->postJson($this->base.'/operations/tasks', ['pillar_id' => $pillar->id, 'title' => 'Forged', 'owner_id' => $ownerId])->assertUnprocessable()->assertJsonValidationErrors('owner_id');
        $this->putJson($this->base.'/operations/tasks/'.$task->id, ['owner_id' => $ownerId])->assertUnprocessable()->assertJsonValidationErrors('owner_id');
    }
    $this->putJson($this->base.'/operations/tasks/'.$task->id, ['owner_id' => $assigned->id])->assertOk();
});

it('keeps tenant notification settings and tenant-wide audiences outside organiser access', function () {
    $payload = ['name' => 'Committee', 'target_role' => 'organizer', 'target_audience' => 'organizers', 'trigger_type' => 'scheduled_offset', 'offset_direction' => 'before', 'offset_amount' => 1, 'offset_unit' => 'days', 'channels' => ['email'], 'subject' => 'Hello', 'body_template' => 'Hello {name}'];
    $rule = $this->event->notificationRules()->create(['tenant_id' => $this->tenant->id] + $payload);
    $response = $this->getJson($this->base.'/notification-rules')->assertOk();
    expect($response->json('settings'))->toBeNull()
        ->and(collect($response->json('audiences'))->pluck('key')->all())->not->toContain('organizers')
        ->and(collect($response->json('rules'))->pluck('id')->all())->not->toContain($rule->id);
    $this->postJson($this->base.'/notification-rules', $payload)->assertUnprocessable();
    $this->putJson($this->base.'/notification-rules/'.$rule->id, ['subject' => 'Changed'])->assertForbidden();
    $this->patchJson($this->base.'/notification-rules/'.$rule->id.'/toggle')->assertForbidden();
    $this->postJson($this->base.'/notification-rules/'.$rule->id.'/dispatch')->assertForbidden();
    $this->putJson($this->base.'/notification-settings', ['sms_enabled' => true, 'whatsapp_enabled' => true, 'overage_billing_enabled' => true, 'anti_abuse_cooldown_minutes' => 5])->assertForbidden();
    $payload['target_role'] = 'attendee';
    $payload['target_audience'] = 'all';
    $this->postJson($this->base.'/notification-rules', $payload)->assertCreated();
});

it('preserves admin operation budgets and organisation notification settings', function () {
    $this->seed(Database\Seeders\RoleSeeder::class);
    $this->actor->syncRoles(['Org Admin']);
    $other = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->tenant->users()->attach($other->id);
    $response = $this->getJson($this->base.'/operations')->assertOk();
    expect($response->json('can_manage_budgets'))->toBeTrue()
        ->and(collect($response->json('team_members'))->pluck('id')->all())->toContain($other->id);
    $response->assertJsonPath('summary.total_actual_budget', 0);
    $pillar = $this->event->operationPillars()->firstOrFail();
    $this->postJson($this->base.'/operations/tasks', ['pillar_id' => $pillar->id, 'title' => 'Budget allowed', 'estimated_budget' => 12.5])->assertCreated()->assertJsonPath('task.estimated_budget', 1250);
    $this->getJson($this->base.'/notification-rules')->assertOk()->assertJsonPath('settings.tenant_id', $this->tenant->id);
    $this->putJson($this->base.'/notification-settings', ['sms_enabled' => true, 'whatsapp_enabled' => true, 'overage_billing_enabled' => true, 'anti_abuse_cooldown_minutes' => 5])->assertOk();
});

it('limits abstract reviewer options and forged reviewer assignments to this event team', function () {
    $unrelated = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $assigned = User::factory()->create(['tenant_id' => Tenant::factory()->create(['isolation_mode' => 'shared'])->id]);
    $existing = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $foreign = User::factory()->create();
    $this->tenant->users()->attach([$unrelated->id, $assigned->id, $existing->id]);
    EventStaffAssignment::create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id, 'user_id' => $assigned->id]);
    $abstract = $this->event->abstracts()->create(['tenant_id' => $this->tenant->id, 'title' => 'Event research', 'code' => 'ABS-001', 'status' => 'submitted']);
    $abstract->reviews()->create(['tenant_id' => $this->tenant->id, 'reviewer_id' => $existing->id, 'status' => 'pending']);
    $response = $this->getJson($this->base.'/data/abstracts')->assertOk();
    expect(collect($response->json('reviewers'))->pluck('id')->sort()->values()->all())->toBe(collect([$this->actor->id, $assigned->id, $existing->id])->sort()->values()->all());
    foreach ([$unrelated->id, $foreign->id] as $reviewerId) {
        $this->postJson($this->base.'/abstracts/'.$abstract->id.'/assign-reviewer', ['reviewer_id' => $reviewerId])->assertUnprocessable()->assertJsonValidationErrors('reviewer_id');
    }
    $this->postJson($this->base.'/abstracts/'.$abstract->id.'/assign-reviewer', ['reviewer_id' => $assigned->id])->assertOk();
});

it('keeps sponsor identities and deliverables editable without exposing or accepting sponsorship money', function () {
    $sponsor = $this->event->sponsors()->create(['tenant_id' => $this->tenant->id, 'name' => 'Partner organisation', 'tier' => 'partner', 'amount' => 18500, 'currency' => 'GHS']);
    $this->getJson($this->base.'/data/sponsors')->assertOk()->assertJsonMissingPath('0.amount')->assertJsonPath('0.name', 'Partner organisation');
    $this->postJson($this->base.'/sponsors', ['name' => 'New sponsor', 'tier' => 'partner', 'amount' => 123])->assertUnprocessable()->assertJsonValidationErrors('amount');
    $this->postJson($this->base.'/sponsors', ['name' => 'New sponsor', 'tier' => 'partner'])->assertCreated()->assertJsonMissingPath('amount');
    $this->postJson($this->base.'/sponsors/'.$sponsor->id.'/deliverables', ['description' => 'Logo on programme'])->assertCreated()->assertJsonMissingPath('amount');
    expect($sponsor->fresh()->amount)->toBe(18500);
});

it('requires an administrator to delete recorded sponsorship or operation budgets while allowing zero-value deletion', function () {
    $sponsor = $this->event->sponsors()->create(['tenant_id' => $this->tenant->id, 'name' => 'Recorded sponsorship', 'tier' => 'partner', 'amount' => 15000, 'currency' => 'GHS']);
    $pillar = $this->event->operationPillars()->create(['tenant_id' => $this->tenant->id, 'name' => 'Delivery', 'slug' => 'delivery', 'is_default' => false]);
    $task = $this->event->operationTasks()->create(['tenant_id' => $this->tenant->id, 'pillar_id' => $pillar->id, 'title' => 'Recorded operation', 'estimated_budget' => 10000, 'actual_budget' => 3000]);
    $this->getJson($this->base.'/data/sponsors')->assertOk()->assertJsonPath('0.can_delete', false);
    $response = $this->getJson($this->base.'/operations')->assertOk();
    $returnedPillar = collect($response->json('pillars'))->firstWhere('id', $pillar->id);
    expect($returnedPillar['can_delete'])->toBeFalse()->and($returnedPillar['tasks'][0]['can_delete'])->toBeFalse();
    $this->deleteJson($this->base.'/sponsors/'.$sponsor->id)->assertForbidden();
    $this->deleteJson($this->base.'/operations/tasks/'.$task->id)->assertForbidden();
    $this->deleteJson($this->base.'/operations/pillars/'.$pillar->id)->assertForbidden();
    expect($sponsor->fresh()->amount)->toBe(15000)->and($task->fresh()->actual_budget)->toBe(3000);
    $sponsor->update(['amount' => 0]);
    $task->update(['estimated_budget' => 0, 'actual_budget' => 0]);
    $this->deleteJson($this->base.'/sponsors/'.$sponsor->id)->assertOk();
    $this->deleteJson($this->base.'/operations/tasks/'.$task->id)->assertOk();
    $this->deleteJson($this->base.'/operations/pillars/'.$pillar->id)->assertOk();
});
