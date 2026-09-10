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
        'capacity',
        'requires_approval',
        'ticket_price',
        'currency',
        'hero_image_path',
        'visibility',
        'plan_your_visit_content',
        'platform_fee_percentage',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'capacity' => 'integer',
        'requires_approval' => 'boolean',
        'ticket_price' => 'integer',
        'platform_fee_percentage' => 'float',
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

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

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

    public function materials(): HasMany
    {
        return $this->hasMany(EventMaterial::class)->orderBy('created_at');
    }

    public function venueRooms(): HasMany
    {
        return $this->hasMany(EventVenueRoom::class)->orderBy('sort_order');
    }

    public function seatAssignments(): HasMany
    {
        return $this->hasMany(EventSeatAssignment::class);
    }

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

    public function polls(): HasMany
    {
        return $this->hasMany(EventPoll::class)->orderByDesc('created_at');
    }

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
        return (float) ($this->platform_fee_percentage
            ?? $this->tenant?->platform_fee_percentage
            ?? $this->tenant?->package?->default_platform_fee_percentage
            ?? config('services.platform.default_fee_percentage'));
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
