<?php

declare(strict_types=1);

namespace App\Models;

use App\Enum\MemberStatusEnum;
use App\Traits\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int|null $amount_override Minor currency units; 0 waives the dues.
 * @property MemberStatusEnum $status
 * @property string $membership_type_id Renamed from dues_class_id; static analysis cannot follow the rename.
 */
final class Member extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\MemberFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'membership_type_id',
        'name',
        'email',
        'phone',
        'amount_override',
        'status',
        'joined_on',
        'exited_on',
        'active_since',
        'lapsed_on',
        'rejoined_on',
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
        'active_since' => 'date',
        'lapsed_on' => 'date',
        'rejoined_on' => 'date',
    ];

    /**
     * @return BelongsTo<MembershipType, $this>
     */
    public function membershipType(): BelongsTo
    {
        return $this->belongsTo(MembershipType::class);
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
     * waiver), otherwise the amount of their membership type. Null when the
     * type carries no dues, whatever the override says.
     */
    public function effectiveAmount(): ?int
    {
        if (! $this->membershipType->hasDues()) {
            return null;
        }

        return $this->amount_override ?? $this->membershipType->amount;
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
            $member->active_since ??= $member->joined_on ?? CarbonImmutable::now(CommunitySettings::for($member->tenant)->timezone)->toDateString();
        });

        self::updating(function (Member $member): void {
            if (! $member->isDirty('status')) {
                return;
            }
            $today = CarbonImmutable::now(CommunitySettings::for($member->tenant)->timezone)->startOfDay();
            if ($member->status === MemberStatusEnum::Active) {
                $member->active_since = $today;
            } elseif ($member->status === MemberStatusEnum::Lapsed) {
                $member->lapsed_on = $today;
            } elseif ($member->status === MemberStatusEnum::Exited && ! $member->isDirty('exited_on')) {
                $member->exited_on = $today;
            }
        });

        self::updated(function (Member $member): void {
            if ($member->wasChanged('status') && in_array($member->status, [MemberStatusEnum::Exited, MemberStatusEnum::Lapsed], true)) {
                app(\App\Services\Dues\DuesCharges::class)->cancelAdvanceFor($member);
            }
        });
    }
}
