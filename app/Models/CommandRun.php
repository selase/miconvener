<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * One run of an operational command triggered from the Superadmin console.
 *
 * @property string|null $status
 * @property string|null $output
 * @property int|null $exit_code
 */
final class CommandRun extends Model
{
    use HasUuid;

    public const string STATUS_QUEUED = 'queued';

    public const string STATUS_RUNNING = 'running';

    public const string STATUS_SUCCESS = 'success';

    public const string STATUS_FAILED = 'failed';

    protected $connection = 'landlord';

    protected $fillable = [
        'uuid',
        'command_key',
        'command',
        'options',
        'status',
        'exit_code',
        'output',
        'triggered_by',
        'triggered_by_email',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'options' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_SUCCESS, self::STATUS_FAILED], true);
    }

    public function durationForHumans(): string
    {
        if ($this->started_at === null || $this->finished_at === null) {
            return '-';
        }

        return $this->started_at->diffInSeconds($this->finished_at).'s';
    }
}
