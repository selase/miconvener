<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An organiser's own domain registered with SES. See SendingDomainService for
 * how it is set up and checked, and TenantMailIdentity for when mail uses it.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $domain
 * @property string $from_address
 * @property string $status
 * @property bool $owns_ses_identity
 * @property list<array{name: string, type: string, value: string}>|null $dkim_records
 * @property Carbon|null $verified_at
 * @property Carbon|null $last_checked_at
 */
final class TenantSendingDomain extends Model
{
    use HasUuids;

    public const string STATUS_PENDING = 'pending';

    public const string STATUS_VERIFIED = 'verified';

    public const string STATUS_FAILED = 'failed';

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'domain',
        'from_address',
        'status',
        'owns_ses_identity',
        'dkim_records',
        'verified_at',
        'last_checked_at',
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isVerified(): bool
    {
        return $this->status === self::STATUS_VERIFIED;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dkim_records' => 'array',
            'owns_ses_identity' => 'boolean',
            'verified_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }
}
