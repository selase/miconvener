<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventFormField;
use App\Models\EventRegistration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('tenant can update event registration settings', function () {
    [$tenant, $user] = eventHost('medconf');
    $host = eventSubdomainHost('medconf');

    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    $response = $this->actingAs($user)->put("http://{$host}/events/{$event->id}/registration-settings", [
        'phone' => 'required',
        'dietary_requirements' => 'optional',
        'accessibility_needs' => 'hidden',
    ], ['HTTP_HOST' => $host]);

    $response->assertSuccessful();

    $event->refresh();
    $effective = $event->effectiveRegistrationSettings();
    expect($effective['phone'])->toBe('required');
    expect($effective['dietary_requirements'])->toBe('optional');
    expect($effective['accessibility_needs'])->toBe('hidden');
    expect($effective['require_phone'])->toBeTrue();
    expect($effective['collect_accessibility'])->toBeFalse();
});

test('tenant can manage custom form fields with options and conditional logic', function () {
    [$tenant, $user] = eventHost('medconf');
    $host = eventSubdomainHost('medconf');

    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    // 1. Create parent attendance mode field
    $createResponse = $this->actingAs($user)->post("http://{$host}/events/{$event->id}/form-fields", [
        'label' => 'Attendance Mode',
        'field_key' => 'attendance_mode',
        'field_type' => 'radio',
        'is_required' => true,
        'options' => [
            ['label' => 'In-person', 'value' => 'in_person', 'price' => 0],
            ['label' => 'Virtual', 'value' => 'virtual', 'price' => 5000, 'is_override' => true],
        ],
    ], ['HTTP_HOST' => $host]);

    $createResponse->assertSuccessful();
    expect(EventFormField::where('event_id', $event->id)->where('field_key', 'attendance_mode')->exists())->toBeTrue();

    // 2. Create conditional profession field depending on in-person attendance
    $createChildResponse = $this->actingAs($user)->post("http://{$host}/events/{$event->id}/form-fields", [
        'label' => 'Profession',
        'field_key' => 'profession',
        'field_type' => 'select',
        'is_required' => true,
        'conditional_logic' => [
            'depends_on_field' => 'attendance_mode',
            'operator' => 'equals',
            'value' => 'in_person',
        ],
        'options' => [
            ['label' => 'Doctor', 'value' => 'doctor', 'price' => 10000, 'is_override' => true],
            ['label' => 'Nurse', 'value' => 'nurse', 'price' => 8000, 'is_override' => true],
        ],
    ], ['HTTP_HOST' => $host]);

    $createChildResponse->assertSuccessful();

    $childField = EventFormField::where('event_id', $event->id)->where('field_key', 'profession')->first();
    expect($childField)->not->toBeNull();
    expect($childField->conditional_logic['depends_on_field'])->toBe('attendance_mode');

    // 3. Update field
    $updateResponse = $this->actingAs($user)->put("http://{$host}/events/{$event->id}/form-fields/{$childField->id}", [
        'label' => 'Medical Specialty or Role',
        'field_key' => 'profession',
        'field_type' => 'select',
        'is_required' => true,
        'conditional_logic' => [
            'depends_on_field' => 'attendance_mode',
            'operator' => 'equals',
            'value' => 'in_person',
        ],
        'options' => [
            ['label' => 'Doctor', 'value' => 'doctor', 'price' => 10000, 'is_override' => true],
            ['label' => 'Nurse', 'value' => 'nurse', 'price' => 8000, 'is_override' => true],
            ['label' => 'Student', 'value' => 'student', 'price' => 3000, 'is_override' => true],
        ],
    ], ['HTTP_HOST' => $host]);

    $updateResponse->assertSuccessful();
    $childField->refresh();
    expect($childField->label)->toBe('Medical Specialty or Role');
    expect(count($childField->options))->toBe(3);

    // 4. Delete field
    $deleteResponse = $this->actingAs($user)->delete("http://{$host}/events/{$event->id}/form-fields/{$childField->id}", [], ['HTTP_HOST' => $host]);
    $deleteResponse->assertSuccessful();
    expect(EventFormField::where('id', $childField->id)->exists())->toBeFalse();
});

