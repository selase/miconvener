<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\StoreOrder;
use App\Models\StorePaymentObservation;
use Illuminate\Validation\ValidationException;

final readonly class CommerceRefundAllocation
{
    public function __construct(
        public int $sellerDebitPesewas,
        public int $commissionReturnPesewas,
        public int $reviewLiabilityPesewas,
        public string $feeBearer,
        public bool $restock,
    ) {}

    public static function merchant(StoreOrder $order, StorePaymentObservation $capture, bool $sale): self
    {
        $bearer = $order->terms_snapshot['refund_gateway_fee_bearer'] ?? null;
        if (! in_array($bearer, ['seller', 'platform'], true)) {
            throw ValidationException::withMessages(['refund' => 'Refund funding ownership must be approved before a refund.']);
        }
        if ($capture->amount_pesewas === null || $capture->gateway_fee_pesewas === null) {
            throw ValidationException::withMessages(['refund' => 'Verify the captured amount and processing fee first.']);
        }
        $amount = $capture->amount_pesewas;
        $commission = $sale ? $order->platform_fee_pesewas : 0;
        $sellerDebit = $sale ? $amount - $commission - ($bearer === 'platform' ? $capture->gateway_fee_pesewas : 0) : ($bearer === 'seller' ? $capture->gateway_fee_pesewas : 0);

        return new self($sellerDebit, $commission, $sale ? 0 : $amount, $bearer, $sale);
    }
}
