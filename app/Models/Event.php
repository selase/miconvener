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
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    public const string CATEGORY_GENERAL = 'general';

    public const string CATEGORY_CONFERENCE = 'conference';

    public const string CATEGORY_FAITH = 'faith';

    public const string CATEGORY_MEMORIAL = 'memorial';

    public const string CATEGORY_ACADEMIC = 'academic';

    public const string CATEGORY_FUNDRAISER = 'fundraiser';

    public const array CATEGORIES = [
        self::CATEGORY_GENERAL,
        self::CATEGORY_CONFERENCE,
        self::CATEGORY_FAITH,
        self::CATEGORY_MEMORIAL,
        self::CATEGORY_ACADEMIC,
        self::CATEGORY_FUNDRAISER,
    ];

    public const string SPEAKER_POLICY_BEFORE = 'before';

    public const string SPEAKER_POLICY_DURING = 'during';

    public const string SPEAKER_POLICY_AFTER = 'after';

    public const array SPEAKER_POLICIES = [
        self::SPEAKER_POLICY_BEFORE,
        self::SPEAKER_POLICY_DURING,
        self::SPEAKER_POLICY_AFTER,
    ];

    protected $connection = 'landlord';

    /**
     * Public unless someone says otherwise, set here as well as in the schema so
     * an unsaved instance answers isPrivate() correctly rather than null.
     */
    protected $attributes = [
        'visibility' => self::VISIBILITY_PUBLIC,
        'event_category' => self::CATEGORY_GENERAL,
        'speaker_slide_policy' => self::SPEAKER_POLICY_AFTER,
    ];

    protected $fillable = [
        'tenant_id',
        'created_by',
        'name',
        'slug',
        'present_token',
        'description',
        'event_category',
        'cover_image_path',
        'status',
        'starts_at',
        'ends_at',
        'timezone',
        'location_type',
        'allows_self_check_in',
        'address',
        'virtual_link',
        'contact_email',
        'capacity',
        'requires_approval',
        'ticket_price',
        'currency',
        'hero_image_path',
        'visibility',
        'speaker_slide_policy',
        'plan_your_visit_content',
        'store_listing_id',
        'venue_booking_id',
        'platform_fee_percentage',
        'platform_fee_cap_amount',
        'fee_bearer',
        'registration_settings',
        'allow_contributions',
        'contribution_title',
        'contribution_description',
        'contribution_presets',
        'contribution_min_amount_pesewas',
        'contribution_goal_amount_pesewas',
        'show_tribute_wall',
        'show_contributor_amounts',
        'is_recurring',
        'recurrence_pattern',
        'recurrence_days',
        'recurrence_time_start',
        'recurrence_time_end',
        'recurrence_interval',
        'recurrence_until',
        'recurrence_auto_generate_weeks',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'grandfathered_at' => 'datetime',
        'terms_locked_at' => 'datetime',
        'ends_at' => 'datetime',
        'capacity' => 'integer',
        'requires_approval' => 'boolean',
        'allows_self_check_in' => 'boolean',
        'ticket_price' => 'integer',
        'platform_fee_percentage' => 'float',
        'platform_fee_cap_amount' => 'integer',
        'registration_settings' => 'array',
        'event_category' => 'string',
        'speaker_slide_policy' => 'string',
        'allow_contributions' => 'boolean',
        'contribution_presets' => 'array',
        'contribution_min_amount_pesewas' => 'integer',
        'contribution_goal_amount_pesewas' => 'integer',
        'show_tribute_wall' => 'boolean',
        'show_contributor_amounts' => 'boolean',
        'is_recurring' => 'boolean',
        'recurrence_days' => 'array',
        'recurrence_interval' => 'integer',
        'recurrence_until' => 'date',
        'recurrence_auto_generate_weeks' => 'integer',
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
     * @return BelongsTo<StoreListing, $this>
     */
    public function venueListing(): BelongsTo
    {
        return $this->belongsTo(StoreListing::class, 'store_listing_id');
    }

    /**
     * @return BelongsTo<VenueBooking, $this>
     */
    public function venueBooking(): BelongsTo
    {
        return $this->belongsTo(VenueBooking::class, 'venue_booking_id');
    }

    public function isMarketplaceVenue(): bool
    {
        return $this->store_listing_id !== null;
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

    /** @return HasOne<EventBadgeTemplate, $this> */
    public function badgeTemplate(): HasOne
    {
        return $this->hasOne(EventBadgeTemplate::class);
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

    /**
     * @return HasMany<EventContribution, $this>
     */
    public function contributions(): HasMany
    {
        return $this->hasMany(EventContribution::class)->orderByDesc('created_at');
    }

    public function allowsContributions(): bool
    {
        return (bool) $this->allow_contributions;
    }

    public function isRecurring(): bool
    {
        return (bool) $this->is_recurring;
    }

    public function isFaith(): bool
    {
        return $this->event_category === self::CATEGORY_FAITH;
    }

    public function isMemorial(): bool
    {
        return $this->event_category === self::CATEGORY_MEMORIAL;
    }

    public function isAcademic(): bool
    {
        return $this->event_category === self::CATEGORY_ACADEMIC;
    }

    public function isFundraiser(): bool
    {
        return $this->event_category === self::CATEGORY_FUNDRAISER;
    }

    public function isConference(): bool
    {
        return $this->event_category === self::CATEGORY_CONFERENCE;
    }

    /**
     * @return array<string, mixed>
     */
    public function lexicon(): array
    {
        return \App\Services\Events\EventLexicon::forEvent($this);
    }

    /**
     * @return array<int, string>
     */
    public function recurrenceDays(): array
    {
        return is_array($this->recurrence_days) ? $this->recurrence_days : [];
    }

    /**
     * @return HasMany<EventSession, $this>
     */
    public function recurringSessions(): HasMany
    {
        return $this->hasMany(EventSession::class)->where('is_occurrence', true)->orderBy('starts_at');
    }

    /**
     * @return HasMany<EventSession, $this>
     */
    public function upcomingRecurringSessions(): HasMany
    {
        return $this->recurringSessions()->where('starts_at', '>=', now()->startOfDay());
    }

    /**
     * @return array<int, int>
     */
    public function effectiveContributionPresets(): array
    {
        return ! empty($this->contribution_presets)
            ? $this->contribution_presets
            : [5000, 10000, 20000, 50000]; // 50, 100, 200, 500 GHS
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

    public function payoutSchedule(): HasOne
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

    /** @return BelongsToMany<Speaker, $this> */
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
