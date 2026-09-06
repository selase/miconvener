<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\Transaction;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fulfilment for one-off payments, shared by the redirect callback and the
 * payment webhook.
 *
 * Both routes must be able to fulfil the same payment. The browser returning
 * from the provider is not guaranteed -- a mobile money payment is approved on
 * the customer's phone and the tab is often never brought back -- so relying on
 * the redirect alone means the money is taken and nothing is delivered. The
 * webhook always arrives, but may arrive before, after, or instead of the
 * redirect. Every method here is therefore idempotent and reports whether it
 * was the one that actually did the work.
 */
final class PaymentFulfillmentService
{
    /**
     * Credit a token pack once, keyed on the provider's transaction reference.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function fulfillLlmTokenPurchase(Tenant $tenant, string $reference, array $metadata): bool
    {
        $packKey = (string) ($metadata['pack_key'] ?? '');
        $packs = Config::get('llm.token_packs', []);

        if (! isset($packs[$packKey])) {
            Log::error("Invalid token pack {$packKey} on fulfillment for tenant {$tenant->id}");

            return false;
        }

        $pack = $packs[$packKey];
        $tokens = (int) $pack['tokens'];

        return DB::connection('landlord')->transaction(function () use ($tenant, $reference, $packKey, $pack, $tokens): bool {
            if ($this->alreadyRecorded($reference)) {
                return false;
            }

            DB::connection('landlord')->table('tenants')
                ->where('id', $tenant->id)
                ->increment('llm_topup_balance', $tokens);

            Transaction::create([
                'tenant_id' => $tenant->id,
                'amount' => (int) round((float) $pack['price'] * 100),
                'currency' => $pack['currency'],
                'status' => 'success',
                'type' => 'credit',
                'provider' => config('services.payment.default', 'paystack'),
                'provider_transaction_id' => $reference,
                'meta' => [
                    'type' => 'llm_token_purchase',
                    'pack_key' => $packKey,
                    'tokens' => $tokens,
                ],
            ]);

            return true;
        });
    }

    /**
     * Mark an invoice paid once. The invoice's own status is the idempotency
     * key, so a redelivered webhook cannot record a second payment against it.
     */
    public function fulfillInvoice(Tenant $tenant, string $invoiceId, string $reference, int $amountMinor, string $currency): bool
    {
        return DB::connection('landlord')->transaction(function () use ($tenant, $invoiceId, $reference, $amountMinor, $currency): bool {
            $invoice = Invoice::query()
                ->where('tenant_id', $tenant->id)
                ->whereKey($invoiceId)
                ->lockForUpdate()
                ->first();

            if (! $invoice) {
                Log::warning("Invoice {$invoiceId} not found for tenant {$tenant->id} during fulfillment");

                return false;
            }

            if ($invoice->status === Invoice::STATUS_PAID) {
                return false;
            }

            $invoice->update([
                'status' => Invoice::STATUS_PAID,
                'paid_at' => now(),
            ]);

            Transaction::create([
                'tenant_id' => $tenant->id,
                'amount' => $amountMinor,
                'currency' => $currency,
                'status' => 'success',
                'type' => 'charge',
                'provider' => config('services.payment.default', 'paystack'),
                'provider_transaction_id' => $reference,
                'meta' => [
                    'type' => 'invoice_payment',
                    'invoice_id' => $invoiceId,
                ],
            ]);

            return true;
        });
    }

    private function alreadyRecorded(string $reference): bool
    {
        return $reference !== '' && Transaction::query()
            ->where('provider_transaction_id', $reference)
            ->exists();
    }
}
