<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventDynamicForm;
use App\Models\EventParticipantGroup;
use App\Models\EventRegistration;
use App\Models\EventTicketType;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('host can view cohorts and create a dynamic stratification group', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'slug' => 'cardio-summit-2026',
    ]);

    $ticketType = EventTicketType::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'name' => 'Consultant Cardiologist',
    ]);

    $response = $this->actingAs($user)
        ->getJson("http://{$host}/events/{$event->id}/participant-groups", ['HTTP_HOST' => $host]);

    $response->assertOk();
    $response->assertJsonStructure(['groups', 'meta' => ['ticket_types', 'sessions', 'forms']]);

    // Create dynamic cohort
    $createResponse = $this->actingAs($user)
        ->postJson("http://{$host}/events/{$event->id}/participant-groups", [
            'name' => 'Active Checked-in Consultants',
            'description' => 'Consultants who have completed on-site badge scanning.',
            'color' => '#10B981',
            'icon' => 'user-check',
            'type' => 'dynamic',
            'criteria' => [
                [
                    'type' => 'ticket_type',
                    'operator' => 'equals',
                    'value' => $ticketType->id,
                ],
                [
                    'type' => 'status',
                    'operator' => 'equals',
                    'value' => 'checked_in',
                ],
            ],
        ], ['HTTP_HOST' => $host]);

    $createResponse->assertCreated();
    $createResponse->assertJsonPath('group.name', 'Active Checked-in Consultants');

    $group = $event->participantGroups()->where('slug', 'active-checked-in-consultants')->firstOrFail();
    expect($group->type)->toBe('dynamic')
        ->and(count($group->criteria))->toBe(2);
});

test('dynamic rule engine accurately stratifies attendees by ticket type and check-in status', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'slug' => 'stratify-summit-2026',
    ]);

    $ticketVIP = EventTicketType::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'name' => 'VIP Faculty',
    ]);

    $ticketStandard = EventTicketType::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'name' => 'General Delegate',
    ]);

    // Attendee 1: VIP and checked in -> Should MATCH
    $reg1 = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'ticket_type_id' => $ticketVIP->id,
        'first_name' => 'Dr. Kofi',
        'last_name' => 'Annan',
        'email' => 'kofi@un.org',
        'status' => EventRegistration::STATUS_CHECKED_IN,
    ]);

    // Attendee 2: VIP but only confirmed -> Should NOT MATCH
    $reg2 = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'ticket_type_id' => $ticketVIP->id,
        'first_name' => 'Dr. Ama',
        'last_name' => 'Atta',
        'email' => 'ama@accra.org',
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    // Attendee 3: Standard and checked in -> Should NOT MATCH
    $reg3 = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'ticket_type_id' => $ticketStandard->id,
        'first_name' => 'Sam',
        'last_name' => 'Taylor',
        'email' => 'sam@standard.org',
        'status' => EventRegistration::STATUS_CHECKED_IN,
    ]);

    $group = EventParticipantGroup::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'name' => 'Checked In VIPs',
        'slug' => 'checked-in-vips',
        'type' => 'dynamic',
        'criteria' => [
            ['type' => 'ticket_type', 'operator' => 'equals', 'value' => $ticketVIP->id],
            ['type' => 'status', 'operator' => 'equals', 'value' => 'checked_in'],
        ],
        'member_count' => 0,
    ]);

    // Trigger sync endpoint
    $syncResponse = $this->actingAs($user)
        ->postJson("http://{$host}/events/{$event->id}/participant-groups/{$group->id}/sync", [], ['HTTP_HOST' => $host]);

    $syncResponse->assertOk();
    $syncResponse->assertJsonPath('stats.matched_count', 1);
    $syncResponse->assertJsonPath('stats.total_count', 1);

    expect($group->members()->count())->toBe(1);
    expect($group->members()->first()->registration_id)->toBe($reg1->id);
});

