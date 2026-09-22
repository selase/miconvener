<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Database\Factories\AttendeeAccessCodeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A one-time code proving someone holds an address with one organiser. Only
 * its hash is stored, so a leaked row proves nothing.
 *
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 */
final class AttendeeAccessCode extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<AttendeeAccessCodeFactory> */
    use HasFactory;

    use HasUuids;

    /** A code is useless after this long, so a forwarded email goes stale. */
    public const int TTL_MINUTES = 15;

    /** Guessing a six-digit code is only hard if guessing is limited. */
    public const int MAX_ATTEMPTS = 5;

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'email',
        'code_hash',
        'attempts',
        'expires_at',
        'consumed_at',
    ];

    /**
     * Six digits: short enough to read out of an email on a phone, and safe
     * because attempts are capped and it expires.
     */
    public static function generateCode(): string
    {
        return mb_str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
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
