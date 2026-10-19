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
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int|null $amount_override Minor currency units; 0 waives the dues.
 * @property CarbonImmutable|null $dues_start_on Earliest dues eligibility after a membership-type change.
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

    protected $attributes = ['access_version' => 1];

    protected $hidden = [
        'pay_token',
    ];

    protected $casts = [
        'access_version' => 'integer',
        'amount_override' => 'integer',
        'status' => MemberStatusEnum::class,
        'joined_on' => 'date',
        'exited_on' => 'date',
        'active_since' => 'date',
        'dues_start_on' => 'immutable_date',
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

    /** @return BelongsToMany<CommunityGroup, $this> */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(CommunityGroup::class, 'community_group_members', 'member_id', 'group_id')->withPivot('tenant_id')->withTimestamps();
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

    /** First full period permitted by both the type and this member's dues boundary. */
    public function firstDuesPeriod(CommunitySettings $settings): ?CarbonImmutable
    {
        $type = $this->membershipType;
        if ($type->period === null) {
            return null;
        }
        $dates = array_filter([$type->dues_start_on?->toDateString(), $this->dues_start_on?->toDateString()]);
        if ($dates === []) {
            return null;
        }
        $date = CarbonImmutable::parse(max($dates), $settings->timezone);
        $start = $type->period->periodStartFor($date, $settings);

        return $start->lt($date) ? $type->period->nextStart($start, $settings) : $start;
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
            $identity = app(\App\Services\Community\MemberIdentity::class);
            $changed = $identity->normaliseEmail($member->email) !== $identity->normaliseEmail($member->getOriginal('email'))
                || $identity->normalisePhone($member->phone) !== $identity->normalisePhone($member->getOriginal('phone'));
            if ($changed) {
                $member->access_version = ((int) $member->getOriginal('access_version')) + 1;
            }

            if ($member->isDirty('membership_type_id')) {
                $type = MembershipType::withoutGlobalScopes()->where('tenant_id', $member->tenant_id)->findOrFail($member->membership_type_id);
                $previousType = MembershipType::withoutGlobalScopes()->where('tenant_id', $member->tenant_id)->findOrFail($member->getOriginal('membership_type_id'));
                if (! $previousType->hasDues() && $type->hasDues()) {
                    $member->dues_start_on = CarbonImmutable::now(CommunitySettings::for($member->tenant)->timezone)->startOfDay();
                }
                $member->setRelation('membershipType', $type);
            }

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
            if ($member->wasChanged('access_version') && ($member->wasChanged('phone') || $member->wasChanged('email'))) {
                CommunityAccessCredential::withoutGlobalScopes()->where('tenant_id', $member->tenant_id)->where('member_id', $member->id)->where('role', 'self')->whereNull('revoked_at')->update(['revoked_at' => now()]);
                CommunityAccessCredential::withoutGlobalScopes()->where('tenant_id', $member->tenant_id)->where('member_id', $member->id)->where('role', 'representative')->whereNull('revoked_at')->update(['suspended_at' => now()]);
                CommunityAccessClaim::withoutGlobalScopes()->where('tenant_id', $member->tenant_id)->where('member_id', $member->id)->whereIn('status', ['pending', 'approved'])->update(['status' => 'expired']);
                CommunityAccessAudit::query()->create(['tenant_id' => $member->tenant_id, 'member_id' => $member->id, 'action' => 'contact_changed', 'method' => 'contact_version']);
            }

            if ($member->wasChanged('status') && in_array($member->status, [MemberStatusEnum::Exited, MemberStatusEnum::Lapsed], true)) {
                app(\App\Services\Dues\DuesCharges::class)->cancelAdvanceFor($member);
            }
        });
    }
}
