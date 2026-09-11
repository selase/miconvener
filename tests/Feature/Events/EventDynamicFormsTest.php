<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventDynamicForm;
use App\Models\EventRegistration;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('host can view dynamic forms dashboard and create institutional survey', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'slug' => 'global-pediatrics-2026',
    ]);

    // Check index endpoint
    $response = $this->actingAs($user)
        ->getJson("http://{$host}/events/{$event->id}/dynamic-forms", ['HTTP_HOST' => $host]);

    $response->assertOk();
    $response->assertJsonStructure(['forms', 'stats']);

    // Create a new general-purpose evaluation form
    $createResponse = $this->actingAs($user)
        ->postJson("http://{$host}/events/{$event->id}/dynamic-forms", [
            'title' => 'Plenary Session & CME Assessment',
            'description' => 'Please evaluate the clinical relevance and presentation quality of keynote sessions.',
            'type' => 'cme_evaluation',
            'requires_check_in' => true,
            'is_active' => true,
            'schema' => [
                [
                    'key' => 'speaker_clarity',
                    'label' => 'Speaker Presentation & Scientific Clarity',
                    'type' => 'rating',
                    'required' => true,
                ],
                [
                    'key' => 'practice_impact',
                    'label' => 'Will this session alter your clinical practice?',
                    'type' => 'radio',
                    'options' => ['Definitely', 'Somewhat', 'No change'],
                    'required' => true,
                ],
                [
                    'key' => 'additional_comments',
                    'label' => 'Qualitative Feedback',
                    'type' => 'textarea',
                    'required' => false,
                ],
            ],
        ], ['HTTP_HOST' => $host]);

    $createResponse->assertCreated();
    $createResponse->assertJsonPath('form.title', 'Plenary Session & CME Assessment');

    $form = $event->dynamicForms()->where('slug', 'plenary-session-cme-assessment')->firstOrFail();
    expect($form->type)->toBe('cme_evaluation')
        ->and($form->requires_check_in)->toBeTrue()
        ->and(count($form->schema))->toBe(3);
});

test('host can update, duplicate, and toggle status of dynamic form', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'slug' => 'surgery-summit-2026',
    ]);

    $form = EventDynamicForm::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'title' => 'Catering & Accessibility Preferences',
        'slug' => 'catering-accessibility',
        'type' => 'dietary_accessibility',
        'is_active' => true,
        'schema' => [
            ['key' => 'diet', 'label' => 'Dietary Requirement', 'type' => 'select', 'options' => ['Vegetarian', 'Halal', 'Kosher', 'None']],
        ],
    ]);

    // Update form
    $updateResponse = $this->actingAs($user)
        ->putJson("http://{$host}/events/{$event->id}/dynamic-forms/{$form->id}", [
            'title' => 'Updated Dietary Preferences',
            'is_active' => false,
            'schema' => $form->schema,
        ], ['HTTP_HOST' => $host]);

    $updateResponse->assertOk();
    expect($form->fresh()->title)->toBe('Updated Dietary Preferences')
        ->and($form->fresh()->is_active)->toBeFalse();

    // Duplicate form
    $dupResponse = $this->actingAs($user)
        ->postJson("http://{$host}/events/{$event->id}/dynamic-forms/{$form->id}/duplicate", [], ['HTTP_HOST' => $host]);

    $dupResponse->assertCreated();
    expect($event->dynamicForms()->count())->toBe(2);
});

