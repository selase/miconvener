<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string|null $option_id
 * @property string|null $response_text
 * @property bool|null $is_correct
 * @property int $points_awarded
 */
final class EventPollResponse extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'poll_id',
        'option_id',
        'response_text',
        'response_number',
        'response_payload',
        'respondent_token',
        'respondent_name',
        'is_correct',
        'points_awarded',
        'is_approved',
    ];

    protected $casts = [
        'is_correct' => 'boolean',
        'points_awarded' => 'integer',
        'is_approved' => 'boolean',
        'response_number' => 'float',
        'response_payload' => 'array',
    ];

    public function poll(): BelongsTo
    {
        return $this->belongsTo(EventPoll::class, 'poll_id');
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(EventPollOption::class, 'option_id');
    }
}
