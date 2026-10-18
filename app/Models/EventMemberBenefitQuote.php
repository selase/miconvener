<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $event_id
 * @property string $registration_id
 * @property string $member_id
 * @property string $currency
 * @property string $cart_fingerprint
 * @property string $state
 * @property int $amount
 * @property int $platform_fee_amount
 * @property int $charged_amount
 * @property array<string, mixed> $identity
 * @property array<string, mixed> $pricing
 * @property \Carbon\CarbonImmutable $expires_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class EventMemberBenefitQuote extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\EventMemberBenefitQuoteFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = ['tenant_id', 'event_id', 'registration_id', 'member_id', 'identity', 'pricing', 'amount', 'platform_fee_amount', 'charged_amount', 'currency', 'cart_fingerprint', 'state', 'expires_at'];

    protected $hidden = ['identity', 'cart_fingerprint', 'member_id', 'pricing'];

    protected $casts = ['identity' => 'array', 'pricing' => 'array', 'amount' => 'integer', 'platform_fee_amount' => 'integer', 'charged_amount' => 'integer', 'expires_at' => 'immutable_datetime'];

    /** @return BelongsTo<EventRegistration, $this> */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(EventRegistration::class, 'registration_id');
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return HasMany<EventMemberBenefitAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(EventMemberBenefitAttempt::class, 'quote_id');
    }
}
