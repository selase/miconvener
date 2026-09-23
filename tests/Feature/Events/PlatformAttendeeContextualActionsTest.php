<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventDynamicForm;
use App\Models\EventDynamicFormSubmission;
use App\Models\EventForumBan;
use App\Models\EventForumThread;
use App\Models\EventForumVote;
use App\Models\EventPoll;
use App\Models\EventPollResponse;
use App\Models\EventRegistration;
use App\Models\EventServiceRequest;
use App\Models\Tenant;
use App\Services\Events\PlatformAttendeeVerification;
use App\Services\Tenancy\TenantHostMatcher;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

if (! function_exists('Tests\Feature\Events\platformHost')) {
    function platformHost(): string
    {
        return app(TenantHostMatcher::class)->baseDomain();
    }
}

if (! function_exists('Tests\Feature\Events\sessionFor')) {
    function sessionFor(string $email): array
    {
        return [
            PlatformAttendeeVerification::SESSION_KEY => [
                'email' => PlatformAttendeeVerification::normalise($email),
                'verified_at' => now()->getTimestamp(),
                'expires_at' => now()->addHours(12)->getTimestamp(),
            ],
        ];
    }
}

test('verified attendee can retrieve live poll and respond with opaque token isolation', function (): void {
    $host = platformHost();
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'attendee@example.com',
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    $poll = EventPoll::factory()->live()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'question' => 'Which clinical workshop was most valuable?',
        'type' => EventPoll::TYPE_MULTIPLE_CHOICE,
    ]);
    $opt1 = $poll->options()->create(['tenant_id' => $tenant->id, 'label' => 'Neonatology', 'sort_order' => 1]);
    $opt2 = $poll->options()->create(['tenant_id' => $tenant->id, 'label' => 'Pediatric Cardiology', 'sort_order' => 2]);

    $session = sessionFor('attendee@example.com');

    // 1. Retrieve poll
    $getResponse = $this->withSession($session)
        ->getJson("http://{$host}/my/events/{$registration->id}/poll", ['HTTP_HOST' => $host]);

    $getResponse->assertOk();
    $getResponse->assertJsonPath('poll.id', $poll->id);
    $getResponse->assertJsonPath('poll.question', 'Which clinical workshop was most valuable?');
    expect($getResponse->json('poll.options'))->toHaveCount(2);

    // 2. Respond to poll
    $respondResponse = $this->withSession($session)
        ->postJson(
            "http://{$host}/my/events/{$registration->id}/poll/{$poll->id}/respond",
            ['option_id' => $opt1->id],
            ['HTTP_HOST' => $host]
        );

    $respondResponse->assertOk();

    // 3. Invariant audit: response is keyed by opaque HMAC token and NEVER contains registration ID or email
    $expectedToken = hash_hmac('sha256', "poll:{$registration->id}:{$poll->id}", (string) config('app.key'));
    $savedResponse = EventPollResponse::where('poll_id', $poll->id)->first();

    expect($savedResponse)->not->toBeNull();
    expect($savedResponse->respondent_token)->toBe($expectedToken);
    expect($savedResponse->option_id)->toBe($opt1->id);

    // Verify database row has no attendee personal data leaked
    $rawRow = $savedResponse->toArray();
    expect($rawRow)->not->toHaveKey('registration_id');
    expect($rawRow)->not->toHaveKey('email');

    // 4. Duplicate submission is rejected
    $duplicateResponse = $this->withSession($session)
        ->postJson(
            "http://{$host}/my/events/{$registration->id}/poll/{$poll->id}/respond",
            ['option_id' => $opt2->id],
            ['HTTP_HOST' => $host]
        );

    $duplicateResponse->assertStatus(422);
    $duplicateResponse->assertJsonPath('message', 'You already responded to this poll.');
});

