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
