<?php

declare(strict_types=1);

namespace App\Models;

use App\Enum\MemberStatusEnum;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int|null $amount_override Minor currency units; 0 waives the dues.
 * @property MemberStatusEnum $status
 */
final class Member extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'dues_class_id',
        'name',
        'email',
        'phone',
        'amount_override',
        'status',
        'joined_on',
        'exited_on',
        'pay_token',
        'notes',
    ];

    protected $hidden = [
        'pay_token',
    ];

    protected $casts = [
        'amount_override' => 'integer',
        'status' => MemberStatusEnum::class,
        'joined_on' => 'date',
        'exited_on' => 'date',
    ];

    /**
     * @return BelongsTo<DuesClass, $this>
     */
    public function duesClass(): BelongsTo
    {
        return $this->belongsTo(DuesClass::class);
    }

    /**
     * @return HasMany<DuesCharge, $this>
     */
    public function charges(): HasMany
    {
        return $this->hasMany(DuesCharge::class);
    }

    /**
     * What this member owes each period: their own amount if set (zero is a
     * waiver), otherwise the amount of their class.
     */
    public function effectiveAmount(): int
    {
        return $this->amount_override ?? $this->duesClass->amount;
    }

    /**
     * Replaces the member's payment link token, which retires the old link.
     */
    public function rotatePayToken(): string
    {
        $this->forceFill(['pay_token' => Str::random(40)])->save();

        return $this->pay_token;
    }

    protected static function booted(): void
    {
        self::creating(function (Member $member): void {
            if (blank($member->pay_token)) {
                $member->pay_token = Str::random(40);
            }
        });
    }
}
