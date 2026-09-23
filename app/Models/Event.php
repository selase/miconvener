<?php

declare(strict_types=1);

namespace App\Models;

use App\Libraries\Helper;
use App\Traits\BelongsToTenant;
use App\Traits\SpatieActivityLogs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Event extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;
    use SpatieActivityLogs;

    public const string STATUS_DRAFT = 'draft';

    public const string STATUS_PUBLISHED = 'published';

    public const string STATUS_CANCELLED = 'cancelled';

    public const string LOCATION_IN_PERSON = 'in_person';

    public const string LOCATION_VIRTUAL = 'virtual';

    /**
     * Anyone with the link sees the whole page: lineup, agenda, sponsors.
     */
    public const string VISIBILITY_PUBLIC = 'public';

    /**
     * The link reaches a registration form and little else. Speakers, sessions
     * and sponsors appear only once a registration is confirmed, because for a
     * closed event the lineup is the confidential part.
     *
     * There is deliberately no "unlisted" between these two: nothing in this
     * application lists events publicly, so unlisted would behave identically
     * to public and mean nothing.
     */
    public const string VISIBILITY_PRIVATE = 'private';

    public const array VISIBILITIES = [self::VISIBILITY_PUBLIC, self::VISIBILITY_PRIVATE];

    protected $connection = 'landlord';

    /**
     * Public unless someone says otherwise, set here as well as in the schema so
     * an unsaved instance answers isPrivate() correctly rather than null.
     */
    protected $attributes = [
        'visibility' => self::VISIBILITY_PUBLIC,
    ];

    protected $fillable = [
        'tenant_id',
        'created_by',
        'name',
        'slug',
        'description',
        'cover_image_path',
        'status',
        'starts_at',
        'ends_at',
        'timezone',
        'location_type',
        'address',
        'virtual_link',
        'contact_email',
        'capacity',
        'requires_approval',
        'ticket_price',
        'currency',
        'hero_image_path',
        'visibility',
        'plan_your_visit_content',
        'platform_fee_percentage',
        'platform_fee_cap_amount',
        'fee_bearer',
        'registration_settings',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'grandfathered_at' => 'datetime',
        'terms_locked_at' => 'datetime',
        'ends_at' => 'datetime',
        'capacity' => 'integer',
        'requires_approval' => 'boolean',
        'ticket_price' => 'integer',
        'platform_fee_percentage' => 'float',
        'platform_fee_cap_amount' => 'integer',
        'registration_settings' => 'array',
    ];

    /**
     * The disk event uploads live on, matching the convention used at every
     * upload site.
     */
    public static function uploadDisk(): string
    {
        return config('app.env') === 'production' ? 's3' : 'public';
    }

    public function heroImageUrl(): ?string
    {
        return Helper::storageUrl($this->hero_image_path);
    }

    public function getTitleAttribute(): string
    {
        return $this->name;
    }

    public function formFields(): HasMany
    {
        return $this->hasMany(EventFormField::class)->orderBy('sort_order')->orderBy('created_at');
    }

    /**
     * @return array<string, mixed>
     */
    public function effectiveRegistrationSettings(): array
    {
        $raw = $this->registration_settings ?? [];
        $collectPhone = $raw['collect_phone'] ?? (isset($raw['phone']) ? $raw['phone'] !== 'hidden' : true);
        $requirePhone = $raw['require_phone'] ?? (isset($raw['phone']) ? $raw['phone'] === 'required' : false);
        $collectDietary = $raw['collect_dietary'] ?? (isset($raw['dietary_requirements']) ? $raw['dietary_requirements'] !== 'hidden' : true);
        $requireDietary = $raw['require_dietary'] ?? (isset($raw['dietary_requirements']) ? $raw['dietary_requirements'] === 'required' : false);
        $collectAccess = $raw['collect_accessibility'] ?? (isset($raw['accessibility_needs']) ? $raw['accessibility_needs'] !== 'hidden' : true);
        $requireAccess = $raw['require_accessibility'] ?? (isset($raw['accessibility_needs']) ? $raw['accessibility_needs'] === 'required' : false);

        return [
            'collect_phone' => (bool) $collectPhone,
            'require_phone' => (bool) $requirePhone,
            'collect_dietary' => (bool) $collectDietary,
            'require_dietary' => (bool) $requireDietary,
            'collect_accessibility' => (bool) $collectAccess,
            'require_accessibility' => (bool) $requireAccess,
            'phone' => ! $collectPhone ? 'hidden' : ($requirePhone ? 'required' : 'optional'),
            'dietary_requirements' => ! $collectDietary ? 'hidden' : ($requireDietary ? 'required' : 'optional'),
            'accessibility_needs' => ! $collectAccess ? 'hidden' : ($requireAccess ? 'required' : 'optional'),
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<EventRegistration, $this>
     */
    public function registrations(): HasMany
    {
        return $this->hasMany(EventRegistration::class);
    }

    public function blasts(): HasMany
    {
        return $this->hasMany(EventBlast::class);
    }

    public function isPrivate(): bool
    {
        return $this->visibility === self::VISIBILITY_PRIVATE;
    }

    /**
     * @return HasMany<EventMaterial, $this>
     */
    public function materials(): HasMany
    {
        return $this->hasMany(EventMaterial::class)->orderBy('created_at');
    }

    public function venueRooms(): HasMany
    {
        return $this->hasMany(EventVenueRoom::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<EventSeatAssignment, $this>
     */
    public function seatAssignments(): HasMany
    {
        return $this->hasMany(EventSeatAssignment::class);
    }

    /**
     * @return HasMany<EventForumThread, $this>
     */
    public function forumThreads(): HasMany
    {
        return $this->hasMany(EventForumThread::class)->orderByDesc('is_pinned')->orderByDesc('created_at');
    }

    public function forumBans(): HasMany
    {
        return $this->hasMany(EventForumBan::class);
    }

    public function badgePrints(): HasMany
    {
        return $this->hasMany(EventBadgePrint::class);
    }

    /**
     * @return HasMany<EventPoll, $this>
     */
    public function polls(): HasMany
    {
        return $this->hasMany(EventPoll::class)->orderByDesc('created_at');
    }

    /**
     * @return HasMany<EventServiceRequest, $this>
     */
    public function serviceRequests(): HasMany
    {
        return $this->hasMany(EventServiceRequest::class)->orderByDesc('created_at');
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(EventPayout::class)->orderByDesc('created_at');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(EventLedgerEntry::class, 'event_id');
    }

    public function sponsors(): HasMany
    {
        return $this->hasMany(EventSponsor::class)->orderBy('sort_order');
    }

    public function ticketTypes(): HasMany
    {
        return $this->hasMany(EventTicketType::class)->orderBy('sort_order');
    }

    public function promoCodes(): HasMany
    {
        return $this->hasMany(EventPromoCode::class);
    }

    public function abstracts(): HasMany
    {
        return $this->hasMany(EventAbstract::class)->orderByDesc('created_at');
    }

    public function payoutSchedule(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(EventPayoutSchedule::class);
    }

    public function ledgerTransactions(): HasMany
    {
        return $this->hasMany(LedgerTransaction::class);
    }

    public function operationPillars(): HasMany
    {
        return $this->hasMany(EventOperationPillar::class)->orderBy('sort_order');
    }

    public function operationTasks(): HasMany
    {
        return $this->hasMany(EventOperationTask::class)->orderBy('due_date');
    }

    public function certificateTemplates(): HasMany
    {
        return $this->hasMany(EventCertificateTemplate::class);
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(EventCertificate::class)->latest('issued_at');
    }

    /**
     * @return HasMany<EventDynamicForm, $this>
     */
    public function dynamicForms(): HasMany
    {
        return $this->hasMany(EventDynamicForm::class)->latest('created_at');
    }

    /**
     * @return HasMany<EventParticipantGroup, $this>
     */
    public function participantGroups(): HasMany
    {
        return $this->hasMany(EventParticipantGroup::class)->orderBy('name');
    }

    public function notificationRules(): HasMany
    {
        return $this->hasMany(EventNotificationRule::class)->latest('created_at');
    }

    public function notificationLogs(): HasMany
    {
        return $this->hasMany(EventNotificationLog::class)->latest('created_at');
    }

    /**
     * @return HasMany<EventSession, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(EventSession::class)->orderBy('starts_at')->orderBy('sort_order');
    }

    public function speakers(): BelongsToMany
    {
        return $this->belongsToMany(Speaker::class, 'event_speakers', 'event_id', 'speaker_id')
            ->withPivot(['role', 'sort_order'])
            ->orderBy('event_speakers.sort_order');
    }

    public function hasTicketTypes(): bool
    {
        return $this->relationLoaded('ticketTypes')
            ? $this->ticketTypes->where('is_active', true)->isNotEmpty()
            : $this->ticketTypes()->active()->exists();
    }

    public function isFree(): bool
    {
        if (! $this->hasTicketTypes()) {
            return $this->ticket_price <= 0;
        }

        $activeTypes = $this->relationLoaded('ticketTypes')
            ? $this->ticketTypes->where('is_active', true)
            : $this->ticketTypes()->active()->get();

        return $activeTypes->every(fn (EventTicketType $type): bool => $type->price <= 0);
    }

    public function effectivePlatformFeePercentage(): float
    {
        if ($this->platform_fee_percentage === null && $this->terms_locked_at !== null) {
            return (float) $this->locked_platform_fee_percentage;
        }

        return (float) ($this->platform_fee_percentage
            ?? $this->tenant?->platform_fee_percentage
            ?? $this->tenant?->package?->default_platform_fee_percentage
            ?? config('services.platform.default_fee_percentage'));
    }

    /**
     * The commission ceiling for one ticket on this event, in minor units.
     * Null means uncapped; a stored zero is a waiver and is honoured as one.
     */
    public function effectivePlatformFeeCapAmount(): ?int
    {
        if ($this->platform_fee_cap_amount === null && $this->terms_locked_at !== null) {
            return $this->locked_platform_fee_cap_amount === null ? null : (int) $this->locked_platform_fee_cap_amount;
        }

        $cap = $this->platform_fee_cap_amount
            ?? $this->tenant?->platform_fee_cap_amount
            ?? $this->tenant?->package?->default_platform_fee_cap_amount
            ?? config('services.platform.default_fee_cap_amount');

        return $cap === null ? null : (int) $cap;
    }

    /**
     * Whether the organizer absorbs the platform commission or the attendee
     * pays it on top of the ticket price.
     */
    public function effectiveFeeBearer(): string
    {
        if ($this->fee_bearer === null && $this->terms_locked_at !== null && $this->locked_fee_bearer !== null) {
            return (string) $this->locked_fee_bearer;
        }

        return (string) ($this->fee_bearer
            ?? $this->tenant?->fee_bearer
            ?? $this->tenant?->package?->default_fee_bearer
            ?? config('services.platform.default_fee_bearer'));
    }

    /**
     * Published and not over: the events a plan change must not break.
     */
    public function isLive(): bool
    {
        return $this->isPublished() && $this->ends_at->gte(now());
    }

    /**
     * Whether this event was already live when its organizer moved to a
     * smaller plan. It keeps registering and selling as it did until it ends.
     */
    public function isGrandfathered(): bool
    {
        return $this->grandfathered_at !== null;
    }

    /**
     * Whether attendees may buy paid tickets for this event.
     */
    public function sellsPaidTickets(): bool
    {
        return $this->isGrandfathered() || (bool) Tenant::query()->find($this->tenant_id)?->planAllows('paid_tickets');
    }

    /**
     * Whether the event charges anything: an event price or an active paid ticket type.
     */
    public function isPaid(): bool
    {
        return (int) $this->ticket_price > 0
            || $this->ticketTypes()->where('is_active', true)->where('price', '>', 0)->exists();
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    protected static function booted(): void
    {
        // event_materials cascades at the database level, so the rows that point
        // at these files disappear the instant the event does -- taking with them
        // the only record of what to delete. The files have to go first, and this
        // lives on the model rather than the controller so every deletion path is
        // covered, not just the one the UI happens to use.
        self::deleting(function (self $event): void {
            $disk = self::uploadDisk();

            foreach ($event->materials()->pluck('file_path') as $path) {
                if ($path) {
                    Helper::deleteFile($path, $disk);
                }
            }

            if ($event->hero_image_path) {
                Helper::deleteFile($event->hero_image_path, $disk);
            }
        });
    }
}
