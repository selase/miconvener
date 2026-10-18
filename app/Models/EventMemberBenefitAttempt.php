<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $quote_id
 * @property string $registration_id
 * @property string $provider
 * @property string $account_key
 * @property string $settlement_mode
 * @property string $reference
 * @property string $currency
 * @property string $state
 * @property string|null $gateway_id
 * @property string|null $provider_session_id
 * @property string|null $provider_reference
 * @property string|null $checkout_url
 * @property string|null $review_reason
 * @property string|null $refund_operation_key
 * @property string|null $refund_reference
 * @property int $amount
 * @property int $charged_amount
 * @property int $platform_fee_amount
 * @property int|null $gateway_fee_amount
 * @property int|null $refund_gateway_credit
 * @property int|null $refund_commission_returned
 * @property bool $is_extra_capture
 * @property array<string, mixed>|null $commission_settlement
 * @property array<string, mixed>|null $observed_payment
 * @property \Carbon\CarbonImmutable|null $paid_at
 * @property \Carbon\CarbonImmutable|null $fulfilled_at
 * @property \Carbon\CarbonImmutable|null $refunded_at
 * @property \Carbon\CarbonImmutable|null $booked_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class EventMemberBenefitAttempt extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\EventMemberBenefitAttemptFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $attributes = ['is_extra_capture' => false];

    protected $fillable = ['is_extra_capture', 'tenant_id', 'quote_id', 'registration_id', 'provider', 'account_key', 'gateway_id', 'settlement_mode', 'reference', 'provider_session_id', 'provider_reference', 'amount', 'charged_amount', 'platform_fee_amount', 'gateway_fee_amount', 'currency', 'commission_settlement', 'checkout_url', 'state', 'review_reason', 'paid_at', 'fulfilled_at', 'refunded_at', 'booked_at', 'observed_payment', 'refund_operation_key', 'refund_reference', 'refund_gateway_credit', 'refund_commission_returned'];

    protected $hidden = ['observed_payment', 'refund_operation_key', 'account_key', 'gateway_id', 'commission_settlement', 'checkout_url'];

    protected $casts = ['is_extra_capture' => 'boolean', 'booked_at' => 'immutable_datetime', 'observed_payment' => 'array', 'refund_gateway_credit' => 'integer', 'refund_commission_returned' => 'integer', 'amount' => 'integer', 'charged_amount' => 'integer', 'platform_fee_amount' => 'integer', 'gateway_fee_amount' => 'integer', 'commission_settlement' => 'array', 'checkout_url' => 'encrypted', 'paid_at' => 'immutable_datetime', 'fulfilled_at' => 'immutable_datetime', 'refunded_at' => 'immutable_datetime'];

    /** @return BelongsTo<EventMemberBenefitQuote, $this> */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(EventMemberBenefitQuote::class, 'quote_id');
    }

    /** @return BelongsTo<EventRegistration, $this> */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(EventRegistration::class, 'registration_id');
    }
}
