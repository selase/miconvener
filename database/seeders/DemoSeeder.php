<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enum\TenantStatusEnum;
use App\Models\Event;
use App\Models\EventPayout;
use App\Models\EventRegistration;
use App\Models\EventSession;
use App\Models\EventSpeaker;
use App\Models\EventTicketType;
use App\Models\Package;
use App\Models\Role;
use App\Models\Speaker;
use App\Models\Tenant;
use App\Models\TenantPayoutAccount;
use App\Models\User;
use App\Services\Finance\FeeCalculator;
use App\Services\Finance\LedgerService;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A believable organization for sales demos: Kente Events, running an upcoming
 * paid conference, a free meetup, and a past summit that was checked in and
 * paid out. Every figure goes through the same fee and ledger code as real
 * sales, so the finance pages add up.
 *
 * Runs only on the demo environment (DEMO_MODE=true) or locally, and only once:
 * an existing demo organization is left untouched.
 */
final class DemoSeeder extends Seeder
{
    public const string TENANT_SLUG = 'kente-events';

    /** @var list<string> */
    private const array FIRST_NAMES = ['Ama', 'Kofi', 'Akosua', 'Kwame', 'Abena', 'Yaw', 'Efua', 'Kojo', 'Adwoa', 'Kwabena', 'Esi', 'Kwesi', 'Afua', 'Nana', 'Aba', 'Fiifi', 'Maame', 'Selorm', 'Dzifa', 'Edem', 'Aisha', 'Ibrahim', 'Fatima', 'Yusuf'];

    /** @var list<string> */
    private const array LAST_NAMES = ['Mensah', 'Owusu', 'Boateng', 'Asante', 'Osei', 'Agyeman', 'Appiah', 'Darko', 'Addo', 'Quaye', 'Tetteh', 'Amoah', 'Ofori', 'Adjei', 'Kwarteng', 'Nkrumah', 'Agbeko', 'Dogbe', 'Abdulai', 'Sulemana'];

    public function run(): void
    {
        if (! config('app.demo') && ! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('DemoSeeder only runs on the demo environment (DEMO_MODE=true).');
        }

        if (Tenant::query()->where('slug', self::TENANT_SLUG)->exists()) {
            $this->command?->info('Demo organization already exists; nothing to do.');

            return;
        }

        $password = config('app.demo_owner_password');

        if (blank($password)) {
            throw new RuntimeException('Set DEMO_OWNER_PASSWORD before seeding the demo.');
        }

        mt_srand(2026);

        $growth = Package::query()->where('slug', 'growth')->firstOrFail();
        $tenant = Tenant::query()->create([
            'name' => 'Kente Events',
            'slug' => self::TENANT_SLUG,
            'email' => 'hello@kente-events.example',
            'status' => TenantStatusEnum::ACTIVE,
            'isolation_mode' => 'shared',
            'db_driver' => 'pgsql',
            'package_id' => $growth->id,
            'country' => 'Ghana',
            'city' => 'Accra',
            'onboarding_completed_at' => now(),
        ]);
        $tenant->forceFill(['billing_complimentary' => true])->save();
        $tenant->syncFeaturesFromPackage();
        app(TenantContext::class)->setTenant($tenant);

        $owner = $this->member($tenant, 'Esi', 'Owusu', (string) config('app.demo_owner_email'), 'Org Superadmin', (string) $password);
        $this->member($tenant, 'Kwame', 'Asante', 'kwame@kente-events.example', 'Org Admin', Str::random(40));

        $summit = $this->event($tenant, $owner, 'Accra Tech Summit 2026', 'The largest gathering of builders, founders and investors in West Africa: two days of talks, workshops and deals.', now()->addDays(24)->setTime(9, 0), 2, 'Accra International Conference Centre');
        $tiers = [
            $this->ticket($summit, 'Early bird', 25000, 150, EventTicketType::TIER_GENERAL, 0),
            $this->ticket($summit, 'Regular', 40000, 300, EventTicketType::TIER_GENERAL, 1),
            $this->ticket($summit, 'VIP', 120000, 40, EventTicketType::TIER_VIP, 2),
        ];
        $this->programme($tenant, $summit);
        $this->sell($tenant, $summit, [[$tiers[0], 118], [$tiers[1], 64], [$tiers[2], 11]], checkedInShare: 0, pending: 9);

        $meetup = $this->event($tenant, $owner, 'Women in Product: Accra Meetup', 'An evening of lightning talks and mentoring for women building products in Ghana.', now()->addDays(9)->setTime(17, 30), 0, 'Impact Hub Accra');
        $this->registerFree($tenant, $meetup, 46);

        $past = $this->event($tenant, $owner, 'Kumasi Founders Forum 2026', 'A day for founders outside Accra to meet investors and each other.', now()->subDays(21)->setTime(9, 0), 0, 'Golden Tulip Kumasi City');
        $pastTier = $this->ticket($past, 'General admission', 15000, 200, EventTicketType::TIER_GENERAL, 0);
        $this->sell($tenant, $past, [[$pastTier, 87]], checkedInShare: 0.82, pending: 0);
        $this->payOut($tenant, $past);

        $this->command?->info("Seeded Kente Events at {$tenant->slug}. Sign in as ".config('app.demo_owner_email').'.');
    }

