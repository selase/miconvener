<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An event email that went straight to an attendee. Written by
 * RecordSentEventEmail when the message leaves; read by MessageDeliverySearch.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $event_id
 * @property string $recipient_email
 * @property string|null $subject
 * @property string $mailable
 * @property Carbon $sent_at
 */
final class SentEmail extends Model
{
    /** @use HasFactory<\Database\Factories\SentEmailFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'event_id',
        'recipient_email',
        'subject',
        'mailable',
        'sent_at',
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }
}
