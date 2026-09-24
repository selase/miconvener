<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class EventMaterial extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    public const string PROVENANCE_ORGANIZER = 'organizer';

    public const string PROVENANCE_SPEAKER = 'speaker';

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'event_id',
        'session_id',
        'title',
        'file_path',
        'file_size',
        'mime_type',
        'download_limit',
        'release_at',
        'provenance',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'download_limit' => 'integer',
        'release_at' => 'datetime',
        'provenance' => 'string',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(EventSession::class, 'session_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne<EventSpeaker, $this>
     */
    public function eventSpeaker(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(EventSpeaker::class, 'slides_material_id');
    }

    public function downloads(): HasMany
    {
        return $this->hasMany(EventMaterialDownload::class, 'material_id');
    }

    public function isReleased(?\Carbon\CarbonInterface $now = null): bool
    {
        return app(\App\Services\Events\MaterialReleasePolicy::class)->isReleased($this, $now);
    }

    public function isSpeakerDeck(): bool
    {
        return $this->provenance === self::PROVENANCE_SPEAKER;
    }

    public function isOrganizerMaterial(): bool
    {
        return $this->provenance === self::PROVENANCE_ORGANIZER;
    }

    public function downloadsUsedBy(string $registrationId): int
    {
        return $this->downloads()->where('registration_id', $registrationId)->count();
    }

    public function remainingAttemptsFor(string $registrationId): int
    {
        return max(0, $this->download_limit - $this->downloadsUsedBy($registrationId));
    }
}
