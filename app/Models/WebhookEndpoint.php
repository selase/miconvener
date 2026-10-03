<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class WebhookEndpoint extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    public const string EVENT_ALL = '*';

    public const string EVENT_REGISTRATION_CREATED = 'registration.created';

    public const string EVENT_REGISTRATION_CONFIRMED = 'registration.confirmed';

    public const string EVENT_REGISTRATION_CANCELLED = 'registration.cancelled';

    public const string EVENT_TICKET_CHECKED_IN = 'ticket.checked_in';

    public const string EVENT_CONTRIBUTION_RECEIVED = 'contribution.received';

    public const string EVENT_OFFLINE_PAYMENT_SUBMITTED = 'offline_payment.submitted';

    public const string EVENT_OFFLINE_PAYMENT_APPROVED = 'offline_payment.approved';

    public const string EVENT_INVOICE_ISSUED = 'invoice.issued';

    public const string EVENT_PING = 'ping';

    public const array AVAILABLE_EVENTS = [
        self::EVENT_REGISTRATION_CREATED => 'Registration Created (Pending/Initiated)',
        self::EVENT_REGISTRATION_CONFIRMED => 'Registration Confirmed (Ticket Issued)',
        self::EVENT_REGISTRATION_CANCELLED => 'Registration Cancelled',
        self::EVENT_TICKET_CHECKED_IN => 'Attendee Checked In at Door/Session',
        self::EVENT_CONTRIBUTION_RECEIVED => 'Contribution / Voluntary Giving Completed',
        self::EVENT_OFFLINE_PAYMENT_SUBMITTED => 'Offline Payment Proof Submitted',
        self::EVENT_OFFLINE_PAYMENT_APPROVED => 'Offline Payment Verified & Approved',
        self::EVENT_INVOICE_ISSUED => 'Billing Invoice Issued',
    ];

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'url',
        'secret',
        'events',
        'is_active',
    ];

    protected $casts = [
        'events' => 'array',
        'secret' => 'encrypted',
        'is_active' => 'boolean',
    ];

    protected $hidden = [
        'secret',
    ];

    public static function generateSecret(): string
    {
        return 'whsec_'.bin2hex(random_bytes(24));
    }

    public function rotateSecret(): string
    {
        $newSecret = self::generateSecret();
        $this->update(['secret' => $newSecret]);

        return $newSecret;
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return HasMany<WebhookCall, $this>
     */
    public function calls(): HasMany
    {
        return $this->hasMany(WebhookCall::class, 'webhook_endpoint_id');
    }
}