test('attendee taking a quiz receives immediate scoring and feedback', function (): void {
    $host = platformHost();
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'quizzer@example.com',
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    $quiz = EventPoll::factory()->live()->quiz()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'question' => 'What is the standard emergency dosage?',
        'points' => 15,
    ]);
    $correctOpt = $quiz->options()->create(['tenant_id' => $tenant->id, 'label' => '0.1 mg/kg', 'sort_order' => 1, 'is_correct' => true]);
    $quiz->options()->create(['tenant_id' => $tenant->id, 'label' => '5.0 mg/kg', 'sort_order' => 2, 'is_correct' => false]);

    $session = sessionFor('quizzer@example.com');

    // Submit correct answer
    $response = $this->withSession($session)
        ->postJson(
            "http://{$host}/my/events/{$registration->id}/poll/{$quiz->id}/respond",
            ['option_id' => $correctOpt->id],
            ['HTTP_HOST' => $host]
        );

    $response->assertOk();
    $response->assertJson([
        'points_awarded' => 15,
        'is_correct' => true,
    ]);
});

test('attendee cannot respond to an inactive poll', function (): void {
    $host = platformHost();
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'attendee@example.com',
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    $draftPoll = EventPoll::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventPoll::STATUS_DRAFT,
        'went_live_at' => null,
    ]);
    $opt = $draftPoll->options()->create(['tenant_id' => $tenant->id, 'label' => 'Option 1', 'sort_order' => 1]);

    $session = sessionFor('attendee@example.com');

    $response = $this->withSession($session)
        ->postJson(
            "http://{$host}/my/events/{$registration->id}/poll/{$draftPoll->id}/respond",
            ['option_id' => $opt->id],
            ['HTTP_HOST' => $host]
        );

    $response->assertStatus(404);
});

test('verified attendee can access event forum even on private events', function (): void {
    $host = platformHost();
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    // Private event (visibility = private)
    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'visibility' => Event::VISIBILITY_PRIVATE,
    ]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'forum@example.com',
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    EventForumThread::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'title' => 'Where can we find session slides?',
        'body' => 'Are keynote slides being shared in the portal?',
        'author_name' => 'Dr. Mensah',
    ]);

    $session = sessionFor('forum@example.com');

    $response = $this->withSession($session)
        ->getJson("http://{$host}/my/events/{$registration->id}/forum", ['HTTP_HOST' => $host]);

    $response->assertOk();
    $response->assertJsonPath('is_banned', false);
    $response->assertJsonPath('threads.0.title', 'Where can we find session slides?');
});

test('attendee can create a forum question with optional anonymous posting', function (): void {
    $host = platformHost();
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'full_name' => 'Akua Asante',
        'email' => 'akua@example.com',
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    $session = sessionFor('akua@example.com');

    $response = $this->withSession($session)
        ->postJson(
            "http://{$host}/my/events/{$registration->id}/forum",
            [
                'title' => 'Will parking validation be available?',
                'body' => 'I would like to know if attendee parking is covered at the convention center.',
                'is_anonymous' => true,
            ],
            ['HTTP_HOST' => $host]
        );

    $response->assertStatus(201);
    $response->assertJsonPath('thread.author_name', 'Anonymous');

    $savedThread = EventForumThread::where('event_id', $event->id)->first();
    expect($savedThread)->not->toBeNull();
    expect($savedThread->title)->toBe('Will parking validation be available?');
    expect($savedThread->is_anonymous)->toBeTrue();
});

test('banned attendee is forbidden from creating forum threads', function (): void {
    $host = platformHost();
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'spammer@example.com',
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    EventForumBan::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'author_email' => 'spammer@example.com',
    ]);

    $session = sessionFor('spammer@example.com');

    $response = $this->withSession($session)
        ->postJson(
            "http://{$host}/my/events/{$registration->id}/forum",
            [
                'title' => 'Unsolicited announcement',
                'body' => 'Buy promotional tickets now.',
            ],
            ['HTTP_HOST' => $host]
        );

    $response->assertStatus(403);
    $response->assertJsonPath('message', "You're no longer able to post in this event's forum.");
});