    private function member(Tenant $tenant, string $first, string $last, string $email, string $role, string $password): User
    {
        $user = User::query()->create([
            'first_name' => $first,
            'last_name' => $last,
            'email' => mb_strtolower($email),
            'password' => Hash::make($password),
            'tenant_id' => $tenant->id,
            'email_verified_at' => now(),
        ]);
        $tenant->users()->attach($user->id);
        setPermissionsTeamId($tenant->id);
        $user->assignRole(Role::query()->where('name', $role)->whereNull('tenant_id')->firstOrFail());

        return $user;
    }

    private function event(Tenant $tenant, User $owner, string $name, string $description, CarbonImmutable|\Illuminate\Support\Carbon $startsAt, int $extraDays, string $venue): Event
    {
        $startsAt = CarbonImmutable::parse($startsAt);

        return Event::query()->create([
            'tenant_id' => $tenant->id,
            'created_by' => $owner->id,
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => $description,
            'status' => Event::STATUS_PUBLISHED,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addDays($extraDays)->setTime(18, 0),
            'timezone' => 'Africa/Accra',
            'location_type' => Event::LOCATION_IN_PERSON,
            'address' => $venue.', Ghana',
            'ticket_price' => 0,
            'currency' => 'GHS',
        ]);
    }

    private function ticket(Event $event, string $name, int $price, int $capacity, string $tier, int $sort): EventTicketType
    {
        return EventTicketType::query()->create([
            'tenant_id' => $event->tenant_id,
            'event_id' => $event->id,
            'name' => $name,
            'price' => $price,
            'capacity' => $capacity,
            'badge_tier' => $tier,
            'is_active' => true,
            'sort_order' => $sort,
        ]);
    }