test('public registration requires core identity fields: title, first_name, last_name, email', function () {
    Mail::fake();
    [$tenant] = eventHost('medconf');
    $host = eventSubdomainHost('medconf');

    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    $response = $this->post("http://{$host}/e/{$event->slug}/register", [
        // Missing title, first_name, last_name, email
    ], ['HTTP_HOST' => $host]);

    $response->assertSessionHasErrors(['title', 'first_name', 'last_name', 'email']);
});

test('conditional custom fields are enforced only when conditions match', function () {
    Mail::fake();
    [$tenant] = eventHost('medconf');
    $host = eventSubdomainHost('medconf');

    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    // Parent field
    EventFormField::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'label' => 'Attendance Mode',
        'field_key' => 'attendance_mode',
        'field_type' => 'select',
        'is_required' => true,
        'sort_order' => 1,
        'options' => [
            ['label' => 'In-person', 'value' => 'in_person'],
            ['label' => 'Virtual', 'value' => 'virtual'],
        ],
    ]);

    // Child field conditioned on attendance_mode = in_person
    EventFormField::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'label' => 'License Number',
        'field_key' => 'license_number',
        'field_type' => 'text',
        'is_required' => true,
        'sort_order' => 2,
        'conditional_logic' => [
            'depends_on_field' => 'attendance_mode',
            'operator' => 'equals',
            'value' => 'in_person',
        ],
    ]);

    // Case 1: Attendee selects "in_person" but omits "license_number" -> Validation error
    $responseFailed = $this->post("http://{$host}/e/{$event->slug}/register", [
        'title' => 'Dr',
        'first_name' => 'Kofi',
        'last_name' => 'Mensah',
        'email' => 'kofi@example.com',
        'form_answers' => [
            'attendance_mode' => 'in_person',
        ],
    ], ['HTTP_HOST' => $host]);

    $responseFailed->assertSessionHasErrors(['form_answers.license_number']);

    // Case 2: Attendee selects "virtual" -> license_number is not required and passes!
    $responseSuccess = $this->post("http://{$host}/e/{$event->slug}/register", [
        'title' => 'Dr',
        'first_name' => 'Kofi',
        'last_name' => 'Mensah',
        'email' => 'kofi.virtual@example.com',
        'form_answers' => [
            'attendance_mode' => 'virtual',
        ],
    ], ['HTTP_HOST' => $host]);

    $responseSuccess->assertRedirect();
    $reg = EventRegistration::where('email', 'kofi.virtual@example.com')->first();
    expect($reg)->not->toBeNull();
    expect($reg->title)->toBe('Dr');
    expect($reg->first_name)->toBe('Kofi');
    expect($reg->last_name)->toBe('Mensah');
    expect($reg->full_name)->toBe('Dr Kofi Mensah');
    expect($reg->form_answers)->toBe(['attendance_mode' => 'virtual']);
});

