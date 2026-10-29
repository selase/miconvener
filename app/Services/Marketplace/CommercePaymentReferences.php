<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\CommercePaymentReference;
use App\Models\LedgerTransaction;
use App\Models\StoreOrder;
use App\Models\VenueBooking;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Decides which single source owns a provider payment reference. A reference is
 * claimed once; claiming it again for the same source is a no-op and for any
 * other source is refused. Money already posted or held under the older venue
 * payment path can never be claimed as a new sale.
 */
final class CommercePaymentReferences
{
    public function claimForOrder(StoreOrder $order, string $reference, string $origin = 'checkout'): CommercePaymentReference
    {
        if ($this->postedByLegacyVenuePath($reference)) {
            throw new RuntimeException('This provider reference was already taken by a legacy venue booking payment and needs staff reconciliation.');
        }

        return $this->claim($order->provider, $order->account_fingerprint, $reference, [
            'tenant_id' => $order->tenant_id, 'shop_id' => $order->shop_id,
            'source_type' => CommercePaymentReference::SOURCE_ORDER, 'order_id' => $order->id, 'origin' => $origin,
        ]);
    }

    public function claimForLegacyBooking(VenueBooking $booking, string $provider, string $accountFingerprint, string $reference): CommercePaymentReference
    {
        return $this->claim($provider, $accountFingerprint, $reference, [
            'tenant_id' => $booking->tenant_id, 'shop_id' => $booking->shop_id,
            'source_type' => CommercePaymentReference::SOURCE_LEGACY_BOOKING, 'booking_id' => $booking->id, 'origin' => 'legacy_reconciliation',
        ]);
    }

    /**
     * Record the first ledger entry that posted a claimed reference.
     */
    public function linkLedger(CommercePaymentReference $claim, string $ledgerReference): CommercePaymentReference
    {
        if ($claim->ledger_reference === $ledgerReference) {
            return $claim;
        }

        $claim->update(['ledger_reference' => $ledgerReference]);

        return $claim;
    }

    /**
     * Whether the older venue payment path has already taken or posted this reference.
     */
    public function postedByLegacyVenuePath(string $reference): bool
    {
        $escaped = addcslashes($reference, '\\%_');

        return VenueBooking::query()->where('paystack_reference', $reference)->exists()
            || LedgerTransaction::query()->where('reference', 'like', "MKT-%-{$escaped}")->exists();
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function claim(string $provider, string $accountFingerprint, string $reference, array $source): CommercePaymentReference
    {
        $reference = mb_trim($reference);
        if ($reference === '') {
            throw new RuntimeException('A provider payment reference is required.');
        }

        return DB::connection('landlord')->transaction(function () use ($provider, $accountFingerprint, $reference, $source): CommercePaymentReference {
            $key = ['provider' => $provider, 'account_fingerprint' => $accountFingerprint, 'reference' => $reference];

            DB::connection('landlord')->table('commerce_payment_references')->insertOrIgnore([
                'id' => (string) Str::uuid(), ...$key, ...$source,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $claim = CommercePaymentReference::query()->where($key)->lockForUpdate()->firstOrFail();
            foreach (['source_type', 'order_id', 'booking_id'] as $field) {
                if ($claim->{$field} !== ($source[$field] ?? null)) {
                    throw new RuntimeException('This provider reference already belongs to another payment source.');
                }
            }

            return $claim;
        });
    }
}
