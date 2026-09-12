<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EventNotificationLog extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    public const string CHANNEL_EMAIL = 'email';

    public const string CHANNEL_SMS = 'sms';

    public const string CHANNEL_WHATSAPP = 'whatsapp';

    public const string STATUS_SENT = 'sent';

    public const string STATUS_STAGED = 'staged_omnichannel';

    public const string STATUS_FAILED = 'failed';

    public const string STATUS_SUPPRESSED_QUOTA = 'suppressed_quota';

    public const string STATUS_UNSUBSCRIBED = 'unsubscribed';

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'event_id',
        'rule_id',
        'recipient_name',
        'recipient_email',
        'recipient_phone',
        'channel',
        'status',
        'subject',
        'message',
        'cost_billed',
        'metadata',
        'sent_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'cost_billed' => 'integer',
        'sent_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(EventNotificationRule::class, 'rule_id');
    }
}
