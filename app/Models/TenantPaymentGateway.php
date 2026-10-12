<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\EncryptedString;
use App\Traits\BelongsToTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class TenantPaymentGateway extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuid;
    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'provider',
        'provider_account_id',
        'api_key_encrypted',
        'public_key_encrypted',
        'webhook_secret_encrypted',
        'is_active',
        'meta',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'meta' => 'array',
        'api_key_encrypted' => EncryptedString::class,
        'public_key_encrypted' => EncryptedString::class,
        'webhook_secret_encrypted' => EncryptedString::class,
    ];

    /**
     * Scope to only include active gateways.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * The Paystack subaccount that routes the platform's commission, as the
     * tenant entered it.
     */
    public function commissionSubaccountCode(): ?string
    {
        $code = $this->meta['commission_split']['subaccount_code'] ?? null;

        return filled($code) ? (string) $code : null;
    }

    /**
     * Whether the last check found the subaccount live and pointed at the
     * platform's commission account.
     */
    public function commissionSplitIsVerified(): bool
    {
        return ($this->meta['commission_split']['ok'] ?? false) === true;
    }

    public function commissionSplitFailure(): ?string
    {
        return $this->meta['commission_split']['reason'] ?? null;
    }

    /**
     * @param  array{subaccount_code: ?string, ok: bool, reason: ?string}  $state
     */
    public function recordCommissionSplit(array $state): void
    {
        $meta = $this->meta ?? [];
        $meta['commission_split'] = $state + ['checked_at' => now()->toIso8601String()];
        $this->forceFill(['meta' => $meta])->save();
    }
}