test('attendee can upvote and unvote forum thread using derived voter token', function (): void {
    $host = platformHost();
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'voter@example.com',
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    $thread = EventForumThread::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'title' => 'Great keynote session!',
        'body' => 'Thanks to the organizers for this presentation.',
    ]);

    $session = sessionFor('voter@example.com');

    // Upvote
    $voteRes = $this->withSession($session)
        ->postJson(
            "http://{$host}/my/events/{$registration->id}/forum/{$thread->id}/vote",
            [],
            ['HTTP_HOST' => $host]
        );

    $voteRes->assertOk();
    $voteRes->assertJson([
        'votes_count' => 1,
        'voted_by_me' => true,
    ]);

    $expectedVoterToken = hash_hmac('sha256', "forum:{$registration->id}:event:{$event->id}", (string) config('app.key'));
    expect(EventForumVote::where('thread_id', $thread->id)->where('respondent_token', $expectedVoterToken)->exists())->toBeTrue();

    // Unvote
    $unvoteRes = $this->withSession($session)
        ->postJson(
            "http://{$host}/my/events/{$registration->id}/forum/{$thread->id}/unvote",
            [],
            ['HTTP_HOST' => $host]
        );

    $unvoteRes->assertOk();
    $unvoteRes->assertJson([
        'votes_count' => 0,
        'voted_by_me' => false,
    ]);
});

test('attendee dynamic form submission binds verified identity and enforces check-in gating', function (): void {
    $host = platformHost();
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'full_name' => 'Kofi Badu',
        'email' => 'kofi@example.com',
        'status' => EventRegistration::STATUS_CONFIRMED,
        'checked_in_at' => null, // NOT checked in yet
    ]);

    $form = EventDynamicForm::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'title' => 'Pediatric CME Assessment',
        'slug' => 'pediatric-cme-assessment',
        'type' => EventDynamicForm::TYPE_CME_EVALUATION,
        'requires_check_in' => true,
        'is_active' => true,
        'schema' => [
            [
                'key' => 'session_quality',
                'label' => 'Session Quality',
                'type' => 'select',
                'required' => true,
            ],
        ],
    ]);

    $session = sessionFor('kofi@example.com');

    // 1. Submit while not checked in -> rejected with 403
    $ineligibleRes = $this->withSession($session)
        ->postJson(
            "http://{$host}/my/events/{$registration->id}/forms/{$form->id}",
            ['answers' => ['session_quality' => 'Excellent']],
            ['HTTP_HOST' => $host]
        );

    $ineligibleRes->assertStatus(403);
    $ineligibleRes->assertJsonPath('message', 'This evaluation is restricted to checked-in attendees of this conference.');

    // 2. Now check in attendee
    $registration->update(['checked_in_at' => now()]);

    // 3. Validation failure if required schema answer is missing
    $missingRes = $this->withSession($session)
        ->postJson(
            "http://{$host}/my/events/{$registration->id}/forms/{$form->id}",
            ['answers' => ['other_field' => 'something']],
            ['HTTP_HOST' => $host]
        );

    $missingRes->assertStatus(422);
    $missingRes->assertJsonValidationErrors(['answers.session_quality']);

    // 4. Valid submission succeeds and automatically binds verified registration identity
    $validRes = $this->withSession($session)
        ->postJson(
            "http://{$host}/my/events/{$registration->id}/forms/{$form->id}",
            ['answers' => ['session_quality' => 'Excellent']],
            ['HTTP_HOST' => $host]
        );

    $validRes->assertStatus(201);

    $submission = EventDynamicFormSubmission::where('form_id', $form->id)->first();
    expect($submission)->not->toBeNull();
    expect($submission->registration_id)->toBe($registration->id);
    expect($submission->respondent_name)->toBe('Kofi Badu');
    expect($submission->respondent_email)->toBe('kofi@example.com');
    expect($submission->answers['session_quality'])->toBe('Excellent');
});