test('public attendees can view and submit responses to dynamic form with validation', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'slug' => 'cardio-symposium-2026',
    ]);

    $form = EventDynamicForm::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'title' => 'Workshop Registration & Skill Assessment',
        'slug' => 'workshop-assessment',
        'type' => 'workshop_feedback',
        'is_active' => true,
        'requires_check_in' => false,
        'schema' => [
            ['key' => 'experience_level', 'label' => 'Experience Level', 'type' => 'select', 'options' => ['Junior', 'Senior', 'Consultant'], 'required' => true],
            ['key' => 'preferred_topics', 'label' => 'Topics of Interest', 'type' => 'multiselect', 'options' => ['Echo', 'ECG', 'Angiography'], 'required' => true],
            ['key' => 'overall_rating', 'label' => 'Readiness Rating', 'type' => 'rating', 'required' => true],
        ],
    ]);

    // Public view
    $viewResponse = $this->get("http://{$host}/e/{$event->slug}/forms/{$form->slug}", ['HTTP_HOST' => $host]);
    $viewResponse->assertOk();

    // Failed submission due to missing required field
    $failedSubmit = $this->postJson("http://{$host}/e/{$event->slug}/forms/{$form->slug}/submit", [
        'respondent_name' => 'Dr. Kwame Mensah',
        'respondent_email' => 'kwame@ghanahealth.org',
        'answers' => [
            'experience_level' => 'Senior',
            // missing preferred_topics and overall_rating
        ],
    ], ['HTTP_HOST' => $host]);

    $failedSubmit->assertStatus(422);
    $failedSubmit->assertJsonValidationErrors(['answers.preferred_topics', 'answers.overall_rating']);

    // Successful submission
    $successSubmit = $this->postJson("http://{$host}/e/{$event->slug}/forms/{$form->slug}/submit", [
        'respondent_name' => 'Dr. Kwame Mensah',
        'respondent_email' => 'kwame@ghanahealth.org',
        'answers' => [
            'experience_level' => 'Senior',
            'preferred_topics' => ['Echo', 'ECG'],
            'overall_rating' => 5,
        ],
    ], ['HTTP_HOST' => $host]);

    $successSubmit->assertCreated();
    expect($form->submissions()->count())->toBe(1);

    $sub = $form->submissions()->first();
    expect($sub->respondent_email)->toBe('kwame@ghanahealth.org')
        ->and($sub->answers['experience_level'])->toBe('Senior')
        ->and($sub->answers['overall_rating'])->toBe(5);
});

test('check-in restricted form blocks non-checked-in attendees', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'slug' => 'oncology-summit-2026',
    ]);

    $form = EventDynamicForm::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'title' => 'Official Accredited CME Feedback',
        'slug' => 'cme-feedback',
        'type' => 'cme_evaluation',
        'is_active' => true,
        'requires_check_in' => true,
        'schema' => [
            ['key' => 'quality', 'label' => 'Session Quality', 'type' => 'rating', 'required' => true],
        ],
    ]);

    // Registration confirmed but NOT checked in
    $reg = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'first_name' => 'Sara',
        'last_name' => 'Osei',
        'email' => 'sara@clinic.com',
        'ticket_code' => 'TKT-SARA-01',
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    // Should be rejected with 403
    $rejected = $this->postJson("http://{$host}/e/{$event->slug}/forms/{$form->slug}/submit", [
        'ticket_code' => 'TKT-SARA-01',
        'answers' => ['quality' => 5],
    ], ['HTTP_HOST' => $host]);

    $rejected->assertStatus(403);
    $rejected->assertJsonPath('message', 'This evaluation is restricted to checked-in attendees of this conference.');

    // Now check in the attendee
    $reg->update(['status' => EventRegistration::STATUS_CHECKED_IN]);

    $accepted = $this->postJson("http://{$host}/e/{$event->slug}/forms/{$form->slug}/submit", [
        'ticket_code' => 'TKT-SARA-01',
        'answers' => ['quality' => 5],
    ], ['HTTP_HOST' => $host]);

    $accepted->assertCreated();
    expect($form->submissions()->count())->toBe(1);
});

test('host can view submissions and export CSV report', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'slug' => 'export-test-2026',
    ]);

    $form = EventDynamicForm::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'title' => 'Post-Conference Survey',
        'slug' => 'post-conf-survey',
        'type' => 'post_event_survey',
        'is_active' => true,
        'schema' => [
            ['key' => 'recommend', 'label' => 'Would you recommend this conference?', 'type' => 'radio', 'options' => ['Yes', 'No']],
        ],
    ]);

    $form->submissions()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'respondent_name' => 'Dr. John Doe',
        'respondent_email' => 'john@test.com',
        'answers' => ['recommend' => 'Yes'],
        'submitted_at' => now(),
    ]);

    // View submissions roster
    $rosterResponse = $this->actingAs($user)
        ->getJson("http://{$host}/events/{$event->id}/dynamic-forms/{$form->id}/submissions", ['HTTP_HOST' => $host]);

    $rosterResponse->assertOk();
    $rosterResponse->assertJsonPath('submissions.total', 1);

    // Export CSV
    $csvResponse = $this->actingAs($user)
        ->get("http://{$host}/events/{$event->id}/dynamic-forms/{$form->id}/export", ['HTTP_HOST' => $host]);

    $csvResponse->assertOk();
    $content = $csvResponse->streamedContent();
    expect($content)->toContain('john@test.com');
    expect($content)->toContain('Dr. John Doe');
    expect($content)->toContain('Yes');
});
