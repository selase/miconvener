<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class EventOperationPillar extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'event_id',
        'name',
        'slug',
        'color',
        'icon',
        'is_default',
        'sort_order',
    ];

    /**
     * Default 8 core operational pillars for academic & professional conferences.
     *
     * @return array<int, array{name: string, slug: string, color: string, icon: string}>
     */
    public static function defaultPillars(): array
    {
        return [
            [
                'name' => 'Programme & Speakers',
                'slug' => 'programme-speakers',
                'color' => '#8B5CF6',
                'icon' => 'calendar',
            ],
            [
                'name' => 'Technology & Registration',
                'slug' => 'technology-registration',
                'color' => '#3B82F6',
                'icon' => 'cpu-chip',
            ],
            [
                'name' => 'Finance & Procurement',
                'slug' => 'finance-procurement',
                'color' => '#10B981',
                'icon' => 'banknotes',
            ],
            [
                'name' => 'Sponsorship & Exhibition',
                'slug' => 'sponsorship-exhibition',
                'color' => '#F59E0B',
                'icon' => 'building-storefront',
            ],
            [
                'name' => 'Communications & Media',
                'slug' => 'communications-media',
                'color' => '#EC4899',
                'icon' => 'megaphone',
            ],
            [
                'name' => 'Venue & Logistics',
                'slug' => 'venue-logistics',
                'color' => '#6366F1',
                'icon' => 'map-pin',
            ],
            [
                'name' => 'Protocol & Hospitality',
                'slug' => 'protocol-hospitality',
                'color' => '#14B8A6',
                'icon' => 'user-group',
            ],
            [
                'name' => 'Post-Conference Reporting',
                'slug' => 'post-conference-reporting',
                'color' => '#64748B',
                'icon' => 'clipboard-document-check',
            ],
        ];
    }

    /**
     * Ensure default pillars exist for the given event.
     */
    public static function seedDefaultsForEvent(Event $event): void
    {
        $tenantId = $event->tenant_id;
        $order = 0;

        foreach (self::defaultPillars() as $pillarDef) {
            self::firstOrCreate(
                [
                    'event_id' => $event->id,
                    'slug' => $pillarDef['slug'],
                ],
                [
                    'tenant_id' => $tenantId,
                    'name' => $pillarDef['name'],
                    'color' => $pillarDef['color'],
                    'icon' => $pillarDef['icon'],
                    'is_default' => true,
                    'sort_order' => $order++,
                ]
            );
        }
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(EventOperationTask::class, 'pillar_id')->orderBy('due_date')->orderBy('created_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
