<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Event;
use App\Models\EventAbstract;
use App\Models\EventAbstractAuthor;
use App\Models\EventCertificate;
use App\Models\EventDynamicForm;
use App\Models\EventForumThread;
use App\Models\EventMaterial;
use App\Models\EventPoll;
use App\Models\EventPollOption;
use App\Models\EventRegistration;
use App\Models\EventSeatAssignment;
use App\Models\EventSession;
use App\Models\EventSessionAttendance;
use App\Models\EventSpeaker;
use App\Models\EventVenueRoom;
use App\Models\Speaker;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Furnishes one event with every part the attendee workspace can show, so the
 * whole thing can be looked at rather than imagined.
 *
 * The event is deliberately in progress right now: "Get help" only opens while
 * an event is running or the attendee is checked in, and the dashboard's "Live
 * now" section needs the same. Re-running this moves the event to span the new
 * "now" and leaves everything else in place, so it can be used again next week
 * without piling up duplicates.
 *
 * Nothing here touches the probe tenant's existing events, which hold settled
 * live charges. It creates and maintains one event of its own.
 */
final class ProbeWorkspaceSeeder extends Seeder
{
    public const string EVENT_SLUG = 'probe-full-experience';

    public function run(): void
    {
        $tenantSlug = (string) config('attendee_portal.probe.tenant_slug');
        $attendeeEmail = mb_strtolower((string) config('attendee_portal.probe.attendee_email'));

        $tenant = Tenant::query()->where('slug', $tenantSlug)->first();

        if (! $tenant instanceof Tenant) {
            throw new RuntimeException("No tenant with slug [{$tenantSlug}]. Set PROBE_TENANT_SLUG to one that exists.");
        }

        app(TenantContext::class)->setTenant($tenant);
        setPermissionsTeamId($tenant->id);

        $event = $this->event($tenant);
        $sessions = $this->sessions($event);
        $this->materials($event, $sessions);
        $this->poll($event);
        $this->feedbackForm($event);
        $registration = $this->registration($event, $attendeeEmail);
        $this->seat($event, $registration);
        $this->forum($event, $sessions, $attendeeEmail);
        $this->attendance($event, $sessions, $registration);
        $this->certificate($event, $registration, $attendeeEmail);
        $this->abstractSubmission($event, $attendeeEmail);

        $this->command->info("Probe workspace ready: {$tenant->slug} / {$event->slug}");
        $this->command->info("Open https://miconvener.com/my as {$attendeeEmail}");
    }