test('dynamic option pricing calculates the exact price and strips inactive conditional pricing', function () {
    Mail::fake();
    [$tenant] = eventHost('medconf');
    $host = eventSubdomainHost('medconf');

    // Base free event
    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'ticket_price' => 0,
        'currency' => 'GHS',
    ]);

    // Attendance mode
    EventFormField::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'label' => 'Attendance Mode',
        'field_key' => 'attendance_mode',
        'field_type' => 'radio',
        'is_required' => true,
        'sort_order' => 1,
        'options' => [
            ['label' => 'In-person', 'value' => 'in_person', 'price' => 0],
            ['label' => 'Virtual', 'value' => 'virtual', 'price' => 5000, 'is_override' => true],
        ],
    ]);

    // Role (conditioned on in-person)
    EventFormField::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'label' => 'Role',
        'field_key' => 'role',
        'field_type' => 'select',
        'is_required' => true,
        'sort_order' => 2,
        'conditional_logic' => [
            'depends_on_field' => 'attendance_mode',
            'operator' => 'equals',
            'value' => 'in_person',
        ],
        'options' => [
            ['label' => 'Doctor', 'value' => 'doctor', 'price' => 10000, 'is_override' => true],
            ['label' => 'Nurse', 'value' => 'nurse', 'price' => 8000, 'is_override' => true],
        ],
    ]);

    // Test In-person + Doctor = 10000 pesewas (100 GHS)
    $respDoctor = $this->post("http://{$host}/e/{$event->slug}/register", [
        'title' => 'Dr',
        'first_name' => 'Kwame',
        'last_name' => 'Nkrumah',
        'email' => 'kwame.doc@example.com',
        'form_answers' => [
            'attendance_mode' => 'in_person',
            'role' => 'doctor',
        ],
    ], ['HTTP_HOST' => $host]);

    $respDoctor->assertRedirect();
    $regDoctor = EventRegistration::where('email', 'kwame.doc@example.com')->first();
    expect($regDoctor->amount)->toBe(10000);
    expect($regDoctor->status)->toBe(EventRegistration::STATUS_PENDING_PAYMENT);

    // Test In-person + Nurse = 8000 pesewas (80 GHS)
    $respNurse = $this->post("http://{$host}/e/{$event->slug}/register", [
        'title' => 'Ms',
        'first_name' => 'Akosua',
        'last_name' => 'Darko',
        'email' => 'akosua.nurse@example.com',
        'form_answers' => [
            'attendance_mode' => 'in_person',
            'role' => 'nurse',
        ],
    ], ['HTTP_HOST' => $host]);

    $respNurse->assertRedirect();
    $regNurse = EventRegistration::where('email', 'akosua.nurse@example.com')->first();
    expect($regNurse->amount)->toBe(8000);

    // Test Virtual = 5000 pesewas (50 GHS)
    $respVirtual = $this->post("http://{$host}/e/{$event->slug}/register", [
        'title' => 'Mr',
        'first_name' => 'Yaw',
        'last_name' => 'Osei',
        'email' => 'yaw.virtual@example.com',
        'form_answers' => [
            'attendance_mode' => 'virtual',
            'role' => 'doctor', // Sent stale role from client, should be stripped because condition is not met!
        ],
    ], ['HTTP_HOST' => $host]);

    $respVirtual->assertRedirect();
    $regVirtual = EventRegistration::where('email', 'yaw.virtual@example.com')->first();
    expect($regVirtual->amount)->toBe(5000); // 50 GHS, not 100 GHS
    expect($regVirtual->form_answers)->toBe(['attendance_mode' => 'virtual']); // 'role' was stripped!
});

test('registration export includes title, first_name, last_name, and custom form answers', function () {
    [$tenant, $user] = eventHost('medconf');
    $host = eventSubdomainHost('medconf');

    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    EventFormField::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'label' => 'Hospital Affiliation',
        'field_key' => 'hospital_affiliation',
        'field_type' => 'text',
        'is_required' => false,
        'sort_order' => 1,
    ]);

    EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'title' => 'Prof',
        'first_name' => 'Ama',
        'last_name' => 'Aidoo',
        'full_name' => 'Prof Ama Aidoo',
        'email' => 'ama@example.com',
        'form_answers' => ['hospital_affiliation' => 'Korle Bu Teaching Hospital'],
    ]);

    // 1. Default export automatically includes custom form fields
    $defaultResponse = $this->actingAs($user)->get("http://{$host}/events/{$event->id}/reports/registrations", ['HTTP_HOST' => $host]);
    $defaultResponse->assertSuccessful();
    $defaultContent = $defaultResponse->streamedContent();
    expect($defaultContent)->toContain('Hospital Affiliation');
    expect($defaultContent)->toContain('Korle Bu Teaching Hospital');
    expect($defaultContent)->toContain('Prof Ama Aidoo');

    // 2. Custom column selection including explicit Title, First name, Last name, and custom fields
    $customResponse = $this->actingAs($user)->get(
        "http://{$host}/events/{$event->id}/reports/registrations?columns[]=title&columns[]=first_name&columns[]=last_name&columns[]=form_hospital_affiliation",
        ['HTTP_HOST' => $host]
    );
    $customResponse->assertSuccessful();
    $customContent = $customResponse->streamedContent();
    expect($customContent)->toContain('Title');
    expect($customContent)->toContain('First name');
    expect($customContent)->toContain('Last name');
    expect($customContent)->toContain('Hospital Affiliation');
    expect($customContent)->toContain('Prof');
    expect($customContent)->toContain('Ama');
    expect($customContent)->toContain('Aidoo');
    expect($customContent)->toContain('Korle Bu Teaching Hospital');
});

