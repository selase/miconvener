<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PlatformAttendeeAccessCodeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A one-time code proving someone controls an email address across the platform.
 * Landlord model with no tenant scoping. Only its bcrypt hash is stored.
 *
 * @property string $id
 * @property string $email_normalized
 * @property string $code_hash
 * @property int $attempts
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class PlatformAttendeeAccessCode extends Model
{
    /** @use HasFactory<PlatformAttendeeAccessCodeFactory> */
    use HasFactory;

    use HasUuids;
    use MassPrunable;

    /** A code is useless after this long, so a forwarded email goes stale. */
    public const int TTL_MINUTES = 15;

    /** Guessing a six-digit code is only hard if guessing is limited. */
    public const int MAX_ATTEMPTS = 5;

    protected $connection = 'landlord';

    protected $table = 'platform_attendee_access_codes';

    protected $fillable = [
        'email_normalized',
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

    /**
     * Get the prunable model query.
     * Consumed codes may be removed after one day; expired unconsumed codes are immediately prunable.
     *
     * @return Builder<PlatformAttendeeAccessCode>
     */
    public function prunable(): Builder
    {
        return self::query()
            ->where(function (Builder $query): void {
                $query->whereNotNull('consumed_at')
                    ->where('consumed_at', '<=', now()->subDay());
            })
            ->orWhere(function (Builder $query): void {
                $query->whereNull('consumed_at')
                    ->where('expires_at', '<=', now());
            });
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