test('stratification engine filters participants based on dynamic form questionnaire responses', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'slug' => 'neuro-congress-2026',
    ]);

    // Create a dynamic form
    $form = EventDynamicForm::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'title' => 'Clinical Subspecialty Survey',
        'slug' => 'subspecialty-survey',
        'type' => 'survey',
        'is_active' => true,
        'schema' => [
            ['key' => 'subspecialty', 'label' => 'Subspecialty', 'type' => 'select', 'options' => ['Pediatric Neurosurgery', 'Spine', 'Vascular']],
        ],
    ]);

    $reg1 = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'first_name' => 'Dr. Jessica',
        'last_name' => 'Taylor',
        'email' => 'jessica@pedneuro.org',
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    $reg2 = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'first_name' => 'Dr. Robert',
        'last_name' => 'Chen',
        'email' => 'robert@spine.org',
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    // Submit form response for Jessica
    $form->submissions()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'registration_id' => $reg1->id,
        'respondent_email' => $reg1->email,
        'answers' => ['subspecialty' => 'Pediatric Neurosurgery'],
        'submitted_at' => now(),
    ]);

    // Submit form response for Robert
    $form->submissions()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'registration_id' => $reg2->id,
        'respondent_email' => $reg2->email,
        'answers' => ['subspecialty' => 'Spine'],
        'submitted_at' => now(),
    ]);

    // Create dynamic cohort for Pediatric Neurosurgery
    $group = EventParticipantGroup::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'name' => 'Pediatric Neuro Cohort',
        'slug' => 'pediatric-neuro-cohort',
        'type' => 'dynamic',
        'criteria' => [
            [
                'type' => 'form_answer',
                'form_id' => $form->id,
                'field_key' => 'subspecialty',
                'operator' => 'equals',
                'value' => 'Pediatric Neurosurgery',
            ],
        ],
        'member_count' => 0,
    ]);

    $this->actingAs($user)
        ->postJson("http://{$host}/events/{$event->id}/participant-groups/{$group->id}/sync", [], ['HTTP_HOST' => $host])
        ->assertOk();

    expect($group->members()->count())->toBe(1);
    expect($group->members()->first()->registration_id)->toBe($reg1->id);
});

test('host can manually pin and remove members from a participant cohort', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'slug' => 'manual-cohort-2026',
    ]);

    $reg = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'first_name' => 'Prof. Kwame',
        'last_name' => 'Nkrumah',
        'email' => 'kwame@flagstaff.gov',
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    $group = EventParticipantGroup::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'name' => 'Executive Committee Delegates',
        'slug' => 'exec-committee',
        'type' => 'manual',
        'criteria' => [],
        'member_count' => 0,
    ]);

    // Manually add member
    $addResponse = $this->actingAs($user)
        ->postJson("http://{$host}/events/{$event->id}/participant-groups/{$group->id}/members", [
            'registration_id' => $reg->id,
        ], ['HTTP_HOST' => $host]);

    $addResponse->assertOk();
    expect($group->members()->count())->toBe(1);
    expect($group->members()->first()->is_manual)->toBeTrue();

    // Remove member
    $removeResponse = $this->actingAs($user)
        ->deleteJson("http://{$host}/events/{$event->id}/participant-groups/{$group->id}/members/{$reg->id}", [], ['HTTP_HOST' => $host]);

    $removeResponse->assertOk();
    expect($group->members()->count())->toBe(0);
});

test('host can export cohort roster to CSV', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'slug' => 'export-cohort-2026',
    ]);

    $reg = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'first_name' => 'Nana',
        'last_name' => 'Yaa',
        'email' => 'yaa@asante.org',
        'ticket_code' => 'TKT-YAA-99',
        'status' => EventRegistration::STATUS_CHECKED_IN,
    ]);

    $group = EventParticipantGroup::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'name' => 'Keynote VIPs',
        'slug' => 'keynote-vips',
        'type' => 'manual',
        'member_count' => 1,
    ]);

    $group->members()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'registration_id' => $reg->id,
        'is_manual' => true,
        'matched_at' => now(),
    ]);

    $csvResponse = $this->actingAs($user)
        ->get("http://{$host}/events/{$event->id}/participant-groups/{$group->id}/export", ['HTTP_HOST' => $host]);

    $csvResponse->assertOk();
    $content = $csvResponse->streamedContent();
    expect($content)->toContain('yaa@asante.org');
    expect($content)->toContain('TKT-YAA-99');
    expect($content)->toContain('Manual Pin');
});