    private function event(Tenant $tenant): Event
    {
        /** @var Event $event */
        $event = Event::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'slug' => self::EVENT_SLUG],
            [
                'name' => 'Probe Full Experience',
                'description' => 'Every part of the attendee workspace, switched on at once: a ticket with a seat, a running programme, released and withheld material, a live poll, an open feedback form, Q&A, and help while the room is open.',
                'status' => 'published',
                'visibility' => Event::VISIBILITY_PUBLIC,
                // Running now, so "Get help" and "Live now" are both open.
                'starts_at' => now()->subHours(2),
                'ends_at' => now()->addHours(6),
                'timezone' => 'Africa/Accra',
                'location_type' => 'in_person',
                'address' => 'Accra International Conference Centre, Accra, Ghana',
                'currency' => 'GHS',
                'ticket_price' => 0,
                'speaker_slide_policy' => Event::SPEAKER_POLICY_DURING,
            ]
        );

        return $event;
    }

    /**
     * A day with something already finished, something happening now, and
     * something still to come, so My day has all three states to render.
     *
     * @return array<string, EventSession>
     */
    private function sessions(Event $event): array
    {
        $plan = [
            'opening' => ['Opening plenary: what the portal is for', now()->subHours(2), now()->subHour(), 'Main Auditorium', 'Plenary'],
            'running' => ['Designing for attendees who arrive on mobile data', now()->subMinutes(20), now()->addMinutes(40), 'Main Auditorium', 'Product'],
            'workshop' => ['Workshop: running a hybrid conference on one laptop', now()->addHours(2), now()->addHours(3), 'Room B', 'Workshop'],
            'closing' => ['Closing remarks and certificates', now()->addHours(5), now()->addHours(6), 'Main Auditorium', 'Plenary'],
        ];

        $sessions = [];
        $order = 0;

        foreach ($plan as $key => [$title, $startsAt, $endsAt, $location, $track]) {
            /** @var EventSession $session */
            $session = EventSession::query()->updateOrCreate(
                ['event_id' => $event->id, 'title' => $title],
                [
                    'tenant_id' => $event->tenant_id,
                    'description' => 'Seeded by ProbeWorkspaceSeeder so the workspace has a real programme to show.',
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'location' => $location,
                    'track' => $track,
                    'capacity' => $key === 'workshop' ? 30 : null,
                    'sort_order' => $order++,
                ]
            );

            $sessions[$key] = $session;
        }

        return $sessions;
    }

    /**
     * Three files: one released to everyone, one held back until later today,
     * and one speaker deck that the release policy lets through only once that
     * speaker's session has started.
     *
     * @param  array<string, EventSession>  $sessions
     */
    private function materials(Event $event, array $sessions): void
    {
        $disk = Event::uploadDisk();

        $files = [
            'programme' => [
                'title' => 'Programme and floor plan',
                'body' => "Probe Full Experience\nProgramme and floor plan\n\nThis file exists so the download path can be exercised end to end.",
                'session' => null,
                'release_at' => null,
                'provenance' => EventMaterial::PROVENANCE_ORGANIZER,
            ],
            'handout' => [
                'title' => 'Workshop handout (released after the event)',
                'body' => "Workshop handout\n\nWithheld for the whole event, to show a dated release.",
                'session' => $sessions['workshop'],
                // Dated past the end of the event on purpose. Releasing part-way
                // through would be truer to a real handout, but this file's job
                // here is to be the withheld one: a fixture that quietly becomes
                // available half way through its own window teaches the reader
                // that the release policy is broken when it is working.
                'release_at' => now()->addHours(7),
                'provenance' => EventMaterial::PROVENANCE_ORGANIZER,
            ],
            'deck' => [
                'title' => 'Speaker deck: designing for mobile data',
                'body' => "Speaker deck\n\nReleased by the event's speaker-slide policy once the talk is under way.",
                'session' => $sessions['running'],
                'release_at' => null,
                'provenance' => EventMaterial::PROVENANCE_SPEAKER,
            ],
        ];

        $decks = [];

        foreach ($files as $key => $spec) {
            $path = "probe/{$event->id}/{$key}.txt";

            if (! Storage::disk($disk)->exists($path)) {
                Storage::disk($disk)->put($path, $spec['body']);
            }

            /** @var EventMaterial $material */
            $material = EventMaterial::query()->updateOrCreate(
                ['event_id' => $event->id, 'title' => $spec['title']],
                [
                    'tenant_id' => $event->tenant_id,
                    'session_id' => $spec['session']?->id,
                    'file_path' => $path,
                    'file_size' => mb_strlen($spec['body']),
                    'mime_type' => 'text/plain',
                    'download_limit' => 5,
                    'release_at' => $spec['release_at'],
                    'provenance' => $spec['provenance'],
                ]
            );

            $decks[$key] = $material;
        }

        $this->speaker($event, $sessions['running'], $decks['deck']);
    }

    /**
     * The speaker owns the deck, and the deck reaches attendees through that
     * speaker's sessions rather than a stamped session id.
     */
    private function speaker(Event $event, EventSession $session, EventMaterial $deck): void
    {
        /** @var Speaker $speaker */
        $speaker = Speaker::query()->updateOrCreate(
            ['tenant_id' => $event->tenant_id, 'name' => 'Dr Ama Serwaa'],
            [
                'title' => 'Head of Digital Health',
                'organization' => 'Ghana Health Service',
                'bio' => 'Works on clinical systems that have to run on the connection people actually have.',
            ]
        );

        /** @var EventSpeaker $eventSpeaker */
        $eventSpeaker = EventSpeaker::query()->updateOrCreate(
            ['event_id' => $event->id, 'speaker_id' => $speaker->id],
            ['tenant_id' => $event->tenant_id, 'slides_material_id' => $deck->id]
        );

        // The pivot carries its own uuid primary key and no tenant column.
        $session->speakers()->syncWithoutDetaching([
            $speaker->id => ['id' => (string) Str::uuid(), 'role' => 'speaker', 'sort_order' => 0],
        ]);

        unset($eventSpeaker);
    }

    private function poll(Event $event): void
    {
        /** @var EventPoll $poll */
        $poll = EventPoll::query()->updateOrCreate(
            ['event_id' => $event->id, 'question' => 'How are you joining us today?'],
            [
                'tenant_id' => $event->tenant_id,
                'type' => EventPoll::TYPE_MULTIPLE_CHOICE,
                'status' => EventPoll::STATUS_LIVE,
                'went_live_at' => now()->subMinutes(5),
                'requires_moderation' => false,
            ]
        );

        $options = ['In the room', 'Watching online', 'Catching up later', 'Presenting today'];

        foreach ($options as $index => $label) {
            EventPollOption::query()->updateOrCreate(
                ['poll_id' => $poll->id, 'label' => $label],
                ['tenant_id' => $event->tenant_id, 'sort_order' => $index]
            );
        }
    }

    private function feedbackForm(Event $event): void
    {
        EventDynamicForm::query()->updateOrCreate(
            ['event_id' => $event->id, 'slug' => 'session-feedback'],
            [
                'tenant_id' => $event->tenant_id,
                'title' => 'Tell us how today is going',
                'description' => 'Two minutes, and it shapes tomorrow.',
                'type' => EventDynamicForm::TYPE_FEEDBACK,
                'is_active' => true,
                'is_public' => false,
                'requires_check_in' => false,
                'schema' => [
                    ['key' => 'overall', 'label' => 'How is the event so far?', 'type' => 'rating', 'required' => true],
                    [
                        'key' => 'best_session',
                        'label' => 'Which session has been most useful?',
                        'type' => 'select',
                        'required' => false,
                        'options' => ['Opening plenary', 'Designing for mobile data', 'Workshop', 'Not sure yet'],
                    ],
                    ['key' => 'venue', 'label' => 'Could you hear and see clearly?', 'type' => 'boolean', 'required' => false],
                    ['key' => 'comments', 'label' => 'Anything you would change?', 'type' => 'textarea', 'required' => false],
                ],
            ]
        );
    }

    private function registration(Event $event, string $email): EventRegistration
    {
        /** @var EventRegistration $registration */
        $registration = EventRegistration::query()->updateOrCreate(
            ['event_id' => $event->id, 'email' => $email],
            [
                'tenant_id' => $event->tenant_id,
                'full_name' => 'Selase Kwawu',
                'phone' => '+233201234567',
                'status' => EventRegistration::STATUS_CHECKED_IN,
                'amount' => 0,
                'currency' => 'GHS',
                'email_verified_at' => now(),
                // Checked in, so Get help stays open even outside event hours
                // and the attendance record has something real behind it.
                'checked_in_at' => now()->subHours(2),
            ]
        );

        if (blank($registration->ticket_code)) {
            $registration->issueTicket();
            $registration->save();
        }

        return $registration->fresh() ?? $registration;
    }

    private function seat(Event $event, EventRegistration $registration): void
    {
        /** @var EventVenueRoom $room */
        $room = EventVenueRoom::query()->updateOrCreate(
            ['event_id' => $event->id, 'name' => 'Main Auditorium'],
            ['tenant_id' => $event->tenant_id, 'rows' => 12, 'seats_per_row' => 20]
        );

        EventSeatAssignment::query()->updateOrCreate(
            ['event_id' => $event->id, 'registration_id' => $registration->id],
            ['tenant_id' => $event->tenant_id, 'room_id' => $room->id, 'seat_label' => 'C-14']
        );
    }

    /**
     * @param  array<string, EventSession>  $sessions
     */
    private function forum(Event $event, array $sessions, string $attendeeEmail): void
    {
        $threads = [
            [
                'title' => 'Will the slides be shared after the talks?',
                'body' => 'Asking on behalf of colleagues who could not travel.',
                'author' => 'Kofi Mensah',
                'email' => 'kofi@example.com',
                'answered' => true,
                'pinned' => true,
                'session' => $sessions['opening'],
            ],
            [
                'title' => 'Is there a quiet room for prayers?',
                'body' => 'And is it on the same floor as the main auditorium?',
                'author' => 'Abena Owusu',
                'email' => 'abena@example.com',
                'answered' => false,
                'pinned' => false,
                'session' => null,
            ],
            [
                'title' => 'Great session on mobile data',
                'body' => 'The point about bundling assets for 3G was the most useful thing I have heard all week.',
                'author' => 'Selase Kwawu',
                'email' => $attendeeEmail,
                'answered' => false,
                'pinned' => false,
                'session' => $sessions['running'],
            ],
        ];

        foreach ($threads as $thread) {
            EventForumThread::query()->updateOrCreate(
                ['event_id' => $event->id, 'title' => $thread['title']],
                [
                    'tenant_id' => $event->tenant_id,
                    'session_id' => $thread['session']?->id,
                    'body' => $thread['body'],
                    'author_name' => $thread['author'],
                    'author_email' => $thread['email'],
                    'is_anonymous' => false,
                    'is_pinned' => $thread['pinned'],
                    'is_answered' => $thread['answered'],
                    'is_hidden' => false,
                ]
            );
        }
    }

    /**
     * @param  array<string, EventSession>  $sessions
     */
    private function attendance(Event $event, array $sessions, EventRegistration $registration): void
    {
        foreach (['opening', 'running'] as $key) {
            EventSessionAttendance::query()->updateOrCreate(
                ['session_id' => $sessions[$key]->id, 'registration_id' => $registration->id],
                [
                    'tenant_id' => $event->tenant_id,
                    'event_id' => $event->id,
                    'checked_in_at' => $sessions[$key]->starts_at,
                    'checked_out_at' => $key === 'opening' ? $sessions[$key]->ends_at : null,
                ]
            );
        }
    }

    private function certificate(Event $event, EventRegistration $registration, string $email): void
    {
        EventCertificate::query()->updateOrCreate(
            ['event_id' => $event->id, 'registration_id' => $registration->id],
            [
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $event->tenant_id,
                'recipient_name' => $registration->full_name,
                'recipient_email' => $email,
                'role' => 'Delegate',
                'cpd_hours' => 6.0,
                'issued_at' => now()->subMinutes(30),
            ]
        );
    }

    private function abstractSubmission(Event $event, string $email): void
    {
        /** @var EventAbstract $abstract */
        $abstract = EventAbstract::query()->updateOrCreate(
            ['event_id' => $event->id, 'code' => 'ABS-PROBE-001'],
            [
                'tenant_id' => $event->tenant_id,
                'title' => 'Ticketing at the edge: what happens when the network does not',
                'status' => EventAbstract::STATUS_ACCEPTED_ORAL,
                'presentation_preference' => EventAbstract::PREFERENCE_ORAL,
            ]
        );

        EventAbstractAuthor::query()->updateOrCreate(
            ['abstract_id' => $abstract->id, 'email' => $email],
            [
                'first_name' => 'Selase',
                'last_name' => 'Kwawu',
                'affiliation' => 'MiConvener',
                'is_presenting' => true,
                'is_corresponding' => true,
                'sort_order' => 0,
            ]
        );
    }
}