/**
 * @param  array<string, mixed>  $attributes
 */
function bootcampField(Event $event, array $attributes): EventFormField
{
    static $sort = 0;

    return EventFormField::create([
        'tenant_id' => $event->tenant_id,
        'event_id' => $event->id,
        'is_required' => false,
        'sort_order' => ++$sort,
        ...$attributes,
    ]);
}

/**
 * @param  array<string, mixed>  $answers
 */
function registerWithAnswers(Event $event, string $host, string $email, array $answers): \Illuminate\Testing\TestResponse
{
    return test()->post("http://{$host}/e/{$event->slug}/register", [
        'title' => 'Ms',
        'first_name' => 'Esi',
        'last_name' => 'Owusu',
        'email' => $email,
        'form_answers' => $answers,
    ], ['HTTP_HOST' => $host]);
}

test('builder saves length limits for text questions and clears them for other types', function () {
    [$tenant, $user] = eventHost('medconf');
    $host = eventSubdomainHost('medconf');
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    $this->actingAs($user)->post("http://{$host}/events/{$event->id}/form-fields", [
        'label' => 'Why should you be invited?',
        'field_key' => 'motivation',
        'field_type' => 'textarea',
        'is_required' => true,
        'min_length' => 100,
        'max_length' => 2000,
    ], ['HTTP_HOST' => $host])->assertSuccessful();

    $this->actingAs($user)->post("http://{$host}/events/{$event->id}/form-fields", [
        'label' => 'Do you have a laptop?',
        'field_key' => 'has_computer',
        'field_type' => 'radio',
        'is_required' => true,
        'min_length' => 10,
        'options' => [['label' => 'Yes', 'value' => '1'], ['label' => 'No', 'value' => '0']],
    ], ['HTTP_HOST' => $host])->assertSuccessful();

    $motivation = EventFormField::where('event_id', $event->id)->where('field_key', 'motivation')->sole();
    expect($motivation->min_length)->toBe(100)->and($motivation->max_length)->toBe(2000);

    $laptop = EventFormField::where('event_id', $event->id)->where('field_key', 'has_computer')->sole();
    expect($laptop->min_length)->toBeNull()->and($laptop->max_length)->toBeNull();
});

test('builder rejects a maximum shorter than the minimum and choice questions without choices', function () {
    [$tenant, $user] = eventHost('medconf');
    $host = eventSubdomainHost('medconf');
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/form-fields", [
        'label' => 'Goals',
        'field_type' => 'textarea',
        'is_required' => true,
        'min_length' => 50,
        'max_length' => 20,
    ], ['HTTP_HOST' => $host])->assertJsonValidationErrors('max_length');

    $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/form-fields", [
        'label' => 'School',
        'field_type' => 'select',
        'is_required' => true,
        'options' => [],
    ], ['HTTP_HOST' => $host])->assertJsonValidationErrors('options');
});

test('a section heading is never required, carries no choices and is not exported', function () {
    [$tenant, $user] = eventHost('medconf');
    $host = eventSubdomainHost('medconf');
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    $this->actingAs($user)->post("http://{$host}/events/{$event->id}/form-fields", [
        'label' => 'About you',
        'field_key' => 'about_you',
        'field_type' => 'heading',
        'is_required' => true,
        'options' => [['label' => 'Stray', 'value' => 'stray']],
    ], ['HTTP_HOST' => $host])->assertSuccessful();

    $heading = EventFormField::where('event_id', $event->id)->where('field_key', 'about_you')->sole();
    expect($heading->is_required)->toBeFalse()->and($heading->options)->toBeNull();

    $this->actingAs($user)->getJson("http://{$host}/events/{$event->id}/data/reports", ['HTTP_HOST' => $host])
        ->assertSuccessful()
        ->assertJsonMissing(['key' => 'form_about_you']);

    $csv = $this->actingAs($user)->get("http://{$host}/events/{$event->id}/reports/registrations", ['HTTP_HOST' => $host])->streamedContent();

    expect($csv)->not->toContain('About you');
});