    private function programme(Tenant $tenant, Event $event): void
    {
        $speakers = collect([
            ['Dr. Adwoa Agyeman', 'Chief Executive', 'Fintech Association of Ghana'],
            ['Selorm Dogbe', 'Head of Engineering', 'Hubtel'],
            ['Yusuf Abdulai', 'Partner', 'Savannah Ventures'],
            ['Maame Tetteh', 'Product Director', 'mPharma'],
        ])->map(fn (array $person, int $index): Speaker => tap(Speaker::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $person[0],
            'title' => $person[1],
            'organization' => $person[2],
            'bio' => "{$person[0]} leads {$person[2]}'s work on building for African markets.",
        ]), fn (Speaker $speaker) => EventSpeaker::query()->create([
            'tenant_id' => $tenant->id,
            'event_id' => $event->id,
            'speaker_id' => $speaker->id,
            'role' => $index === 0 ? 'keynote' : 'speaker',
            'sort_order' => $index,
            'is_confirmed' => true,
        ])));

        $day = CarbonImmutable::parse($event->starts_at);

        foreach ([
            ['Opening keynote: the next ten years of African tech', 0, 60, 'Main hall'],
            ['Payments that work: lessons from mobile money', 75, 45, 'Main hall'],
            ['Workshop: raising your seed round', 135, 90, 'Room B'],
            ['Panel: building healthcare products people trust', 240, 60, 'Main hall'],
        ] as $index => [$title, $offset, $minutes, $room]) {
            EventSession::query()->create([
                'tenant_id' => $tenant->id,
                'event_id' => $event->id,
                'title' => $title,
                'starts_at' => $day->addMinutes($offset),
                'ends_at' => $day->addMinutes($offset + $minutes),
                'location' => $room,
                'type' => str_starts_with($title, 'Workshop') ? EventSession::TYPE_WORKSHOP : 'session',
                'sort_order' => $index,
            ]);
        }
    }

    /**
     * @param  list<array{0: EventTicketType, 1: int}>  $sales
     */
    private function sell(Tenant $tenant, Event $event, array $sales, float $checkedInShare, int $pending): void
    {
        $fees = app(FeeCalculator::class);
        $ledger = app(LedgerService::class);

        foreach ($sales as [$tier, $count]) {
            for ($i = 0; $i < $count; $i++) {
                $breakdown = $fees->for($event, $tier->price);
                $gatewayFee = (int) round($breakdown->chargedAmount * 0.0195);
                $reference = 'DEMO'.mb_strtoupper(Str::random(12));
                $checkedIn = mt_rand() / mt_getrandmax() < $checkedInShare;

                $registration = $this->attendee($tenant, $event, [
                    'ticket_type_id' => $tier->id,
                    'status' => $checkedIn ? EventRegistration::STATUS_CHECKED_IN : EventRegistration::STATUS_CONFIRMED,
                    'amount' => $tier->price,
                    'platform_fee_amount' => $breakdown->platformFee,
                    'charged_amount' => $breakdown->chargedAmount,
                    'gateway_fee_amount' => $gatewayFee,
                    'payment_reference' => $reference,
                    'checked_in_at' => $checkedIn ? CarbonImmutable::parse($event->starts_at)->addMinutes(mt_rand(0, 90)) : null,
                    'created_at' => now()->subDays(mt_rand(1, 30)),
                ]);

                $ledger->recordTicketSale($event, $registration, $breakdown->chargedAmount, $breakdown->platformFee, $reference, $gatewayFee, 'paystack', $reference);
            }
        }

        for ($i = 0; $i < $pending; $i++) {
            $this->attendee($tenant, $event, [
                'ticket_type_id' => $sales[0][0]->id,
                'status' => EventRegistration::STATUS_PENDING_PAYMENT,
                'amount' => $sales[0][0]->price,
                'ticket_code' => null,
                'qr_token' => null,
            ]);
        }
    }

    private function registerFree(Tenant $tenant, Event $event, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->attendee($tenant, $event, ['status' => EventRegistration::STATUS_CONFIRMED, 'amount' => 0, 'created_at' => now()->subDays(mt_rand(1, 14))]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function attendee(Tenant $tenant, Event $event, array $attributes): EventRegistration
    {
        $first = self::FIRST_NAMES[mt_rand(0, count(self::FIRST_NAMES) - 1)];
        $last = self::LAST_NAMES[mt_rand(0, count(self::LAST_NAMES) - 1)];

        return EventRegistration::query()->create($attributes + [
            'tenant_id' => $tenant->id,
            'event_id' => $event->id,
            'full_name' => "{$first} {$last}",
            'first_name' => $first,
            'last_name' => $last,
            'email' => mb_strtolower("{$first}.{$last}.".Str::random(4).'@example.com'),
            'phone' => '+23324'.mt_rand(1000000, 9999999),
            'ticket_code' => 'EVT-'.mb_strtoupper(Str::random(4)).'-'.mt_rand(100, 999),
            'qr_token' => bin2hex(random_bytes(16)),
            'currency' => 'GHS',
        ]);
    }

    private function payOut(Tenant $tenant, Event $event): void
    {
        $net = (int) $event->ledgerEntries()->sum('net_amount');
        $transferFee = 100;
        $reference = 'DEMO-PAYOUT-'.mb_strtoupper(Str::random(8));

        $account = TenantPayoutAccount::query()->create([
            'tenant_id' => $tenant->id,
            'type' => 'mobile_money',
            'bank_code' => 'MTN',
            'label' => 'Kente Events MoMo',
            'account_name' => 'Kente Events',
            'account_number_encrypted' => '0240000000',
            'is_verified' => true,
            'resolved_account_name' => 'KENTE EVENTS',
        ]);

        $payout = EventPayout::query()->create([
            'tenant_id' => $tenant->id,
            'event_id' => $event->id,
            'payout_account_id' => $account->id,
            'amount' => $net,
            'transfer_fee_amount' => $transferFee,
            'net_paid_amount' => $net - $transferFee,
            'status' => EventPayout::STATUS_PAID,
            'paid_at' => now()->subDays(18),
            'note' => 'Settlement for Kumasi Founders Forum',
            'provider_reference' => $reference,
        ]);

        app(LedgerService::class)->recordPayout($event, $net, $reference, $payout, $transferFee);
    }
}
