<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class EventCertificateTemplate extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    public const string ROLE_DELEGATE = 'delegate';

    public const string ROLE_SPEAKER = 'speaker';

    public const string ROLE_PRESENTER = 'presenter';

    public const string ROLE_VOLUNTEER = 'volunteer';

    public const string ROLE_CUSTOM = 'custom';

    public const array ROLES = [
        self::ROLE_DELEGATE,
        self::ROLE_SPEAKER,
        self::ROLE_PRESENTER,
        self::ROLE_VOLUNTEER,
        self::ROLE_CUSTOM,
    ];

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'event_id',
        'role',
        'title',
        'body_template',
        'issuer_name',
        'issuer_title',
        'signature_path',
        'background_path',
        'show_qr',
        'show_cpd_hours',
        'default_cpd_hours',
    ];

    public static function defaultTitle(string $role): string
    {
        return match ($role) {
            self::ROLE_SPEAKER => 'Certificate of Appreciation - Distinguished Speaker',
            self::ROLE_PRESENTER => 'Certificate of Scientific Presentation',
            self::ROLE_VOLUNTEER => 'Certificate of Commendation - Organizing Committee & Volunteer',
            default => 'Certificate of Participation & Attendance',
        };
    }

    public static function defaultBodyTemplate(string $role): string
    {
        return match ($role) {
            self::ROLE_SPEAKER => 'This certificate is proudly conferred upon {name} in deep gratitude for serving as a Distinguished Speaker and delivering expert academic sessions during {event_name} held on {date}.',
            self::ROLE_PRESENTER => 'This certificate is conferred upon {name} in recognition of contributing valuable scientific insights and presenting academic research at {event_name} held on {date}.',
            self::ROLE_VOLUNTEER => 'This certificate acknowledges and honors {name} for dedicated service and exceptional operational support contributing to the success of {event_name} on {date}.',
            default => 'This certifies that {name} has fulfilled all participation requirements and successfully attended {event_name} held on {date}.',
        };
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(EventCertificate::class, 'template_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'show_qr' => 'boolean',
            'show_cpd_hours' => 'boolean',
            'default_cpd_hours' => 'float',
        ];
    }
}