test('public registration enforces minimum and maximum answer lengths', function () {
    Mail::fake();
    [$tenant] = eventHost('medconf');
    $host = eventSubdomainHost('medconf');
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    bootcampField($event, [
        'label' => 'Why should you be invited?',
        'field_key' => 'motivation',
        'field_type' => 'textarea',
        'is_required' => true,
        'min_length' => 100,
    ]);
    bootcampField($event, [
        'label' => 'Programme of study',
        'field_key' => 'course_of_study',
        'field_type' => 'text',
        'is_required' => true,
    ]);

    registerWithAnswers($event, $host, 'short@example.com', [
        'motivation' => str_repeat('a', 99),
        'course_of_study' => str_repeat('b', 256),
    ])->assertSessionHasErrors([
        'form_answers.motivation' => 'The Why should you be invited? must be at least 100 characters.',
        'form_answers.course_of_study' => 'The Programme of study must not be longer than 255 characters.',
    ]);

    registerWithAnswers($event, $host, 'padded@example.com', [
        'motivation' => '   '.str_repeat('a', 98).'   ',
        'course_of_study' => 'Computer Science',
    ])->assertSessionHasErrors('form_answers.motivation');

    registerWithAnswers($event, $host, 'ok@example.com', [
        'motivation' => str_repeat('a', 100),
        'course_of_study' => '  Computer Science  ',
    ])->assertSessionHasNoErrors();

    expect(EventRegistration::where('email', 'ok@example.com')->sole()->form_answers)->toBe([
        'motivation' => str_repeat('a', 100),
        'course_of_study' => 'Computer Science',
    ]);
});

test('public registration checks email questions and ignores section headings', function () {
    Mail::fake();
    [$tenant] = eventHost('medconf');
    $host = eventSubdomainHost('medconf');
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    bootcampField($event, ['label' => 'About you', 'field_key' => 'about_you', 'field_type' => 'heading']);
    bootcampField($event, ['label' => 'School email', 'field_key' => 'school_email', 'field_type' => 'email']);

    registerWithAnswers($event, $host, 'bad@example.com', ['school_email' => 'not-an-email'])
        ->assertSessionHasErrors(['form_answers.school_email' => 'The School email must be a valid email address.']);

    registerWithAnswers($event, $host, 'good@example.com', [
        'about_you' => 'smuggled',
        'school_email' => 'esi@st.ug.edu.gh',
    ])->assertSessionHasNoErrors();

    expect(EventRegistration::where('email', 'good@example.com')->sole()->form_answers)
        ->toBe(['school_email' => 'esi@st.ug.edu.gh']);
});

test('a required checkbox must be ticked and is stored as true', function () {
    Mail::fake();
    [$tenant] = eventHost('medconf');
    $host = eventSubdomainHost('medconf');
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    bootcampField($event, [
        'label' => 'I consent to the use of this information for programme selection.',
        'field_key' => 'consent',
        'field_type' => 'checkbox',
        'is_required' => true,
    ]);

    registerWithAnswers($event, $host, 'unticked@example.com', ['consent' => false])
        ->assertSessionHasErrors('form_answers.consent');

    registerWithAnswers($event, $host, 'ticked@example.com', ['consent' => '1'])
        ->assertSessionHasNoErrors();

    expect(EventRegistration::where('email', 'ticked@example.com')->sole()->form_answers)->toBe(['consent' => true]);
});

