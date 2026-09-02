<?php

declare(strict_types=1);

namespace App\Models;

use App\Enum\DataExportStatus;
use App\Traits\BelongsToTenant;
use App\Traits\HasUuid;
use App\Traits\SpatieActivityLogs;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DataExportRequest extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuid;
    use HasUuids;
    use SpatieActivityLogs;

    protected $connection = 'landlord';

    protected $guarded = [];

    protected $casts = [
        'status' => DataExportStatus::class,
        'scope' => 'array',
        'file_size_bytes' => 'integer',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isDownloadable(): bool
    {
        return $this->status === DataExportStatus::Completed
            && $this->file_path !== null
            && ! $this->isExpired();
    }
}
