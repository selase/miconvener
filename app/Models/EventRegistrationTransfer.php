<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class EventRegistrationTransfer extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    /** A code is useless after this long, so a forwarded email goes stale. */
    public const int TTL_MINUTES = 15;

    /** Guessing a six-digit code is only hard if guessing is limited. */
    public const int MAX_ATTEMPTS = 5;

    /**
     * The code in the clear, held only for the life of the request that creates
     * it so the mailable can render it. It is never persisted -- only its hash
     * is -- so a leaked row cannot complete a transfer.
     */
    public ?string $plainCode = null;

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'registration_id',
        'to_full_name',
        'to_email',
        'to_phone',
        'code_hash',
        'attempts',
        'expires_at',
        'consumed_at',
    ];

    /**
     * Six digits: short enough to read out of an email on a phone at a venue,
     * and safe because attempts are capped and it expires.
     */
    public static function generateCode(): string
    {
        return mb_str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(EventRegistration::class, 'registration_id');
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null
            && $this->expires_at->isFuture()
            && $this->attempts < self::MAX_ATTEMPTS;
    }

    public function matches(string $code): bool
    {
        return Hash::check(Str::of($code)->trim()->toString(), $this->code_hash);
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }
}
