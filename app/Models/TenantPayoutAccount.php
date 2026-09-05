<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\EncryptedString;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class TenantPayoutAccount extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    public const string TYPE_MOBILE_MONEY = 'mobile_money';

    public const string TYPE_BANK = 'bank';

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'type',
        'bank_code',
        'label',
        'account_name',
        'account_number_encrypted',
        'is_verified',
        'recipient_code',
        'resolved_account_name',
    ];

    protected $casts = [
        'is_verified' => 'boolean',
        'account_number_encrypted' => EncryptedString::class,
    ];

    protected $hidden = [
        'account_number_encrypted',
    ];

    public function payouts(): HasMany
    {
        return $this->hasMany(EventPayout::class, 'payout_account_id');
    }

    public function maskedAccountNumber(): string
    {
        $number = (string) $this->account_number_encrypted;

        return '•••• '.mb_substr($number, -4);
    }
}
