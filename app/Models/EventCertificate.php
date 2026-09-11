<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class EventCertificate extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'event_id',
        'registration_id',
        'user_id',
        'template_id',
        'recipient_name',
        'recipient_email',
        'role',
        'cpd_hours',
        'verification_code',
        'issued_at',
        'download_count',
    ];

    public function verificationUrl(): string
    {
        return url('/verify/cert/'.$this->uuid);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(EventRegistration::class, 'registration_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(EventCertificateTemplate::class, 'template_id');
    }

    protected static function booted(): void
    {
        self::creating(function (self $cert): void {
            if (empty($cert->uuid)) {
                $cert->uuid = (string) Str::uuid();
            }

            if (empty($cert->verification_code)) {
                $cert->verification_code = 'MC-'.mb_strtoupper(Str::random(8));
            }

            if (empty($cert->issued_at)) {
                $cert->issued_at = now();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cpd_hours' => 'float',
            'issued_at' => 'datetime',
            'download_count' => 'integer',
        ];
    }
}
