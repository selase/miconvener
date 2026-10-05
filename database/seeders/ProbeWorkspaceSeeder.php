<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Event;
use App\Models\EventAbstract;
use App\Models\EventAbstractAuthor;
use App\Models\EventBlast;
use App\Models\EventCertificate;
use App\Models\EventDynamicForm;
use App\Models\EventForumReply;
use App\Models\EventForumThread;
use App\Models\EventMaterial;
use App\Models\EventNotificationLog;
use App\Models\EventNotificationRule;
use App\Models\EventOperationPillar;
use App\Models\EventOperationTask;
use App\Models\EventParticipantGroup;
use App\Models\EventParticipantGroupMember;
use App\Models\EventPoll;
use App\Models\EventPollOption;
use App\Models\EventPollResponse;
use App\Models\EventPromoCode;
use App\Models\EventRegistration;
use App\Models\EventSeatAssignment;
use App\Models\EventServiceRequest;
use App\Models\EventSession;
use App\Models\EventSessionAttendance;
use App\Models\EventSpeaker;
use App\Models\EventSponsor;
use App\Models\EventSponsorDeliverable;
use App\Models\EventStaffLink;
use App\Models\EventTicketType;
use App\Models\EventVenueRoom;
use App\Models\PollDeck;
use App\Models\Speaker;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
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
    public const string EVENT_SLUG = 'ghana-digital-health-summit';

    /** What the demo event was called before it was given a realistic identity. */
    public const string LEGACY_EVENT_SLUG = 'probe-full-experience';

    /** The deck that walks through every newer question type. */
    public const string SHOWCASE_DECK = 'Interactive showcase';

    /** Every paid ticket, in pesewas: cheap enough to buy for real in a demo. */
    public const int DEMO_PRICE = 200;

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
        $tickets = $this->ticketTypes($event);
        $this->promoCodes($event, $tickets);
        $sessions = $this->sessions($event);
        $this->materials($event, $sessions);
        $this->polls($event);
        $this->feedbackForm($event);
        $registration = $this->registration($event, $attendeeEmail);
        $registration->update(['ticket_type_id' => $tickets['guest']->id]);
        $this->crowd($event, $tickets, $attendeeEmail);
        $this->seat($event, $registration);
        $this->forum($event, $sessions, $attendeeEmail);
        $this->attendance($event, $sessions, $registration);
        $this->certificate($event, $registration, $attendeeEmail);
        $this->abstractSubmission($event, $attendeeEmail);
        $this->speakers($event, $sessions, $attendeeEmail);
        $this->groups($event);
        $this->sponsors($event, $attendeeEmail);
        $this->announcements($event);
        $this->automations($event);
        $this->planning($event);
        $this->crowdInTheRoom($event, $sessions);
        $this->staff($event, $registration);

        $this->command->info("Probe workspace ready: {$tenant->slug} / {$event->slug}");
        $this->command->info("Open https://miconvener.com/my as {$attendeeEmail}");
    }

    private function event(Tenant $tenant): Event
    {
        // Renamed in place rather than recreated, so the registrations, deck and
        // presenter links built under the old slug carry over to the new one.
        $legacy = Event::query()->where('tenant_id', $tenant->id)->where('slug', self::LEGACY_EVENT_SLUG)->first();
        $current = Event::query()->where('tenant_id', $tenant->id)->where('slug', self::EVENT_SLUG)->exists();

        if ($legacy !== null && ! $current) {
            $legacy->update(['slug' => self::EVENT_SLUG]);
        }

        $existingToken = Event::query()
            ->where('tenant_id', $tenant->id)
            ->where('slug', self::EVENT_SLUG)
            ->value('present_token');

        /** @var Event $event */
        $event = Event::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'slug' => self::EVENT_SLUG],
            [
                'name' => 'Ghana Digital Health Summit 2026',
                // The wall is the point of a deck, and it opens on this token
                // rather than a login. Kept if one already exists, so re-seeding
                // does not revoke a link someone has open on a projector.
                'present_token' => $existingToken ?? Str::random(40),
                'description' => $this->description(),
                'status' => 'published',
                'visibility' => Event::VISIBILITY_PUBLIC,
                // Running now, so "Get help" and "Live now" are both open.
                'starts_at' => now()->subHours(2),
                'ends_at' => now()->addHours(6),
                'timezone' => 'Africa/Accra',
                'location_type' => 'in_person',
                'address' => 'Accra International Conference Centre, Castle Road, Ridge, Accra',
                'currency' => 'GHS',
                'ticket_price' => 0,
                'capacity' => 400,
                'speaker_slide_policy' => Event::SPEAKER_POLICY_DURING,
                'hero_image_path' => $this->storeImage('summit-hero.jpg', 'events/hero/ghana-digital-health-summit.jpg'),
                'cover_image_path' => $this->storeImage('summit-cover.jpg', 'events/cover/ghana-digital-health-summit.jpg'),
            ]
        );

        return $event;
    }

    private function description(): string
    {
        return <<<'TEXT'
            Ghana Digital Health Summit brings together clinicians, hospital administrators, policymakers, insurers and technologists for one day on a single question: what does it take to make digital health work in Ghana, not just in a pilot?

            Across four sessions we move from the national picture to the ward floor. The Ghana Health Service and NHIA open the day on interoperability and claims; district teams share what electronic records changed in practice; and founders building for low connectivity show what works when the network does not.

            Who should attend: medical and nursing leads, health information officers, hospital and pharmacy managers, NGO programme staff, and anyone building or buying health technology in West Africa.

            What is included: all sessions, lunch and refreshments, conference materials, a CPD certificate of attendance, and the evening networking reception.
            TEXT;
    }

    /**
     * Copies a committed demo image onto the upload disk at a fixed path, so
     * re-seeding overwrites it rather than piling up copies.
     */
    private function storeImage(string $asset, string $path): string
    {
        Storage::disk(Event::uploadDisk())->put($path, (string) file_get_contents(database_path("seeders/assets/demo/{$asset}")));

        return $path;
    }

    /**
     * Paid tiers at a price someone can actually pay in a demo, so checkout,
     * receipts, finance and payouts are shown with real money rather than
     * invented rows. The complimentary tier holds the seeded crowd; it is
     * invite-only, so the public page offers only what can be bought.
     *
     * @return array<string, EventTicketType>
     */
    private function ticketTypes(Event $event): array
    {
        $plan = [
            'delegate' => ['Delegate — Full Access', 'All sessions, lunch and refreshments, conference pack, CPD certificate and the evening reception.', self::DEMO_PRICE, 250, null],
            'student' => ['Student & Early Career', 'Full programme for students and professionals in their first three years. Bring your student or staff ID.', self::DEMO_PRICE, 80, null],
            'virtual' => ['Virtual Pass', 'Follow the sessions online, take part in live polls and Q&A, and download the materials.', self::DEMO_PRICE, 500, null],
            'guest' => ['Speaker & Invited Guest', 'Complimentary, by invitation.', 0, 120, 'GDHS-GUEST'],
        ];

        $tickets = [];
        $order = 0;

        foreach ($plan as $key => [$name, $description, $price, $capacity, $accessCode]) {
            /** @var EventTicketType $ticket */
            $ticket = EventTicketType::query()->updateOrCreate(
                ['event_id' => $event->id, 'name' => $name],
                [
                    'tenant_id' => $event->tenant_id,
                    'description' => $description,
                    'price' => $price,
                    'capacity' => $capacity,
                    'is_active' => true,
                    'access_code' => $accessCode,
                    'sort_order' => $order++,
                ]
            );
            $tickets[$key] = $ticket;
        }

        return $tickets;
    }

    /**
     * @param  array<string, EventTicketType>  $tickets
     */
    private function promoCodes(Event $event, array $tickets): void
    {
        $codes = [
            ['GDHS-EARLY', 'Early-bird: 25% off any paid ticket.', EventPromoCode::TYPE_PERCENTAGE, 25, 100, null],
            ['NURSES50', 'Half price for nursing and midwifery staff.', EventPromoCode::TYPE_PERCENTAGE, 50, 60, [$tickets['delegate']->id]],
            ['PARTNER-COMP', 'Complimentary delegate place for sponsor and partner staff.', EventPromoCode::TYPE_COMPLIMENTARY, 0, 20, [$tickets['delegate']->id]],
        ];

        foreach ($codes as [$code, $description, $type, $value, $max, $ticketIds]) {
            EventPromoCode::query()->updateOrCreate(
                ['event_id' => $event->id, 'code' => $code],
                [
                    'tenant_id' => $event->tenant_id,
                    'description' => $description,
                    'discount_type' => $type,
                    'discount_value' => $value,
                    'currency' => 'GHS',
                    'max_redemptions' => $max,
                    'max_per_attendee' => 1,
                    'starts_at' => now()->subDays(30),
                    'expires_at' => now()->addDays(7),
                    'applicable_ticket_type_ids' => $ticketIds,
                    'is_active' => true,
                ]
            );
        }
    }

    /**
     * The event-day crew: two gate ushers and a floor steward on staff links,
     * the crowd's check-ins credited to the gates, and attendee requests in
     * every state -- one waiting, one being handled, two done, one of them
     * medical -- so the help desk has something to show on both screens.
     */
    private function staff(Event $event, EventRegistration $attendee): void
    {
        $links = [];
        foreach ([
            'Gate A – Kwame' => ['can_check_in' => true, 'can_handle_requests' => false],
            'Gate B – Adjoa' => ['can_check_in' => true, 'can_handle_requests' => false],
            'Floor – Efua' => ['can_check_in' => true, 'can_handle_requests' => true],
        ] as $name => $switches) {
            $links[$name] = EventStaffLink::query()->firstOrCreate(
                ['event_id' => $event->id, 'name' => $name],
                ['tenant_id' => $event->tenant_id, 'token' => EventStaffLink::newToken(), ...$switches],
            );
            // A re-seed re-dates the event; a link switched off during a demo comes back.
            $links[$name]->update(['revoked_at' => null, ...$switches]);
        }

        $gates = [$links['Gate A – Kwame'], $links['Gate B – Adjoa']];
        EventRegistration::query()
            ->where('event_id', $event->id)
            ->where('status', EventRegistration::STATUS_CHECKED_IN)
            ->whereNull('checked_in_by_staff_link_id')
            ->where('id', '!=', $attendee->id)
            ->orderBy('checked_in_at')
            ->get()
            ->each(fn (EventRegistration $registration, int $i) => $registration->update([
                'checked_in_by_staff_link_id' => $gates[$i % 2]->id,
                'checked_in_source' => 'staff_link',
            ]));

        $crowd = EventRegistration::query()
            ->where('event_id', $event->id)
            ->where('status', EventRegistration::STATUS_CHECKED_IN)
            ->where('id', '!=', $attendee->id)
            // The crowd repeats names; the id keeps the pick the same on every run.
            ->orderBy('full_name')
            ->orderBy('id')
            ->limit(3)
            ->get();
        $floor = $links['Floor – Efua'];

        $this->helpRequest($event, $attendee, EventServiceRequest::TYPE_REFRESHMENT, 'Still water, please', 4);
        $this->helpRequest($event, $crowd[0], EventServiceRequest::TYPE_TECHNICAL, 'The microphone at our table is not working', 9, $floor);
        $this->helpRequest($event, $crowd[1], EventServiceRequest::TYPE_MEDICAL, 'Feeling faint, need to sit somewhere cool', 41, $floor, resolved: true);
        $this->helpRequest($event, $crowd[2], EventServiceRequest::TYPE_ACCESSIBILITY, 'Step-free way to the breakout room?', 63, $floor, resolved: true);
    }

    /**
     * One attendee request: open when nobody has it, acknowledged once a
     * steward has it, resolved when they are done.
     */
    private function helpRequest(Event $event, EventRegistration $registration, string $type, string $note, int $minutesAgo, ?EventStaffLink $handler = null, bool $resolved = false): void
    {
        $status = match (true) {
            $resolved => EventServiceRequest::STATUS_RESOLVED,
            $handler !== null => EventServiceRequest::STATUS_ACKNOWLEDGED,
            default => EventServiceRequest::STATUS_OPEN,
        };

        $request = EventServiceRequest::query()->updateOrCreate(
            ['event_id' => $event->id, 'registration_id' => $registration->id, 'type' => $type],
            [
                'tenant_id' => $event->tenant_id,
                'priority' => $type === EventServiceRequest::TYPE_MEDICAL ? EventServiceRequest::PRIORITY_URGENT : EventServiceRequest::PRIORITY_NORMAL,
                'status' => $status,
                'location' => 'Main Hall',
                'note' => $note,
                'assigned_staff_link_id' => $handler?->id,
                'acknowledged_at' => $handler !== null ? now()->subMinutes($minutesAgo - 2) : null,
                'resolved_at' => $resolved ? now()->subMinutes($minutesAgo - 12) : null,
            ],
        );
        $request->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->saveQuietly();
    }

    /**
     * A room's worth of delegates, so the guest list, check-in, badges and
     * headcount look like an event rather than a test.
     *
     * Every one is complimentary: finance and payouts are computed from these
     * rows, and inventing paid ones on a tenant that holds real charges would
     * report money that was never taken. Their addresses are sub-addresses of
     * the demo mailbox, so an announcement sent in a demo lands in an inbox the
     * presenter owns instead of bouncing and damaging deliverability for every
     * organiser on the platform.
     *
     * @param  array<string, EventTicketType>  $tickets
     */
    private function crowd(Event $event, array $tickets, string $attendeeEmail): void
    {
        [$local, $domain] = explode('@', $attendeeEmail) + [1 => 'example.com'];

        $first = ['Kwame', 'Ama', 'Kofi', 'Akosua', 'Yaw', 'Abena', 'Kwabena', 'Efua', 'Kojo', 'Adwoa', 'Kwesi', 'Esi', 'Nana', 'Afia', 'Selorm', 'Dzifa', 'Edem', 'Mawuli', 'Fatima', 'Ibrahim', 'Aisha', 'Mohammed', 'Naa', 'Nii', 'Dede', 'Ekow', 'Araba', 'Fiifi', 'Mansa', 'Kekeli'];
        $last = ['Mensah', 'Owusu', 'Boateng', 'Asante', 'Osei', 'Agyeman', 'Appiah', 'Darko', 'Amoah', 'Addo', 'Quaye', 'Tetteh', 'Lamptey', 'Ofori', 'Adjei', 'Nkrumah', 'Sarpong', 'Frimpong', 'Acheampong', 'Kuffour', 'Amankwah', 'Bekoe', 'Dogbe', 'Agbeko', 'Seidu', 'Abdulai', 'Yakubu', 'Iddrisu', 'Hammond', 'Aryeetey'];

        for ($i = 1; $i <= 84; $i++) {
            $name = $first[($i * 7) % count($first)].' '.$last[($i * 11) % count($last)];
            $present = $i % 5 !== 0;

            /** @var EventRegistration $registration */
            $registration = EventRegistration::query()->updateOrCreate(
                ['event_id' => $event->id, 'email' => sprintf('%s+ghs-%02d@%s', $local, $i, $domain)],
                [
                    'tenant_id' => $event->tenant_id,
                    'ticket_type_id' => $tickets['guest']->id,
                    'full_name' => $name,
                    'phone' => sprintf('+23324%07d', 1000000 + $i * 3713),
                    'status' => $present ? EventRegistration::STATUS_CHECKED_IN : EventRegistration::STATUS_CONFIRMED,
                    'amount' => 0,
                    'currency' => 'GHS',
                    'email_verified_at' => now()->subDays(10),
                    'checked_in_at' => $present ? now()->subMinutes(150 - $i) : null,
                    'checked_in_source' => $present ? 'scan' : null,
                    // Undo a scan made during a demo; staff() re-credits the present.
                    'checked_in_by_staff_link_id' => null,
                ]
            );

            if (blank($registration->ticket_code)) {
                $registration->issueTicket();
                $registration->save();
            }
        }
    }

    /**
     * A speaker for every session, with an address each -- a speaker without
     * one cannot reach their own view of the event. The addresses are
     * sub-addresses of the demo mailbox, like the crowd's.
     *
     * @param  array<string, EventSession>  $sessions
     */
    private function speakers(Event $event, array $sessions, string $attendeeEmail): void
    {
        [$local, $domain] = explode('@', $attendeeEmail) + [1 => 'example.com'];

        $plan = [
            ['Dr Ama Serwaa', 'Head of Digital Health', 'Ghana Health Service', 'running', 'serwaa',
                'Leads clinical systems that have to run on the connection people actually have. Previously led EMR roll-outs across twelve districts.'],
            ['Dr Kwabena Owusu-Ansah', 'Director of Claims Management', 'National Health Insurance Authority', 'workshop', 'owusu-ansah',
                'Oversees e-claims adoption across accredited facilities and the rules that decide whether a claim is paid first time.'],
            ['Prof. Nana Aba Appiah', 'Dean, School of Public Health', 'University of Ghana', 'opening', 'appiah',
                "Researches health information systems in West Africa and advises on Ghana's digital health strategy."],
            ['Esi Lamptey', 'Founder & CEO', 'CareLink Health', 'closing', 'lamptey',
                'Builds offline-first patient record tools used in more than 40 clinics in the Volta and Oti regions.'],
        ];

        foreach ($plan as $order => [$name, $title, $organisation, $session, $handle, $bio]) {
            /** @var Speaker $speaker */
            $speaker = Speaker::query()->updateOrCreate(
                ['tenant_id' => $event->tenant_id, 'name' => $name],
                [
                    'title' => $title,
                    'organization' => $organisation,
                    'bio' => $bio,
                    'email' => "{$local}+speaker-{$handle}@{$domain}",
                ]
            );

            EventSpeaker::query()->updateOrCreate(
                ['event_id' => $event->id, 'speaker_id' => $speaker->id],
                ['tenant_id' => $event->tenant_id]
            );

            if (! $sessions[$session]->speakers()->whereKey($speaker->id)->exists()) {
                $sessions[$session]->speakers()->attach($speaker->id, [
                    'id' => (string) Str::uuid(),
                    'role' => $order === 2 ? 'moderator' : 'speaker',
                    'sort_order' => 0,
                ]);
            }
        }
    }

    /**
     * Hand-picked groups, so an organiser can show messaging or badging one
     * slice of the room rather than everyone.
     */
    private function groups(Event $event): void
    {
        $crowd = $this->crowdRegistrations($event)->values();

        $plan = [
            ['Clinicians', 'Doctors, nurses and midwives attending for CPD.', '#0EA5E9', 'stethoscope', 0],
            ['Health administrators', 'Hospital, district and pharmacy managers.', '#8B5CF6', 'briefcase', 1],
            ['Students & early career', 'Students and professionals in their first three years.', '#F59E0B', 'graduation-cap', 2],
            ['Partners & exhibitors', 'Sponsor and exhibitor staff on site.', '#10B981', 'handshake', 3],
        ];

        foreach ($plan as [$name, $description, $color, $icon, $bucket]) {
            /** @var EventParticipantGroup $group */
            $group = EventParticipantGroup::query()->updateOrCreate(
                ['event_id' => $event->id, 'slug' => Str::slug($name)],
                [
                    'tenant_id' => $event->tenant_id,
                    'name' => $name,
                    'description' => $description,
                    'color' => $color,
                    'icon' => $icon,
                    'type' => EventParticipantGroup::TYPE_MANUAL,
                    'criteria' => null,
                ]
            );

            // Partners are a small group; the other three split the rest.
            $members = $crowd->filter(fn (EventRegistration $registration, int $index): bool => $bucket === 3
                ? $index % 12 === 11
                : $index % 12 !== 11 && $index % 3 === $bucket);

            foreach ($members as $registration) {
                EventParticipantGroupMember::query()->updateOrCreate(
                    ['group_id' => $group->id, 'registration_id' => $registration->id],
                    ['tenant_id' => $event->tenant_id, 'event_id' => $event->id, 'is_manual' => true, 'matched_at' => now()->subDays(3)]
                );
            }

            $group->update(['member_count' => EventParticipantGroupMember::query()->where('group_id', $group->id)->count()]);
        }
    }

    /**
     * Fictional companies on purpose: a demo shown to strangers must not imply
     * a real brand sponsors anything. Amounts are what each package is worth,
     * in pesewas; they are not payments and never reach finance.
     */
    private function sponsors(Event $event, string $attendeeEmail): void
    {
        [$local, $domain] = explode('@', $attendeeEmail) + [1 => 'example.com'];

        $plan = [
            ['Volta MedTech', EventSponsor::TIER_HEADLINE, 'Booth 1 · Main foyer', 'Akua Boateng', 5_000_000,
                ['Logo on the hero banner and badges' => true, 'Five-minute welcome in the opening plenary' => true, 'Booth fitted with power and screen' => false]],
            ['Ashanti Pharma Group', EventSponsor::TIER_SUPPORTING, 'Booth 4', 'Kojo Adjei', 2_000_000,
                ['Logo on the programme' => true, 'Branded lunch tables' => false]],
            ['Savannah Data Labs', EventSponsor::TIER_SUPPORTING, 'Booth 5', 'Fatima Seidu', 2_000_000,
                ['Logo on the programme' => true, 'Sponsored Wi-Fi network name' => true]],
            ['Coastal Health Insurance', EventSponsor::TIER_PARTNER, 'Table 9', 'Ekow Hammond', 800_000,
                ['Leaflet in the delegate pack' => false]],
        ];

        foreach ($plan as $order => [$name, $tier, $booth, $contact, $amount, $deliverables]) {
            /** @var EventSponsor $sponsor */
            $sponsor = EventSponsor::query()->updateOrCreate(
                ['event_id' => $event->id, 'name' => $name],
                [
                    'tenant_id' => $event->tenant_id,
                    'tier' => $tier,
                    'booth' => $booth,
                    'contact_name' => $contact,
                    'contact_email' => sprintf('%s+sponsor-%s@%s', $local, Str::slug($name), $domain),
                    'logo_path' => $this->storeImage('sponsors/'.Str::slug($name).'.png', 'events/sponsors/'.self::EVENT_SLUG.'-'.Str::slug($name).'.png'),
                    'amount' => $amount,
                    'currency' => 'GHS',
                    'sort_order' => $order,
                ]
            );

            foreach ($deliverables as $description => $done) {
                EventSponsorDeliverable::query()->updateOrCreate(
                    ['sponsor_id' => $sponsor->id, 'description' => $description],
                    ['tenant_id' => $event->tenant_id, 'is_done' => $done]
                );
            }
        }
    }

    /**
     * A history of messages already sent. Only sent ones: a scheduled blast
     * would be dispatched for real, to every delegate, on every re-seed.
     */
    private function announcements(Event $event): void
    {
        $recipients = EventRegistration::query()->where('event_id', $event->id)->count();

        $plan = [
            ['Your ticket and how to get to the AICC', 'Your ticket is attached. Doors open at 7:30am; the east car park is reserved for delegates. We look forward to seeing you.', now()->subDays(2)],
            ['Programme update: NHIA workshop moved to Room B', 'The e-claims workshop is now in Room B on the first floor, same time. Places are limited to 30, first come first served.', now()->subDay()],
            ['Welcome to the summit', 'Wi-Fi: GDHS-Delegates. Live polls and Q&A are in your ticket under Live poll. Lunch is served in the main foyer from 12:30.', now()->subHours(2)],
        ];

        foreach ($plan as [$subject, $body, $sentAt]) {
            $blast = EventBlast::query()->updateOrCreate(
                ['event_id' => $event->id, 'subject' => $subject],
                [
                    'tenant_id' => $event->tenant_id,
                    'body' => $body,
                    'audience' => 'all',
                    'audience_label' => 'All registrants',
                    // The room change also went by text, as an urgent update would.
                    'send_sms' => str_starts_with($subject, 'Programme update'),
                    'recipients_count' => $recipients,
                    'status' => EventBlast::STATUS_SENT,
                    'scheduled_at' => null,
                    'sent_at' => $sentAt,
                ]
            );

            if ($blast->send_sms) {
                $this->smsHistory($event, $blast);
            }
        }
    }

    /**
     * The texts an SMS announcement sent, as delivery records, so the
     * Announcements list shows how many went by SMS. Records only: nothing is
     * sent to the provider, so re-seeding never texts the crowd.
     */
    private function smsHistory(Event $event, EventBlast $blast): void
    {
        $registrations = EventRegistration::query()
            ->where('event_id', $event->id)
            ->whereNotNull('phone')
            ->get(['id', 'full_name', 'email', 'phone']);

        foreach ($registrations as $registration) {
            EventNotificationLog::query()->updateOrCreate(
                ['dedupe_key' => 'demo-blast-sms:'.$blast->id.':'.$registration->id],
                [
                    'tenant_id' => $event->tenant_id,
                    'event_id' => $event->id,
                    'notification_type' => 'announcement',
                    'source_type' => $blast->getMorphClass(),
                    'source_id' => $blast->id,
                    'recipient_name' => $registration->full_name,
                    'recipient_email' => $registration->email,
                    'recipient_phone' => $registration->phone,
                    'channel' => EventNotificationLog::CHANNEL_SMS,
                    'status' => EventNotificationLog::STATUS_SENT,
                    'subject' => $blast->subject,
                    'message' => $blast->subject.': '.$blast->body,
                    'cost_billed' => 0,
                    'attempts' => 1,
                    'sent_at' => $blast->sent_at,
                ]
            );
        }
    }

    /**
     * The welcome on registration is live, so a real purchase during a demo
     * sends the buyer a real email. The timed ones are paused: they run on a
     * schedule against the event's times, which every re-seed moves, and would
     * mail the whole crowd on each run.
     */
    private function automations(Event $event): void
    {
        $plan = [
            ['Welcome and ticket', EventNotificationRule::TRIGGER_ON_REGISTRATION, 'after', 0, 'minutes', true,
                'Your place at {{event_name}} is confirmed', 'Thank you for registering for {{event_name}}. Your ticket and QR code are in your MiConvener portal.'],
            ['Reminder the day before', EventNotificationRule::TRIGGER_SCHEDULED_OFFSET, 'before', 1, 'days', false,
                '{{event_name}} is tomorrow', 'Doors open at 7:30am at the Accra International Conference Centre. Bring your ticket QR code.'],
            ['Thank you and certificate', EventNotificationRule::TRIGGER_SCHEDULED_OFFSET, 'after', 2, 'hours', false,
                'Thank you for joining {{event_name}}', 'Your CPD certificate is now in your MiConvener portal, along with the session slides.'],
        ];

        foreach ($plan as [$name, $trigger, $direction, $amount, $unit, $active, $subject, $body]) {
            EventNotificationRule::query()->updateOrCreate(
                ['event_id' => $event->id, 'name' => $name],
                [
                    'tenant_id' => $event->tenant_id,
                    'target_role' => EventNotificationRule::ROLE_ATTENDEE,
                    'target_audience' => 'all',
                    'trigger_type' => $trigger,
                    'offset_direction' => $direction,
                    'offset_amount' => $amount,
                    'offset_unit' => $unit,
                    // The day-before reminder goes by text too (paused, so it never sends on a re-seed).
                    'channels' => $name === 'Reminder the day before' ? ['email', 'sms'] : ['email'],
                    'subject' => $subject,
                    'body_template' => $body,
                    'is_active' => $active,
                ]
            );
        }
    }

    /**
     * The organiser's run sheet: the app's own default workstreams, with a mix
     * of finished, moving and blocked tasks. Budgets are in pesewas.
     */
    private function planning(Event $event): void
    {
        EventOperationPillar::seedDefaultsForEvent($event);

        $pillars = EventOperationPillar::query()->where('event_id', $event->id)->orderBy('sort_order')->pluck('id')->values();

        $plan = [
            ['Confirm all four speakers and collect slides', 0, 'Abena Owusu', -10, EventOperationTask::PRIORITY_HIGH, EventOperationTask::STATUS_DONE, null, null],
            ['Print the programme and delegate packs', 0, 'Kofi Mensah', -2, EventOperationTask::PRIORITY_MEDIUM, EventOperationTask::STATUS_DONE, 450_000, 418_000],
            ['Test the registration desk scanners on site', 1, 'Selorm Dogbe', -1, EventOperationTask::PRIORITY_HIGH, EventOperationTask::STATUS_DONE, null, null],
            ['Venue Wi-Fi capacity for 400 devices', 1, 'Nii Quaye', 0, EventOperationTask::PRIORITY_URGENT, EventOperationTask::STATUS_IN_PROGRESS, 600_000, null],
            ['Catering: lunch and two coffee breaks', 2, 'Adwoa Asante', -5, EventOperationTask::PRIORITY_HIGH, EventOperationTask::STATUS_DONE, 3_200_000, 3_050_000],
            ['Settle AV hire invoice', 2, 'Kwesi Ofori', 3, EventOperationTask::PRIORITY_MEDIUM, EventOperationTask::STATUS_BLOCKED, 1_500_000, null],
            ['Fit out the headline sponsor booth', 3, 'Akua Boateng', 0, EventOperationTask::PRIORITY_MEDIUM, EventOperationTask::STATUS_IN_PROGRESS, 250_000, 120_000],
            ['Post-event sponsor report', 3, 'Akua Boateng', 7, EventOperationTask::PRIORITY_LOW, EventOperationTask::STATUS_NOT_STARTED, null, null],
        ];

        foreach ($plan as [$title, $pillarIndex, $owner, $dueInDays, $priority, $status, $estimated, $actual]) {
            EventOperationTask::query()->updateOrCreate(
                ['event_id' => $event->id, 'title' => $title],
                [
                    'tenant_id' => $event->tenant_id,
                    'pillar_id' => $pillars->isEmpty() ? null : $pillars->get($pillarIndex % $pillars->count()),
                    'owner_name' => $owner,
                    'due_date' => now()->addDays($dueInDays)->toDateString(),
                    'priority' => $priority,
                    'status' => $status,
                    'estimated_budget' => $estimated ?? 0,
                    'actual_budget' => $actual ?? 0,
                    'completed_at' => $status === EventOperationTask::STATUS_DONE ? now()->subDays(max(1, -$dueInDays)) : null,
                ]
            );
        }
    }

    /**
     * Seats for part of the crowd, and session attendance for everyone who is
     * here, so the venue map and room headcount show a room filling up rather
     * than one occupied seat in an empty hall.
     *
     * @param  array<string, EventSession>  $sessions
     */
    private function crowdInTheRoom(Event $event, array $sessions): void
    {
        $auditorium = EventVenueRoom::query()->where('event_id', $event->id)->where('name', 'Main Auditorium')->first();

        EventVenueRoom::query()->updateOrCreate(
            ['event_id' => $event->id, 'name' => 'Room B'],
            ['tenant_id' => $event->tenant_id, 'rows' => 5, 'seats_per_row' => 6, 'sort_order' => 1]
        );

        foreach ($this->crowdRegistrations($event)->values() as $index => $registration) {
            if ($auditorium !== null && $index < 48) {
                // Rows A-H from the front, even seat numbers, never C-14:
                // that is the demo attendee's own seat.
                $row = chr(ord('A') + intdiv($index, 6));
                $number = 4 + ($index % 6) * 2;
                $label = "{$row}-{$number}" === 'C-14' ? "{$row}-15" : "{$row}-{$number}";

                EventSeatAssignment::query()->updateOrCreate(
                    ['event_id' => $event->id, 'registration_id' => $registration->id],
                    ['tenant_id' => $event->tenant_id, 'room_id' => $auditorium->id, 'seat_label' => $label]
                );
            }

            if (! $registration->isPresent()) {
                continue;
            }

            foreach (['opening' => true, 'running' => $index % 3 !== 0] as $key => $attended) {
                if (! $attended) {
                    continue;
                }

                EventSessionAttendance::query()->updateOrCreate(
                    ['session_id' => $sessions[$key]->id, 'registration_id' => $registration->id],
                    [
                        'tenant_id' => $event->tenant_id,
                        'event_id' => $event->id,
                        'checked_in_at' => $sessions[$key]->starts_at->copy()->addMinutes($index % 15),
                        'checked_out_at' => $key === 'opening' ? $sessions[$key]->ends_at : null,
                    ]
                );
            }
        }
    }

    /**
     * @return Collection<int, EventRegistration>
     */
    private function crowdRegistrations(Event $event): Collection
    {
        return EventRegistration::query()
            ->where('event_id', $event->id)
            ->where('email', 'like', '%+ghs-%')
            ->orderBy('email')
            ->get();
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
            'opening' => ["Opening plenary: Ghana's digital health strategy to 2030", now()->subHours(2), now()->subHour(), 'Main Auditorium', 'Plenary',
                'Where the national strategy stands, what changes for facilities this year, and how interoperability and NHIA e-claims fit together.'],
            'running' => ['Designing for patients who arrive on mobile data', now()->subMinutes(20), now()->addMinutes(40), 'Main Auditorium', 'Technology',
                'Most patients and many health workers reach digital services on a prepaid 3G bundle. What that means for how health systems should be built.'],
            'workshop' => ['Workshop: getting NHIA e-claims right first time', now()->addHours(2), now()->addHours(3), 'Room B', 'Workshop',
                'Hands-on: the claim fields that cause most rejections, and how to catch them before submission. Limited to 30 places.'],
            'closing' => ['Closing panel and CPD certificates', now()->addHours(5), now()->addHours(6), 'Main Auditorium', 'Plenary',
                'What we heard today and what to take back to your facility, followed by the presentation of CPD certificates.'],
        ];

        // Earlier versions of this fixture used placeholder titles. Renaming
        // them in place keeps the attendance, forum threads and agenda entries
        // attached to them, where creating new sessions would orphan all three.
        $legacy = [
            'opening' => 'Opening plenary: what the portal is for',
            'running' => 'Designing for attendees who arrive on mobile data',
            'workshop' => 'Workshop: running a hybrid conference on one laptop',
            'closing' => 'Closing remarks and certificates',
        ];

        foreach ($legacy as $key => $oldTitle) {
            $newTitle = $plan[$key][0];
            if (! EventSession::query()->where('event_id', $event->id)->where('title', $newTitle)->exists()) {
                EventSession::query()->where('event_id', $event->id)->where('title', $oldTitle)->update(['title' => $newTitle]);
            }
        }

        $sessions = [];
        $order = 0;

        foreach ($plan as $key => [$title, $startsAt, $endsAt, $location, $track, $description]) {
            /** @var EventSession $session */
            $session = EventSession::query()->updateOrCreate(
                ['event_id' => $event->id, 'title' => $title],
                [
                    'tenant_id' => $event->tenant_id,
                    'description' => $description,
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

            // Keyed on the path, not the title. Re-titling a fixture used to
            // orphan the old row rather than update it, leaving a stale copy
            // beside the new one -- which is exactly how a withheld file came
            // to be visible during a browser run.
            /** @var EventMaterial $material */
            $material = EventMaterial::query()->updateOrCreate(
                ['event_id' => $event->id, 'file_path' => $path],
                [
                    'tenant_id' => $event->tenant_id,
                    'session_id' => $spec['session']?->id,
                    'title' => $spec['title'],
                    'file_size' => mb_strlen($spec['body']),
                    'mime_type' => 'text/plain',
                    'download_limit' => 5,
                    'release_at' => $spec['release_at'],
                    'provenance' => $spec['provenance'],
                ]
            );

            $decks[$key] = $material;
        }

        // Anything this seeder is no longer responsible for goes, so a renamed
        // or dropped fixture cannot linger and be mistaken for real behaviour.
        EventMaterial::query()
            ->where('event_id', $event->id)
            ->whereNotIn('id', collect($decks)->pluck('id')->all())
            ->delete();

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

    /**
     * A spread of polls, so the results screen can be judged against shapes it
     * will actually meet: a runaway favourite, a dead heat, a five-point scale,
     * an eleven-point one, a question nobody answered, a quiz before and after
     * its answer is revealed, and free text both moderated and not.
     *
     * Only one is live. The rest are closed, so an organiser can put each on
     * the wall in turn from Live polls without two competing for the screen.
     *
     * @return array<int, array<string, mixed>>
     */
    private function pollPlan(): array
    {
        return [
            [
                'question' => 'This session met my expectations',
                'type' => EventPoll::TYPE_MULTIPLE_CHOICE,
                'status' => EventPoll::STATUS_LIVE,
                'options' => ['Strongly disagree' => 1, 'Disagree' => 2, 'Neutral' => 6, 'Agree' => 18, 'Strongly agree' => 27],
            ],
            [
                'question' => 'Did the venue Wi-Fi work for you?',
                'type' => EventPoll::TYPE_MULTIPLE_CHOICE,
                'status' => EventPoll::STATUS_CLOSED,
                // One option runs away with it -- the case where every other bar
                // has to stay readable next to a full-width one.
                'options' => ['Yes, fine' => 47, 'Slow but usable' => 9, 'No' => 3],
            ],
            [
                'question' => 'Coffee or tea?',
                'type' => EventPoll::TYPE_MULTIPLE_CHOICE,
                'status' => EventPoll::STATUS_CLOSED,
                'options' => ['Coffee' => 21, 'Tea' => 21],
            ],
            [
                'question' => 'How likely are you to recommend MiConvener to a colleague?',
                'type' => EventPoll::TYPE_MULTIPLE_CHOICE,
                'status' => EventPoll::STATUS_CLOSED,
                // Eleven bars: the most a screen should ever have to carry.
                'options' => ['0' => 0, '1' => 0, '2' => 1, '3' => 1, '4' => 2, '5' => 3, '6' => 4, '7' => 6, '8' => 11, '9' => 14, '10' => 19],
            ],
            [
                'question' => 'Which track will you follow tomorrow?',
                'type' => EventPoll::TYPE_MULTIPLE_CHOICE,
                'status' => EventPoll::STATUS_CLOSED,
                'options' => ['Clinical practice' => 14, 'Health informatics' => 22, 'Policy' => 8, 'Research methods' => 11, 'Undecided' => 6],
            ],
            [
                'question' => 'Are you joining the gala dinner?',
                'type' => EventPoll::TYPE_MULTIPLE_CHOICE,
                'status' => EventPoll::STATUS_CLOSED,
                'options' => ['Yes' => 38, 'No' => 12],
            ],
            [
                'question' => 'Has anyone voted on this one yet?',
                'type' => EventPoll::TYPE_MULTIPLE_CHOICE,
                'status' => EventPoll::STATUS_CLOSED,
                // Nobody has. Every bar at zero, and no division by it.
                'options' => ['Option A' => 0, 'Option B' => 0, 'Option C' => 0],
            ],
            [
                'question' => 'Which of these is a notifiable disease in Ghana?',
                'type' => EventPoll::TYPE_QUIZ,
                'status' => EventPoll::STATUS_CLOSED,
                'points' => 10,
                'correct' => 'Cholera',
                'options' => ['Cholera' => 31, 'Hypertension' => 7, 'Type 2 diabetes' => 4, 'Asthma' => 2],
            ],
            [
                'question' => 'In what year was the first MiConvener congress held?',
                'type' => EventPoll::TYPE_QUIZ,
                'status' => EventPoll::STATUS_CLOSED,
                'points' => 10,
                'correct' => '2024',
                'options' => ['2022' => 5, '2023' => 12, '2024' => 18, '2025' => 9],
            ],
            [
                'question' => 'What should we cover at next year\'s congress?',
                'type' => EventPoll::TYPE_OPEN,
                'status' => EventPoll::STATUS_CLOSED,
                'requires_moderation' => false,
                'answers' => [
                    ['Offline-first tooling for district hospitals', 'Ama Serwaa'],
                    ['More on data protection and consent', null],
                    ['Practical sessions, fewer keynotes', 'Kofi Mensah'],
                    ['Costing and reimbursement models', null],
                    ['Interoperability between the big EMRs', 'Abena Owusu'],
                    ['Training pathways for informatics staff', null],
                    ['Procurement, honestly', 'Yaw Darko'],
                    ['Maternal health dashboards', null],
                    ['How to keep a system running after the grant ends', 'Efua Quaye'],
                ],
            ],
            [
                'question' => 'Any questions for the closing panel?',
                'type' => EventPoll::TYPE_OPEN,
                'status' => EventPoll::STATUS_CLOSED,
                // Moderated: what a moderator has not yet approved must not
                // reach a wall, which is the whole point of moderating it.
                'requires_moderation' => true,
                'answers' => [
                    ['How do we fund maintenance, not just pilots?', 'Kwame Asante', true],
                    ['Will the slides be shared?', null, true],
                    ['Can we see the raw evaluation data?', 'Adwoa Boateng', true],
                    ['this one is still waiting on a moderator', null, null],
                    ['and so is this one', 'Anonymous', null],
                ],
            ],
        ];
    }

    private function polls(Event $event): void
    {
        $kept = [];
        $order = 0;

        foreach ($this->pollPlan() as $spec) {
            $wentLiveAt = now()->subMinutes(120 - ($order * 10));

            /** @var EventPoll $poll */
            $poll = EventPoll::query()->updateOrCreate(
                ['event_id' => $event->id, 'question' => $spec['question']],
                [
                    'tenant_id' => $event->tenant_id,
                    'type' => $spec['type'],
                    'status' => $spec['status'],
                    'went_live_at' => $wentLiveAt,
                    'points' => $spec['points'] ?? 0,
                    'requires_moderation' => $spec['requires_moderation'] ?? false,
                ]
            );

            $poll->responses()->delete();

            if ($spec['type'] === EventPoll::TYPE_OPEN) {
                $this->openAnswers($event, $poll, $spec['answers']);
            } else {
                $this->optionVotes($event, $poll, $spec);
            }

            $kept[] = $poll->id;
            $order++;
        }

        // Anything this seeder no longer owns goes, so a question dropped from
        // the plan cannot linger on the wall. The showcase deck's questions
        // are owned by showcase() and left for it to maintain.
        $showcase = PollDeck::query()->where('event_id', $event->id)->where('title', self::SHOWCASE_DECK)->value('id');

        EventPoll::query()
            ->where('event_id', $event->id)
            ->whereNotIn('id', $kept)
            ->when($showcase !== null, fn ($query) => $query->where(fn ($q) => $q->whereNull('deck_id')->orWhere('deck_id', '!=', $showcase)))
            ->delete();

        $this->deck($event);
        $this->showcase($event);
    }

    /**
     * A second deck with one question of every newer type, already answered
     * by the room, so a demo can walk through every chart the product draws
     * without waiting for votes. Draft, like the first deck: starting it is the
     * thing being shown.
     */
    private function showcase(Event $event): void
    {
        /** @var PollDeck $deck */
        $deck = PollDeck::query()->updateOrCreate(
            ['event_id' => $event->id, 'title' => self::SHOWCASE_DECK],
            [
                'tenant_id' => $event->tenant_id,
                'join_code' => PollDeck::query()->where('event_id', $event->id)->where('title', self::SHOWCASE_DECK)->value('join_code') ?? PollDeck::generateJoinCode(),
                'status' => PollDeck::STATUS_DRAFT,
                'present_token' => PollDeck::query()->where('event_id', $event->id)->where('title', self::SHOWCASE_DECK)->value('present_token') ?? Str::random(48),
            ]
        );
        $deck->setCurrentPoll(null);

        $plan = [
            [EventPoll::TYPE_YES_NO, 'Does your facility use an electronic health record today?', [], null,
                fn (array $o): array => [...array_fill(0, 34, ['option_id' => $o['Yes']]), ...array_fill(0, 18, ['option_id' => $o['No']])]],
            [EventPoll::TYPE_RATING, 'How would you rate the summit so far?', [], null,
                fn (array $o): array => array_merge(...array_map(fn (string $star, int $n): array => array_fill(0, $n, ['option_id' => $o[$star]]), ['5', '4', '3', '2', '1'], [21, 17, 6, 2, 1]))],
            [EventPoll::TYPE_SCALE, 'How ready is your facility for NHIA e-claims?', [], ['min' => 1, 'max' => 10, 'label_min' => 'Not ready', 'label_max' => 'Fully ready'],
                fn (array $o): array => array_map(fn (int $v): array => ['response_number' => $v], [2, 3, 3, 4, 4, 4, 5, 5, 5, 5, 6, 6, 6, 6, 6, 7, 7, 7, 7, 7, 7, 8, 8, 8, 8, 9, 9, 10, 3, 5, 6, 7, 8, 4, 6, 7])],
            [EventPoll::TYPE_NUMBER, 'How many beds does your facility have?', [], ['unit' => 'beds'],
                fn (array $o): array => array_map(fn (int $v): array => ['response_number' => $v], [12, 18, 24, 30, 30, 40, 45, 60, 60, 80, 96, 120, 150, 180, 200, 240, 300, 400, 420, 650])],
            [EventPoll::TYPE_MULTI_SELECT, 'Which of these would you fund first? Pick any.', ['Connectivity', 'Staff training', 'Hardware', 'Data security', 'Patient-facing apps'], null,
                fn (array $o): array => array_map(fn (array $picks): array => ['response_payload' => array_map(fn (string $p): string => $o[$p], $picks)], [
                    ...array_fill(0, 14, ['Connectivity', 'Staff training']),
                    ...array_fill(0, 9, ['Staff training', 'Data security']),
                    ...array_fill(0, 7, ['Connectivity', 'Hardware', 'Staff training']),
                    ...array_fill(0, 5, ['Patient-facing apps']),
                    ...array_fill(0, 6, ['Connectivity', 'Data security']),
                ])],
            [EventPoll::TYPE_WORD_CLOUD, 'In one to three words: what does digital health in Ghana need most?', [], null,
                fn (array $o): array => array_map(fn (array $words): array => ['response_payload' => $words, 'response_text' => implode(', ', $words)], [
                    ...array_fill(0, 11, ['connectivity', 'training']),
                    ...array_fill(0, 7, ['funding']),
                    ...array_fill(0, 6, ['interoperability', 'trust']),
                    ...array_fill(0, 4, ['data security']),
                    ...array_fill(0, 3, ['leadership', 'funding']),
                    ['power supply'], ['patient privacy'], ['local developers'], ['policy', 'trust'], ['standards'],
                ])],
            [EventPoll::TYPE_RANKING, 'Rank these priorities for the next national strategy.', ['Interoperability', 'Workforce', 'Funding', 'Data privacy'], null,
                fn (array $o): array => array_map(fn (array $order): array => ['response_payload' => array_map(fn (string $p): string => $o[$p], $order)], [
                    ...array_fill(0, 12, ['Funding', 'Workforce', 'Interoperability', 'Data privacy']),
                    ...array_fill(0, 8, ['Workforce', 'Funding', 'Interoperability', 'Data privacy']),
                    ...array_fill(0, 6, ['Interoperability', 'Funding', 'Data privacy', 'Workforce']),
                    ...array_fill(0, 4, ['Data privacy', 'Interoperability', 'Workforce', 'Funding']),
                ])],
        ];

        foreach ($plan as $position => [$type, $question, $labels, $settings, $answers]) {
            /** @var EventPoll $poll */
            $poll = EventPoll::query()->updateOrCreate(
                ['event_id' => $event->id, 'question' => $question],
                [
                    'tenant_id' => $event->tenant_id,
                    'deck_id' => $deck->id,
                    'position' => $position,
                    'type' => $type,
                    'status' => EventPoll::STATUS_DRAFT,
                    'settings' => $settings,
                    'requires_moderation' => false,
                    'points' => 0,
                ]
            );

            $labels = match ($type) {
                EventPoll::TYPE_YES_NO => ['Yes', 'No'],
                EventPoll::TYPE_RATING => ['1', '2', '3', '4', '5'],
                default => $labels,
            };

            foreach ($labels as $order => $label) {
                EventPollOption::query()->updateOrCreate(
                    ['poll_id' => $poll->id, 'label' => $label],
                    ['tenant_id' => $event->tenant_id, 'sort_order' => $order, 'is_correct' => false]
                );
            }

            $options = EventPollOption::query()->where('poll_id', $poll->id)->pluck('id', 'label')->all();

            // Rebuilt from the plan each time, so demo votes cast on a previous
            // run do not accumulate on top of the room's seeded answers.
            $poll->responses()->delete();

            foreach ($answers($options) as $i => $answer) {
                EventPollResponse::query()->create([
                    'tenant_id' => $event->tenant_id,
                    'poll_id' => $poll->id,
                    'respondent_token' => "showcase-{$i}",
                    'is_approved' => true,
                    ...$answer,
                ]);
            }
        }
    }

    /**
     * One deck, left in draft, holding five questions that between them cover
     * every way a chart has to fit: five bars, three, the eleven-point scale
     * that drops to one line per option, five again, and a quiz whose answer is
     * revealed on close.
     *
     * Draft rather than live on purpose: starting it is the thing being
     * demonstrated, and a deck already running would take the wall away from
     * the loose polls this fixture also exists to show. It keeps any presenter
     * link it already has, so re-seeding does not revoke one in use.
     */
    private function deck(Event $event): void
    {
        $existingToken = PollDeck::query()
            ->where('event_id', $event->id)
            ->where('title', 'Opening plenary')
            ->value('present_token');

        /** @var PollDeck $deck */
        $deck = PollDeck::query()->updateOrCreate(
            ['event_id' => $event->id, 'title' => 'Opening plenary'],
            [
                'tenant_id' => $event->tenant_id,
                'join_code' => PollDeck::generateJoinCode(),
                'status' => PollDeck::STATUS_DRAFT,
                'present_token' => $existingToken ?? Str::random(48),
            ]
        );

        $deck->setCurrentPoll(null);
        EventPoll::query()->where('deck_id', $deck->id)->update(['deck_id' => null, 'position' => 0]);

        $questions = [
            'This session met my expectations',
            'Did the venue Wi-Fi work for you?',
            'How likely are you to recommend MiConvener to a colleague?',
            'Which track will you follow tomorrow?',
            'Which of these is a notifiable disease in Ghana?',
        ];

        foreach ($questions as $position => $question) {
            EventPoll::query()
                ->where('event_id', $event->id)
                ->where('question', $question)
                ->update(['deck_id' => $deck->id, 'position' => $position]);
        }
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function optionVotes(Event $event, EventPoll $poll, array $spec): void
    {
        $order = 0;

        foreach ($spec['options'] as $label => $votes) {
            /** @var EventPollOption $option */
            $option = EventPollOption::query()->updateOrCreate(
                ['poll_id' => $poll->id, 'label' => (string) $label],
                [
                    'tenant_id' => $event->tenant_id,
                    'sort_order' => $order,
                    'is_correct' => ($spec['correct'] ?? null) === $label,
                ]
            );

            for ($i = 0; $i < $votes; $i++) {
                EventPollResponse::query()->create([
                    'tenant_id' => $event->tenant_id,
                    'poll_id' => $poll->id,
                    'option_id' => $option->id,
                    'respondent_token' => hash('sha256', "probe:{$poll->id}:{$label}:{$i}"),
                    'is_correct' => $option->is_correct ? true : null,
                    'points_awarded' => $option->is_correct ? ($spec['points'] ?? 0) : 0,
                    'is_approved' => true,
                ]);
            }

            $order++;
        }

        EventPollOption::query()
            ->where('poll_id', $poll->id)
            ->whereNotIn('label', array_map('strval', array_keys($spec['options'])))
            ->delete();
    }

    /**
     * @param  array<int, array<int, mixed>>  $answers
     */
    private function openAnswers(Event $event, EventPoll $poll, array $answers): void
    {
        foreach ($answers as $index => $answer) {
            [$text, $name] = [$answer[0], $answer[1] ?? null];
            $approved = array_key_exists(2, $answer) ? $answer[2] : true;

            EventPollResponse::query()->create([
                'tenant_id' => $event->tenant_id,
                'poll_id' => $poll->id,
                'response_text' => $text,
                'respondent_name' => $name,
                'respondent_token' => hash('sha256', "probe:{$poll->id}:open:{$index}"),
                'is_approved' => $approved,
            ]);
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
        $organiser = 'Summit Secretariat';

        $threads = [
            ['Will the slides be shared after the talks?', 'Asking on behalf of colleagues at Tamale Teaching Hospital who could not travel down.', 'Kofi Mensah', true, true, 'opening', [
                [$organiser, 'Yes. Every speaker has agreed to share their deck, and they appear under Downloads in your ticket as each session ends.'],
                ['Esi Tetteh', 'Thank you. The NHIA claims deck especially, please.'],
            ]],
            ['Is there a quiet room for prayers?', 'And is it on the same floor as the main auditorium?', 'Abena Owusu', true, false, null, [
                [$organiser, 'Room 1.04 on the ground floor is set aside all day, two minutes from the auditorium doors. Ask any usher in a green lanyard.'],
            ]],
            ['Great session on mobile data', 'The point about bundling assets for 3G was the most useful thing I have heard all week.', 'Selase Kwawu', false, false, 'running', [
                ['Kwabena Asante', 'Agreed. We rolled back an offline-first rewrite last year for exactly the reasons she described.'],
                ['Dzifa Agbeko', 'Does anyone have the link to the connectivity survey she mentioned?'],
            ]],
            ['How does the new NHIA e-claims timeline affect private facilities?', 'We are a 40-bed private hospital in Kumasi. Is the October deadline for everyone or only public facilities?', 'Dr Yaw Boateng', true, false, 'opening', [
                [$organiser, 'Passed to the NHIA panel. Their answer: all accredited facilities, but private facilities under 50 beds have a three-month grace period.'],
                ['Afia Sarpong', 'That grace period is news to us. Very helpful, thank you.'],
            ]],
            ['Any recommendations for an EMR that works offline?', 'Our district clinics lose connectivity for hours. What are people actually using?', 'Mawuli Dogbe', false, false, null, [
                ['Nana Adjei', 'We use an open-source EMR on local servers with nightly sync. It is not glamorous, but it has not lost a record in two years.'],
                ['Ibrahim Seidu', 'Same here. The hard part was training, not the software.'],
                ['Kojo Amoah', 'Would love a session on this next year.'],
            ]],
            ['Parking at the conference centre', 'Is there parking on site, or should we come by ride-share?', 'Araba Quaye', true, false, null, [
                [$organiser, 'The east car park is reserved for delegates; show your ticket QR at the gate. It fills by 8:30, so ride-share is safer after that.'],
            ]],
            ['Will the CPD certificate count toward MDC renewal?', 'I need the points for my licence renewal this year.', 'Dr Efua Hammond', true, false, null, [
                [$organiser, 'Yes. The summit is accredited for 6 CPD points. Your certificate appears in your ticket once attendance is recorded.'],
            ]],
            ['Networking reception tonight: dress code?', 'Coming straight from the sessions. Is business attire fine?', 'Kekeli Addo', false, false, null, []],
        ];

        foreach ($threads as [$title, $body, $author, $answered, $pinned, $session, $replies]) {
            /** @var EventForumThread $thread */
            $thread = EventForumThread::query()->updateOrCreate(
                ['event_id' => $event->id, 'title' => $title],
                [
                    'tenant_id' => $event->tenant_id,
                    'session_id' => $session !== null ? $sessions[$session]->id : null,
                    'body' => $body,
                    'author_name' => $author,
                    // The attendee's own thread stays theirs; the rest are not
                    // mailboxes anyone reads, and nothing is ever sent to them.
                    'author_email' => $author === 'Selase Kwawu' ? $attendeeEmail : null,
                    'is_anonymous' => false,
                    'is_pinned' => $pinned,
                    'is_answered' => $answered,
                    'is_hidden' => false,
                ]
            );

            foreach ($replies as [$replyAuthor, $replyBody]) {
                EventForumReply::query()->updateOrCreate(
                    ['thread_id' => $thread->id, 'body' => $replyBody],
                    ['tenant_id' => $event->tenant_id, 'author_name' => $replyAuthor]
                );
            }
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
