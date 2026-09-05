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
    ];

    protected $casts = [
        'file_size' => 'integer',
        'download_limit' => 'integer',
        'release_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(EventSession::class, 'session_id');
    }

    public function downloads(): HasMany
    {
        return $this->hasMany(EventMaterialDownload::class, 'material_id');
    }

    public function isReleased(): bool
    {
        return $this->release_at === null || $this->release_at->isPast();
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