test('attendee service request tracks status progression, medical urgency, and prevents duplicate active requests', function (): void {
    $host = platformHost();
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    // Running event so help requests are permitted
    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHours(4),
    ]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'help@example.com',
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    $session = sessionFor('help@example.com');

    // 1. Submit a medical request -> escalated to urgent priority
    $createRes = $this->withSession($session)
        ->postJson(
            "http://{$host}/my/events/{$registration->id}/service-requests",
            [
                'type' => EventServiceRequest::TYPE_MEDICAL,
                'location' => 'Main Auditorium, Row C',
                'note' => 'Attendee requires ice pack for sprained ankle',
            ],
            ['HTTP_HOST' => $host]
        );

    $createRes->assertStatus(201);
    $createRes->assertJsonPath('service_request.priority', EventServiceRequest::PRIORITY_URGENT);
    $createRes->assertJsonPath('service_request.status', EventServiceRequest::STATUS_OPEN);

    // 2. Submitting duplicate active request of same type is rejected with 422
    $dupRes = $this->withSession($session)
        ->postJson(
            "http://{$host}/my/events/{$registration->id}/service-requests",
            [
                'type' => EventServiceRequest::TYPE_MEDICAL,
                'location' => 'Main Auditorium, Row C',
            ],
            ['HTTP_HOST' => $host]
        );

    $dupRes->assertStatus(422);
    $dupRes->assertJsonPath('message', 'You already have an active medical request in progress.');

    // 3. Different type (e.g. refreshment) is accepted
    $refreshmentRes = $this->withSession($session)
        ->postJson(
            "http://{$host}/my/events/{$registration->id}/service-requests",
            [
                'type' => EventServiceRequest::TYPE_REFRESHMENT,
                'location' => 'Main Auditorium, Row C',
            ],
            ['HTTP_HOST' => $host]
        );

    $refreshmentRes->assertStatus(201);

    // 4. Retrieve list of requests
    $listRes = $this->withSession($session)
        ->getJson("http://{$host}/my/events/{$registration->id}/service-requests", ['HTTP_HOST' => $host]);

    $listRes->assertOk();
    expect($listRes->json('service_requests'))->toHaveCount(2);

    // 5. Once the first request is resolved, another medical request can be raised
    $medicalReq = EventServiceRequest::where('type', EventServiceRequest::TYPE_MEDICAL)->first();
    $medicalReq->update([
        'status' => EventServiceRequest::STATUS_RESOLVED,
        'resolved_at' => now(),
    ]);

    $newMedicalRes = $this->withSession($session)
        ->postJson(
            "http://{$host}/my/events/{$registration->id}/service-requests",
            [
                'type' => EventServiceRequest::TYPE_MEDICAL,
                'location' => 'Front Desk',
            ],
            ['HTTP_HOST' => $host]
        );

    $newMedicalRes->assertStatus(201);
});

test('unauthorized or cross-registration contextual requests are rejected', function (): void {
    $host = platformHost();
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $registrationA = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'alice@example.com',
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    // Bob has a session for bob@example.com, but tries to access Alice's endpoints
    $bobSession = sessionFor('bob@example.com');

    $this->withSession($bobSession)
        ->getJson("http://{$host}/my/events/{$registrationA->id}/poll", ['HTTP_HOST' => $host])
        ->assertStatus(401);

    $this->withSession($bobSession)
        ->getJson("http://{$host}/my/events/{$registrationA->id}/forum", ['HTTP_HOST' => $host])
        ->assertStatus(401);

    $this->withSession($bobSession)
        ->getJson("http://{$host}/my/events/{$registrationA->id}/forms", ['HTTP_HOST' => $host])
        ->assertStatus(401);

    $this->withSession($bobSession)
        ->getJson("http://{$host}/my/events/{$registrationA->id}/service-requests", ['HTTP_HOST' => $host])
        ->assertStatus(401);
});
