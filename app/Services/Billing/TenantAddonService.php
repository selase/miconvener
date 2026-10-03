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
     * @var array<string, array{name: string, description: string, addon_type: string, unit_price: int, billing_interval: string, quantity: int, category: string}>
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
        ],
        'usher_pack' => [
            'key' => 'usher_pack',
            'name' => 'Event Day Usher / Scanner Pack (5 passes)',
            'description' => 'Dedicated check-in staff passes for QR ticket scanning and attendance tracking.',
            'addon_type' => TenantAddon::TYPE_USHER_PACK,
            'unit_price' => 6000, // GHS 60.00
            'billing_interval' => TenantAddon::INTERVAL_MONTHLY,
            'quantity' => 1, // 1 pack = 5 passes
            'category' => 'operations',
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
        ],
    ];

    public function __construct(private readonly PaymentGateway $gateway) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCatalog(): array
    {
        return array_values(array_map(function (array $item): array {
            return array_merge($item, [
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

        $periodStart = Carbon::now();
        $periodEnd = match ($config['billing_interval']) {
            TenantAddon::INTERVAL_MONTHLY => Carbon::now()->addMonth(),
            TenantAddon::INTERVAL_YEARLY => Carbon::now()->addYear(),
            TenantAddon::INTERVAL_EVENT_PASS => Carbon::now()->addDays(7),
            default => null, // One-off credit packs stay active indefinitely
        };

        try {
            return DB::connection('landlord')->transaction(function () use (
                $tenant,
                $reference,
                $config,
                $quantity,
                $totalPrice,
                $eventId,
                $periodStart,
                $periodEnd
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
                    ],
                ]);

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
        } catch (UniqueConstraintViolationException) {
            return TenantAddon::where('paystack_reference', $reference)->first();
        }
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