test('public event page exposes the effective length limits for each question', function () {
    [$tenant] = eventHost('medconf');
    $host = eventSubdomainHost('medconf');
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    bootcampField($event, ['label' => 'Goals', 'field_key' => 'goals', 'field_type' => 'textarea', 'min_length' => 30]);
    bootcampField($event, ['label' => 'Level', 'field_key' => 'level', 'field_type' => 'select', 'options' => [['label' => 'Level 100', 'value' => 'level-100']]]);

    $this->get("http://{$host}/e/{$event->slug}", ['HTTP_HOST' => $host])
        ->assertInertia(fn ($page) => $page
            ->where('event.form_fields.0.min_length', 30)
            ->where('event.form_fields.0.max_length', null)
            ->where('event.form_fields.0.max_characters', 5000)
            ->where('event.form_fields.0.length_unit', 'characters')
            ->where('event.form_fields.1.min_length', null)
            ->where('event.form_fields.1.max_characters', null)
            ->where('event.requires_approval', false));
});

test('registration export shows option labels instead of stored values', function () {
    [$tenant, $user] = eventHost('medconf');
    $host = eventSubdomainHost('medconf');
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    bootcampField($event, [
        'label' => 'Current level',
        'field_key' => 'academic_level',
        'field_type' => 'select',
        'options' => [['label' => 'Level 400 / Final year', 'value' => 'level-400']],
    ]);

    EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'form_answers' => ['academic_level' => 'level-400'],
    ]);

    $csv = $this->actingAs($user)->get("http://{$host}/events/{$event->id}/reports/registrations", ['HTTP_HOST' => $host])->streamedContent();

    expect($csv)->toContain('Level 400 / Final year')->not->toContain('level-400');
});

test('an answered condition counts a zero choice as an answer', function () {
    Mail::fake();
    [$tenant] = eventHost('medconf');
    $host = eventSubdomainHost('medconf');
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    bootcampField($event, [
        'label' => 'Do you have a final-year project?',
        'field_key' => 'has_final_year_project',
        'field_type' => 'radio',
        'options' => [['label' => 'Yes', 'value' => '1'], ['label' => 'No', 'value' => '0']],
    ]);
    bootcampField($event, [
        'label' => 'Tell us why',
        'field_key' => 'project_reason',
        'field_type' => 'text',
        'is_required' => true,
        'conditional_logic' => ['depends_on_field' => 'has_final_year_project', 'operator' => 'is_not_empty'],
    ]);

    registerWithAnswers($event, $host, 'zero@example.com', ['has_final_year_project' => '0'])
        ->assertSessionHasErrors('form_answers.project_reason');

    registerWithAnswers($event, $host, 'blank@example.com', [])
        ->assertSessionHasNoErrors();
});

test('a long answer can be counted in words and still has a character cap', function () {
    Mail::fake();
    [$tenant, $user] = eventHost('medconf');
    $host = eventSubdomainHost('medconf');
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    $this->actingAs($user)->post("http://{$host}/events/{$event->id}/form-fields", [
        'label' => 'Why should you be invited?',
        'field_key' => 'motivation',
        'field_type' => 'textarea',
        'is_required' => true,
        'min_length' => 150,
        'max_length' => 300,
        'length_unit' => 'words',
    ], ['HTTP_HOST' => $host])->assertSuccessful();

    expect(EventFormField::where('event_id', $event->id)->sole()->length_unit)->toBe('words');

    registerWithAnswers($event, $host, 'short@example.com', ['motivation' => mb_trim(str_repeat('word ', 149))])
        ->assertSessionHasErrors(['form_answers.motivation' => 'The Why should you be invited? must be at least 150 words.']);

    registerWithAnswers($event, $host, 'long@example.com', ['motivation' => mb_trim(str_repeat('word ', 301))])
        ->assertSessionHasErrors(['form_answers.motivation' => 'The Why should you be invited? must not be longer than 300 words.']);

    registerWithAnswers($event, $host, 'huge@example.com', ['motivation' => mb_trim(str_repeat(str_repeat('a', 40).' ', 160))])
        ->assertSessionHasErrors(['form_answers.motivation' => 'The Why should you be invited? must not be longer than 5000 characters.']);

    registerWithAnswers($event, $host, 'ok@example.com', ['motivation' => mb_trim(str_repeat("word\n", 150))])
        ->assertSessionHasNoErrors();
});

