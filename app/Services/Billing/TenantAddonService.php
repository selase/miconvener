<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Contracts\PaymentGateway;
use App\Models\Event;
use App\Models\Tenant;
use App\Models\TenantAddon;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class TenantAddonService
{
    /**
     * @var array<string, array{key: string, name: string, description: string, addon_type: string, unit_price: int, billing_interval: string, quantity: int, category: string, available: bool}>
     *
     * An entry marked unavailable stays listed, so the offer is visible, but it
     * cannot be bought until what it sells is delivered. SMS packs are also off
     * sale while no SMS provider is configured -- see isAvailable().
     */
    public const CATALOG = [
        'team_seat' => [
            'key' => 'team_seat',
            'name' => 'Additional Team Member Seat',
            'description' => 'Full workspace console seat for team coordinators, finance, and event planners.',
            'addon_type' => TenantAddon::TYPE_TEAM_SEAT,
            'unit_price' => 7500, // GHS 75.00
            'billing_interval' => TenantAddon::INTERVAL_MONTHLY,
            'quantity' => 1,
            'category' => 'team',
            'available' => true,
        ],
        'usher_pack' => [
            'key' => 'usher_pack',
            'name' => 'Usher Pack (5 staff links)',
            'description' => 'Five more staff links at a time: ushers and floor crew scan tickets and answer attendee requests from their phones, with no account and no team seat.',
            'addon_type' => TenantAddon::TYPE_USHER_PACK,
            'unit_price' => 6000, // GHS 60.00
            'billing_interval' => TenantAddon::INTERVAL_MONTHLY,
            'quantity' => 1, // 1 pack = 5 passes
            'category' => 'operations',
            'available' => true,
        ],
        'live_polling_monthly' => [
            'key' => 'live_polling_monthly',
            'name' => 'Live Polling & Audience Q&A Module (Monthly)',
            'description' => 'Unlock real-time interactive presenter deck, audience polls, and moderated Q&A across all events.',
            'addon_type' => TenantAddon::TYPE_LIVE_POLLING,
            'unit_price' => 8500, // GHS 85.00
            'billing_interval' => TenantAddon::INTERVAL_MONTHLY,
            'quantity' => 1,
            'category' => 'engagement',
            'available' => true,
        ],
        'live_polling_event_pass' => [
            'key' => 'live_polling_event_pass',
            'name' => 'Live Polling Single-Event Pass',
            'description' => 'Unlock live polling and Q&A for a single scheduled event.',
            'addon_type' => TenantAddon::TYPE_LIVE_POLLING,
            'unit_price' => 5000, // GHS 50.00
            'billing_interval' => TenantAddon::INTERVAL_EVENT_PASS,
            'quantity' => 1,
            'category' => 'engagement',
            'available' => true,
        ],
        'sms_500' => [
            'key' => 'sms_500',
            'name' => 'Prepaid SMS Pack (500 SMS)',
            'description' => 'Send SMS registration confirmations, tickets, and reminders directly to attendees.',
            'addon_type' => TenantAddon::TYPE_SMS_PACK,
            'unit_price' => 3500, // GHS 35.00
            'billing_interval' => TenantAddon::INTERVAL_ONE_OFF,
            'quantity' => 500,
            'category' => 'messaging',
            'available' => true,
        ],
        'sms_1500' => [
            'key' => 'sms_1500',
            'name' => 'Prepaid SMS Pack (1,500 SMS)',
            'description' => 'High-volume SMS pack with discounted per-message rate.',
            'addon_type' => TenantAddon::TYPE_SMS_PACK,
            'unit_price' => 9500, // GHS 95.00
            'billing_interval' => TenantAddon::INTERVAL_ONE_OFF,
            'quantity' => 1500,
            'category' => 'messaging',
            'available' => true,
        ],
        'sms_5000' => [
            'key' => 'sms_5000',
            'name' => 'Prepaid SMS Pack (5,000 SMS)',
            'description' => 'Large conference and summit SMS pack for bulk blasts.',
            'addon_type' => TenantAddon::TYPE_SMS_PACK,
            'unit_price' => 29000, // GHS 290.00
            'billing_interval' => TenantAddon::INTERVAL_ONE_OFF,
            'quantity' => 5000,
            'category' => 'messaging',
            'available' => true,
        ],
        'email_5000' => [
            'key' => 'email_5000',
            'name' => 'Email Blast Pack (5,000 Emails)',
            'description' => 'Additional transactional and marketing email broadcast credits.',
            'addon_type' => TenantAddon::TYPE_EMAIL_PACK,
            'unit_price' => 2500, // GHS 25.00
            'billing_interval' => TenantAddon::INTERVAL_ONE_OFF,
            'quantity' => 5000,
            'category' => 'messaging',
            'available' => true,
        ],
        'email_25000' => [
            'key' => 'email_25000',
            'name' => 'Email Blast Pack (25,000 Emails)',
            'description' => 'Enterprise email capacity for mass newsletters and multi-stage reminders.',
            'addon_type' => TenantAddon::TYPE_EMAIL_PACK,
            'unit_price' => 9000, // GHS 90.00
            'billing_interval' => TenantAddon::INTERVAL_ONE_OFF,
            'quantity' => 25000,
            'category' => 'messaging',
            'available' => true,
        ],
        // Marketplace business add-ons (prices set 2026-10-05). One-off
        // payments for a fixed period; buying again extends from the end.
        'shop_verification' => [
            'key' => 'shop_verification',
            'name' => 'Verified business (1 year)',
            'description' => 'Our team checks your business, including past clients and events you have served. Once approved, your page shows a verified tick for a year.',
            'addon_type' => TenantAddon::TYPE_SHOP_VERIFICATION,
            'unit_price' => 15000, // GHS 150.00
            'billing_interval' => TenantAddon::INTERVAL_YEARLY,
            'quantity' => 1,
            'category' => 'marketplace',
            'available' => true,
        ],
        'shop_boost' => [
            'key' => 'shop_boost',
            'name' => 'Boost in search (1 week)',
            'description' => 'Your listings appear first in marketplace search results for a week.',
            'addon_type' => TenantAddon::TYPE_SHOP_BOOST,
            'unit_price' => 5000, // GHS 50.00
            'billing_interval' => TenantAddon::INTERVAL_WEEKLY,
            'quantity' => 1,
            'category' => 'marketplace',
            'available' => true,
        ],
        'shop_featured' => [
            'key' => 'shop_featured',
            'name' => 'Featured on the marketplace (1 month)',
            'description' => 'Your listings lead the marketplace home page, marked Featured, for a month.',
            'addon_type' => TenantAddon::TYPE_SHOP_FEATURED,
            'unit_price' => 20000, // GHS 200.00
            'billing_interval' => TenantAddon::INTERVAL_MONTHLY,
            'quantity' => 1,
            'category' => 'marketplace',
            'available' => true,
        ],
    ];

    public function __construct(private readonly PaymentGateway $gateway) {}

    /**
     * The catalog keys that can be bought today.
     *
     * @return list<string>
     */
    public function purchasableKeys(): array
    {
        return array_keys(array_filter(self::CATALOG, fn (string $key): bool => $this->isAvailable($key), ARRAY_FILTER_USE_KEY));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCatalog(): array
    {
        return array_values(array_map(function (array $item): array {
            return array_merge($item, [
                'available' => $this->isAvailable($item['key']),
                'formatted_price' => number_format($item['unit_price'] / 100, 2),
            ]);
        }, self::CATALOG));
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{checkout_url: string, reference: string}
     */
    public function initializeCheckout(Tenant $tenant, User $user, string $addonKey, array $options = []): array
    {
        if (! isset(self::CATALOG[$addonKey])) {
            throw new InvalidArgumentException("Unknown add-on product [{$addonKey}].");
        }

        if (! in_array($addonKey, $this->purchasableKeys(), true)) {
            throw new InvalidArgumentException("The add-on [{$addonKey}] is not on sale yet.");
        }

        $config = self::CATALOG[$addonKey];
        $multiplier = $config['addon_type'] === TenantAddon::TYPE_LIVE_POLLING
            ? 1
            : max(1, (int) ($options['multiplier'] ?? 1));

        $eventId = null;
        if ($config['billing_interval'] === TenantAddon::INTERVAL_EVENT_PASS) {
            $eventId = $options['event_id'] ?? null;
            if (! $eventId) {
                throw new InvalidArgumentException('An event must be selected for single-event passes.');
            }
            Event::where('tenant_id', $tenant->id)->where('id', $eventId)->firstOrFail();
        }

        $totalPricePesewas = $config['unit_price'] * $multiplier;
        $totalQuantity = $config['quantity'] * $multiplier;
        $reference = 'ADDON-'.mb_strtoupper(Str::random(12));

        // Dev bypass: immediately fulfill without calling external gateway
        if (config('services.payment.dev_bypass', false)) {
            $this->fulfillAddonPurchase($tenant, $reference, [
                'addon_key' => $addonKey,
                'multiplier' => $multiplier,
                'quantity' => $totalQuantity,
                'event_id' => $eventId,
                'total_price' => $totalPricePesewas,
            ]);

            return [
                'checkout_url' => route('billing.addons.index', ['subdomain' => $tenant->slug]),
                'reference' => $reference,
            ];
        }

        $customerId = $this->getOrCreateCustomerId($user, $tenant);

        $checkoutUrl = $this->gateway->createOneTimeCheckoutSession(
            $customerId,
            $totalPricePesewas,
            (string) config('services.paystack.currency', 'GHS'),
            route('billing.callback'),
            [
                'type' => 'tenant_addon',
                'source' => config('services.paystack.metadata_source'),
                'tenant_id' => $tenant->id,
                'addon_key' => $addonKey,
                'multiplier' => $multiplier,
                'quantity' => $totalQuantity,
                'event_id' => $eventId,
                'total_price' => $totalPricePesewas,
            ]
        );

        return [
            'checkout_url' => $checkoutUrl,
            'reference' => $reference,
        ];
    }

    /**
     * Fulfill an addon purchase atomically and idempotently.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function fulfillAddonPurchase(Tenant $tenant, string $reference, array $metadata): ?TenantAddon
    {
        if (blank($reference)) {
            Log::error("Cannot fulfill addon purchase with empty reference for tenant [{$tenant->id}]");

            return null;
        }

        if (! empty($metadata['tenant_id']) && $metadata['tenant_id'] !== $tenant->id) {
            Log::error("Addon purchase tenant mismatch: expected {$metadata['tenant_id']}, got {$tenant->id}");

            return null;
        }

        $existing = TenantAddon::where('paystack_reference', $reference)->first();
        if ($existing) {
            return $existing;
        }

        $addonKey = (string) ($metadata['addon_key'] ?? '');
        if (! isset(self::CATALOG[$addonKey])) {
            Log::error("Cannot fulfill unknown addon [{$addonKey}] for tenant [{$tenant->id}]");

            return null;
        }

        $config = self::CATALOG[$addonKey];
        $multiplier = max(1, (int) ($metadata['multiplier'] ?? 1));
        $quantity = (int) ($metadata['quantity'] ?? ($config['quantity'] * $multiplier));
        $totalPrice = (int) ($metadata['total_price'] ?? ($config['unit_price'] * $multiplier));
        $eventId = ! empty($metadata['event_id']) ? (string) $metadata['event_id'] : null;

        try {
            return $this->activate($tenant, $reference, $config, $quantity, $totalPrice, $eventId, []);
        } catch (UniqueConstraintViolationException) {
            return TenantAddon::where('paystack_reference', $reference)->first();
        }
    }

    /**
     * Give an organisation an add-on without charging for it: goodwill
     * credits, a launch partner's promotion. It works exactly like a bought
     * one, but no payment is recorded (so it is not counted as earnings), and
     * who granted it and why are kept on the add-on.
     */
    public function grantAddon(Tenant $tenant, string $addonKey, int $packs, User $grantedBy, string $reason): TenantAddon
    {
        if (! isset(self::CATALOG[$addonKey])) {
            throw new InvalidArgumentException("Unknown add-on [{$addonKey}].");
        }

        $config = self::CATALOG[$addonKey];
        $packs = max(1, $packs);

        $addon = $this->activate($tenant, 'grant_'.Str::uuid(), $config, $config['quantity'] * $packs, 0, null, [
            'granted_by' => $grantedBy->id,
            'granted_by_name' => $grantedBy->displayName(),
            'grant_reason' => $reason,
        ]);

        activity()
            ->causedBy($grantedBy)
            ->performedOn($tenant)
            ->withProperties(['addon' => $config['name'], 'quantity' => $addon->quantity, 'reason' => $reason])
            ->log("Granted {$config['name']} to {$tenant->name}");

        return $addon;
    }

    public function cancelAddon(Tenant $tenant, TenantAddon $addon): bool
    {
        if ($addon->tenant_id !== $tenant->id) {
            abort(403, 'Unauthorized.');
        }

        if (! in_array($addon->billing_interval, [TenantAddon::INTERVAL_MONTHLY, TenantAddon::INTERVAL_YEARLY], true)) {
            abort(422, 'Only recurring add-ons can be cancelled.');
        }

        if ($addon->status === TenantAddon::STATUS_CANCELLED) {
            return true;
        }

        $addon->update([
            'status' => TenantAddon::STATUS_CANCELLED,
        ]);

        return true;
    }

    /**
     * Create the active add-on. A paid one ($totalPrice > 0) also records the
     * payment to MiConvener; a grant records none.
     *
     * @param  array<string, mixed>  $config  a CATALOG entry
     * @param  array<string, mixed>  $extraMeta
     */
    private function activate(Tenant $tenant, string $reference, array $config, int $quantity, int $totalPrice, ?string $eventId, array $extraMeta): TenantAddon
    {
        // A business buying more of the same promotion extends it: the new
        // period starts when the current one ends, not alongside it.
        $periodStart = Carbon::now();
        if (in_array($config['addon_type'], TenantAddon::SHOP_TYPES, true)) {
            $currentEnd = TenantAddon::query()
                ->where('tenant_id', $tenant->id)
                ->active()
                ->ofType($config['addon_type'])
                ->max('period_end');

            if ($currentEnd !== null && Carbon::parse($currentEnd)->isFuture()) {
                $periodStart = Carbon::parse($currentEnd);
            }
        }

        $periodEnd = match ($config['billing_interval']) {
            TenantAddon::INTERVAL_WEEKLY => $periodStart->copy()->addWeek(),
            TenantAddon::INTERVAL_MONTHLY => $periodStart->copy()->addMonth(),
            TenantAddon::INTERVAL_YEARLY => $periodStart->copy()->addYear(),
            TenantAddon::INTERVAL_EVENT_PASS => Carbon::now()->addDays(7),
            default => null, // One-off credit packs stay active indefinitely
        };

        return DB::connection('landlord')->transaction(function () use (
            $tenant,
            $reference,
            $config,
            $quantity,
            $totalPrice,
            $eventId,
            $periodStart,
            $periodEnd,
            $extraMeta
        ): TenantAddon {
            $addon = TenantAddon::create([
                'tenant_id' => $tenant->id,
                'addon_type' => $config['addon_type'],
                'name' => $config['name'],
                'quantity' => $quantity,
                'unit_price' => $config['unit_price'],
                'total_price' => $totalPrice,
                'billing_interval' => $config['billing_interval'],
                'status' => TenantAddon::STATUS_ACTIVE,
                'event_id' => $eventId,
                'paystack_reference' => $reference,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'meta' => [
                    'catalog_key' => $config['key'],
                    ...$extraMeta,
                ],
            ]);

            if ($totalPrice <= 0) {
                Log::info("Granted addon [{$addon->name}] to tenant [{$tenant->id}] via reference [{$reference}].");

                return $addon;
            }

            // Record double-entry transaction record
            Transaction::create([
                'tenant_id' => $tenant->id,
                'amount' => $totalPrice,
                'currency' => mb_strtolower((string) config('services.paystack.currency', 'ghs')),
                'status' => 'success',
                'type' => 'charge',
                'provider' => config('services.payment.default', 'paystack'),
                'provider_transaction_id' => $reference,
                'meta' => [
                    'type' => 'tenant_addon',
                    'addon_id' => $addon->id,
                    'addon_type' => $addon->addon_type,
                    'addon_name' => $addon->name,
                ],
            ]);

            Log::info("Provisioned addon [{$addon->name}] for tenant [{$tenant->id}] via reference [{$reference}].");

            return $addon;
        });
    }

    private function isAvailable(string $key): bool
    {
        $item = self::CATALOG[$key];

        if ($item['addon_type'] === TenantAddon::TYPE_SMS_PACK && ! app(\App\Contracts\SmsGateway::class)->isConfigured()) {
            return false;
        }

        // Only a business with a marketplace shop has anything to promote.
        if (in_array($item['addon_type'], TenantAddon::SHOP_TYPES, true)) {
            $tenant = app(\App\Services\Tenancy\TenantContext::class)->getTenant();

            if ($tenant === null || $tenant->shop === null) {
                return false;
            }
        }

        return $item['available'];
    }

    private function getOrCreateCustomerId(User $user, Tenant $tenant): string
    {
        $driver = config('services.payment.default', 'paystack');
        $metaKey = "{$driver}_id";
        $customerId = $tenant->meta[$metaKey] ?? null;

        if (! $customerId) {
            $customerId = $this->gateway->createCustomer($user->email, $tenant->name);
            $meta = $tenant->meta ?? [];
            $meta[$metaKey] = $customerId;
            $tenant->update(['meta' => $meta]);
        }

        return $customerId;
    }
}
