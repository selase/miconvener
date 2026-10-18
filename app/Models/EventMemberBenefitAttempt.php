<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EventMemberBenefitAttempt extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\EventMemberBenefitAttemptFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = ['tenant_id', 'quote_id', 'registration_id', 'provider', 'account_key', 'gateway_id', 'settlement_mode', 'reference', 'provider_session_id', 'provider_reference', 'amount', 'charged_amount', 'platform_fee_amount', 'gateway_fee_amount', 'currency', 'commission_settlement', 'checkout_url', 'state', 'review_reason', 'paid_at', 'fulfilled_at', 'refunded_at'];

    protected $hidden = ['account_key', 'gateway_id', 'commission_settlement', 'checkout_url'];

    protected $casts = ['amount' => 'integer', 'charged_amount' => 'integer', 'platform_fee_amount' => 'integer', 'gateway_fee_amount' => 'integer', 'commission_settlement' => 'array', 'checkout_url' => 'encrypted', 'paid_at' => 'immutable_datetime', 'fulfilled_at' => 'immutable_datetime', 'refunded_at' => 'immutable_datetime'];

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
