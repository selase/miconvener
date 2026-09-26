<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Speaker extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'title',
        'organization',
        'bio',
        'photo_path',
    ];

    /** @return HasMany<EventSpeaker, $this> */
    public function eventSpeakers(): HasMany
    {
        return $this->hasMany(EventSpeaker::class);
    }

    /**
     * Addresses are compared without regard to case or stray spacing, the same
     * way the attendee portal compares them, so a speaker typed in by an
     * organiser still matches the address they verify with.
     *
     * @param  Builder<Speaker>  $query
     * @return Builder<Speaker>
     */
    public function scopeForEmail(Builder $query, string $email): Builder
    {
        return $query->whereRaw(
            'lower('.$query->qualifyColumn('email').') = ?',
            [mb_strtolower(mb_trim($email))]
        );
    }

    /** A speaker without an address cannot reach their portal. */
    public function needsEmail(): bool
    {
        return blank($this->email);
    }
}
