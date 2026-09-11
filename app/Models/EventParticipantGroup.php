<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

final class EventParticipantGroup extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    public const string TYPE_DYNAMIC = 'dynamic';

    public const string TYPE_MANUAL = 'manual';

    public const array TYPES = [
        self::TYPE_DYNAMIC,
        self::TYPE_MANUAL,
    ];

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'event_id',
        'name',
        'slug',
        'description',
        'color',
        'icon',
        'type',
        'criteria',
        'member_count',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(EventParticipantGroupMember::class, 'group_id');
    }

    public function registrations(): BelongsToMany
    {
        return $this->belongsToMany(
            EventRegistration::class,
            'event_participant_group_members',
            'group_id',
            'registration_id'
        )->withPivot(['is_manual', 'matched_at']);
    }

    protected static function booted(): void
    {
        self::creating(function (self $group): void {
            if (empty($group->slug)) {
                $group->slug = Str::slug($group->name);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'criteria' => 'array',
            'member_count' => 'integer',
        ];
    }
}