test('word counting is only offered on long answers and is capped', function () {
    [$tenant, $user] = eventHost('medconf');
    $host = eventSubdomainHost('medconf');
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    $this->actingAs($user)->post("http://{$host}/events/{$event->id}/form-fields", [
        'label' => 'Course',
        'field_key' => 'course',
        'field_type' => 'text',
        'is_required' => false,
        'length_unit' => 'words',
    ], ['HTTP_HOST' => $host])->assertSuccessful();

    expect(EventFormField::where('field_key', 'course')->sole()->length_unit)->toBe('characters');

    $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/form-fields", [
        'label' => 'Essay',
        'field_type' => 'textarea',
        'is_required' => false,
        'min_length' => 900,
        'length_unit' => 'words',
    ], ['HTTP_HOST' => $host])->assertJsonValidationErrors('min_length');
});

test('a reviewer sees an application in its sections, with labels, hidden and blank questions left out and removed questions kept', function () {
    [$tenant, $user] = eventHost('medconf');
    $host = eventSubdomainHost('medconf');
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id, 'requires_approval' => true]);

    bootcampField($event, ['label' => 'About you', 'field_key' => 'about_you', 'field_type' => 'heading']);
    bootcampField($event, ['label' => 'Current level', 'field_key' => 'academic_level', 'field_type' => 'select', 'options' => [['label' => 'Level 400 / Final year', 'value' => 'level-400']]]);
    bootcampField($event, ['label' => 'School email', 'field_key' => 'school_email', 'field_type' => 'email']);
    bootcampField($event, ['label' => 'Project and goals', 'field_key' => 'project_and_goals', 'field_type' => 'heading']);
    bootcampField($event, ['label' => 'Final-year project?', 'field_key' => 'has_project', 'field_type' => 'radio', 'options' => [['label' => 'Yes', 'value' => '1'], ['label' => 'No', 'value' => '0']]]);
    bootcampField($event, ['label' => 'Project area', 'field_key' => 'project_area', 'field_type' => 'text', 'conditional_logic' => ['depends_on_field' => 'has_project', 'operator' => 'equals', 'value' => '1']]);
    bootcampField($event, ['label' => 'Consent', 'field_key' => 'consent', 'field_type' => 'checkbox']);

    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_PENDING_APPROVAL,
        'form_answers' => ['academic_level' => 'level-400', 'has_project' => '0', 'consent' => true, 'old_question' => 'kept'],
    ]);

    $this->actingAs($user)->getJson("http://{$host}/events/{$event->id}/registrations/{$registration->id}/application", ['HTTP_HOST' => $host])
        ->assertSuccessful()
        ->assertJsonPath('applicant.status', EventRegistration::STATUS_PENDING_APPROVAL)
        ->assertJsonPath('sections.0.title', 'About you')
        ->assertJsonPath('sections.0.answers', [
            ['label' => 'Current level', 'value' => 'Level 400 / Final year'],
        ])
        ->assertJsonPath('sections.1.title', 'Project and goals')
        ->assertJsonPath('sections.1.answers', [
            ['label' => 'Final-year project?', 'value' => 'No'],
            ['label' => 'Consent', 'value' => 'Yes'],
        ])
        ->assertJsonPath('sections.2.title', 'Answers to questions since removed')
        ->assertJsonPath('sections.2.answers', [['label' => 'Old Question', 'value' => 'kept']]);
});

test('an application cannot be read through another organisation', function () {
    [$tenant] = eventHost('medconf');
    [, $otherUser] = eventHost('othercon');
    $otherHost = eventSubdomainHost('othercon');
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);

    $this->actingAs($otherUser)
        ->getJson("http://{$otherHost}/events/{$event->id}/registrations/{$registration->id}/application", ['HTTP_HOST' => $otherHost])
        ->assertNotFound();
});
