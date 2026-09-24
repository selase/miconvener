<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

final class EventRegistration extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;
    use LogsActivity;

    public const string STATUS_PENDING_PAYMENT = 'pending_payment';

    public const string STATUS_CONFIRMED = 'confirmed';

    public const string STATUS_CANCELLED = 'cancelled';

    public const string STATUS_CHECKED_IN = 'checked_in';

    public const string STATUS_PENDING_APPROVAL = 'pending_approval';

    public const string STATUS_REJECTED = 'rejected';

    public const string STATUS_WAITLISTED = 'waitlisted';

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'event_id',
        'ticket_type_id',
        'full_name',
        'title',
        'first_name',
        'last_name',
        'email',
        'email_verified_at',
        'phone',
        'dietary_requirements',
        'accessibility_needs',
        'form_answers',
        'status',
        'waitlist_position',
        'approval_note',
        'ticket_code',
        'qr_token',
        'promo_code_id',
        'amount',
        'discount_amount',
        'platform_fee_amount',
        'charged_amount',
        'gateway_fee_amount',
        'currency',
        'payment_reference',
        'checked_in_at',
        'checked_in_by',
    ];

    protected $casts = [
        'amount' => 'integer',
        'discount_amount' => 'integer',
        'platform_fee_amount' => 'integer',
        'charged_amount' => 'integer',
        'gateway_fee_amount' => 'integer',
        'waitlist_position' => 'integer',
        'checked_in_at' => 'datetime',
        'email_verified_at' => 'datetime',
        'form_answers' => 'array',
    ];

    protected $hidden = [
        'qr_token',
    ];

    /**
     * Human-readable, collision-checked ticket code. Never used as the QR
     * payload itself — see generateQrToken().
     */
    public static function generateTicketCode(): string
    {
        do {
            $code = 'EVT-'.mb_strtoupper(Str::random(4)).'-'.random_int(100, 999);
        } while (self::where('ticket_code', $code)->exists());

        return $code;
    }

    /**
     * Signed token carried in the QR code. Verified via hash_equals against
     * a fresh HMAC of the registration id, so a forged/guessed token fails.
     */
    public static function signToken(string $registrationId, ?string $salt = null): string
    {
        $payload = $salt !== null ? "{$registrationId}:{$salt}" : $registrationId;

        return hash_hmac('sha256', $payload, (string) config('app.key'));
    }

    /**
     * Excludes qr_token — it's a signed security credential, not a business
     * field, and has no business being written into an audit trail.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['full_name', 'email', 'phone', 'status', 'ticket_type_id', 'waitlist_position', 'approval_note', 'checked_in_at', 'checked_in_by'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('system');
    }

    /**
     * What the gateway was asked to collect. Registrations created before fee
     * pass-through existed carry no value and were charged the ticket price.
     */
    public function effectiveChargedAmount(): int
    {
        return (int) ($this->charged_amount ?? $this->amount);
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return BelongsTo<EventTicketType, $this>
     */
    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(EventTicketType::class, 'ticket_type_id');
    }

    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(EventPromoCode::class, 'promo_code_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function checkedInBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }

    public function sessions(): BelongsToMany
    {
        return $this->belongsToMany(EventSession::class, 'event_registration_sessions', 'registration_id', 'session_id')->withTimestamps();
    }

    /**
     * @return HasOne<EventSeatAssignment, $this>
     */
    public function seatAssignment(): HasOne
    {
        return $this->hasOne(EventSeatAssignment::class, 'registration_id');
    }

    public function badgePrints(): HasMany
    {
        return $this->hasMany(EventBadgePrint::class, 'registration_id')->orderByDesc('created_at');
    }

    /**
     * Every handover this ticket has been through, consumed or still open.
     *
     * A consumed one marks the moment the ticket changed hands, which is what
     * separates the records of one holder from the next.
     *
     * @return HasMany<EventRegistrationTransfer, $this>
     */
    public function transfers(): HasMany
    {
        return $this->hasMany(EventRegistrationTransfer::class, 'registration_id');
    }

    public function isConfirmed(): bool
    {
        return in_array($this->status, [self::STATUS_CONFIRMED, self::STATUS_CHECKED_IN], true);
    }

    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_CONFIRMED, self::STATUS_CHECKED_IN]);
    }

    public function scopeWaitlisted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_WAITLISTED);
    }

    /**
     * Addresses are stored as typed; identity is not case-sensitive. Compared
     * as lower(email) so the (tenant_id, lower(email)) index serves it.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForEmail(Builder $query, string $email): Builder
    {
        return $query->whereRaw('lower('.$query->qualifyColumn('email').') = ?', [mb_strtolower(mb_trim($email))]);
    }

    /**
     * A paid ticket is verified by the payment itself -- the holder received a
     * checkout link and a receipt at that address. A free one has nothing
     * standing behind it but the click on a verification email.
     */
    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function issueTicket(): void
    {
        $this->ticket_code = self::generateTicketCode();
        $this->qr_token = self::signToken($this->id);
    }

    /**
     * Transactionally rotate ticket code and QR token upon transfer so
     * previously downloaded tickets, screenshots, and offline snapshots
     * become immediately invalid at online check-in.
     */
    public function rotateTicketCredentials(): void
    {
        $this->ticket_code = self::generateTicketCode();
        $this->qr_token = self::signToken($this->id, Str::random(16));
    }
}
