<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class EventCertificateDesignVersion extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'event_id',
        'template_id',
        'version',
        'design_hash',
        'design_mode',
        'orientation',
        'page_size',
        'title',
        'body_template',
        'issuer_name',
        'issuer_title',
        'signature_disk',
        'signature_path',
        'background_disk',
        'background_path',
        'show_qr',
        'show_cpd_hours',
        'default_cpd_hours',
        'layout',
    ];

    /**
     * @return BelongsTo<EventCertificateTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(EventCertificateTemplate::class, 'template_id');
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return HasMany<EventCertificate, $this>
     */
    public function certificates(): HasMany
    {
        return $this->hasMany(EventCertificate::class, 'design_version_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'show_qr' => 'boolean',
            'show_cpd_hours' => 'boolean',
            'default_cpd_hours' => 'float',
            'layout' => 'array',
        ];
    }
}
