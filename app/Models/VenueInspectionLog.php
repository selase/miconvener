<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class VenueInspectionLog extends Model
{
    use HasFactory;
    use HasUuids;

    public const string TYPE_CHECK_IN = 'check_in';

    public const string TYPE_CHECK_OUT = 'check_out';

    public const array TYPES = [
        self::TYPE_CHECK_IN,
        self::TYPE_CHECK_OUT,
    ];

    public const string STATUS_PENDING = 'pending';

    public const string STATUS_PASSED = 'passed';

    public const string STATUS_FLAGGED = 'flagged';

    public const array STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PASSED,
        self::STATUS_FLAGGED,
    ];

    protected $connection = 'landlord';

    protected $fillable = [
        'shop_id',
        'venue_booking_id',
        'event_id',
        'store_listing_id',
        'type',
        'inspector_user_id',
        'inspector_name',
        'inspector_role',
        'status',
        'checklist',
        'general_notes',
        'signed_by_name',
        'signed_at',
    ];

    /**
     * Default standard facility checklist items for venue walkthroughs.
     *
     * @return array<int, array{item: string, status: string, notes: string|null}>
     */
    public static function defaultChecklist(string $type): array
    {
        $baseItems = [
            'Power & Standby Generator Check',
            'HVAC / Air Conditioning & Ventilation',
            'Audio / Visual (PA, Microphones, Screens, Projectors)',
            'Stage, Podium & Lighting Systems',
            'Seating Arrangement & Furniture Layout',
            'Restrooms Hygiene & Consumables Check',
            'Emergency Exits, Signage & Fire Extinguishers',
        ];

        if ($type === self::TYPE_CHECK_OUT) {
            $baseItems[] = 'Room Cleanliness & Waste Evacuation';
            $baseItems[] = 'No Damaged Equipment / Breakage Report';
        }

        return array_map(fn (string $item): array => [
            'item' => $item,
            'status' => 'good', // good, damaged, not_applicable
            'notes' => null,
        ], $baseItems);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function venueBooking(): BelongsTo
    {
        return $this->belongsTo(VenueBooking::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(StoreListing::class, 'store_listing_id');
    }

    public function inspectorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspector_user_id');
    }

    public function isSigned(): bool
    {
        return $this->signed_at !== null;
    }

    public function isCheckIn(): bool
    {
        return $this->type === self::TYPE_CHECK_IN;
    }

    public function isCheckOut(): bool
    {
        return $this->type === self::TYPE_CHECK_OUT;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'inspector_name' => $this->inspector_name,
            'inspector_role' => $this->inspector_role,
            'status' => $this->status,
            'checklist' => $this->checklist ?? [],
            'general_notes' => $this->general_notes,
            'is_signed' => $this->isSigned(),
            'signed_by_name' => $this->signed_by_name,
            'signed_at' => $this->signed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'checklist' => 'array',
            'signed_at' => 'datetime',
        ];
    }
}
