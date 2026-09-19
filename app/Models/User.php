<?php

declare(strict_types=1);

namespace App\Models;

use App\Libraries\Helper;
use App\Traits\HasUuid;
use App\Traits\SpatieActivityLogs;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Propaganistas\LaravelPhone\Casts\E164PhoneNumberCast;
use Spatie\Permission\Traits\HasRoles;

final class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use HasRoles;
    use HasUuid;
    use Notifiable;
    use SpatieActivityLogs;
    // use BelongsToTenant;

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_INACTIVE = 'inactive';

    public const array STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
    ];

    /**
     * Single-use codes that stand in for the authenticator app, so losing a
     * phone is not the same as losing the account.
     */
    public const int RECOVERY_CODE_COUNT = 8;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'first_name',
        'last_name',
        'email',
        'password',
        'phone_no',
        'status',
        'last_login_at',
        'last_login_ip',
        'photo',
        'tenant_id',
        'two_factor_secret',
        'two_factor_confirmed_at',
        'two_factor_recovery_codes',
        'notification_preferences',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'id' => 'string',
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'phone_no' => E164PhoneNumberCast::class.':GH',
        'two_factor_confirmed_at' => 'datetime',
        // The secret itself is deliberately left as-is: it is already stored
        // plaintext for the accounts that enrolled before this, and encrypting
        // the column would lock every one of them out.
        'two_factor_recovery_codes' => 'encrypted:array',
        'notification_preferences' => 'array',
    ];

    /**
     * Issue a fresh set, replacing any that already exist.
     *
     * @return array<int, string> the plaintext codes, shown to the user once
     */
    public function generateTwoFactorRecoveryCodes(): array
    {
        $codes = collect(range(1, self::RECOVERY_CODE_COUNT))
            ->map(fn (): string => Str::random(10).'-'.Str::random(10))
            ->all();

        $this->forceFill(['two_factor_recovery_codes' => $codes])->save();

        return $codes;
    }

    /**
     * Spend one code. Each works exactly once.
     */
    public function useTwoFactorRecoveryCode(string $code): bool
    {
        $codes = $this->two_factor_recovery_codes ?? [];

        $matched = null;
        foreach ($codes as $stored) {
            if (is_string($stored) && hash_equals($stored, $code)) {
                $matched = $stored;
                break;
            }
        }

        if ($matched === null) {
            return false;
        }

        $this->forceFill([
            'two_factor_recovery_codes' => array_values(
                array_filter($codes, fn (mixed $stored): bool => $stored !== $matched)
            ),
        ])->save();

        return true;
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class);
    }

    public function displayName(): string
    {
        return $this->first_name.' '.$this->last_name;
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function lastLogin(): string
    {
        return $this->last_login_at ? Helper::getReadableDate($this->last_login_at) : '-';
    }

    public function isGlobalSuperAdmin(): bool
    {
        // Use the 'landlord' connection explicitly
        return DB::connection('landlord')->table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_type', self::class)
            // Fix: The table uses 'model_id' and stores the INT ID of the user, not the UUID
            ->where('model_has_roles.model_id', (string) $this->id)
            ->where('roles.name', 'Superadmin')
            ->whereNull('model_has_roles.tenant_id')
            ->exists();
    }

    /**
     * Override getKey to return string for PostgreSQL polymorphic compatibility.
     * This ensures that when this model is used in model_has_roles/permissions (which use a string model_id),
     * PostgreSQL doesn't fail due to type mismatch.
     */
    public function getKey()
    {
        $key = parent::getKey();

        return is_null($key) ? $key : (string) $key;
    }

    /**
     * Get the user's full name.
     */
    protected function name(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->first_name.' '.$this->last_name,
        );
    }

    /**
     * Get the user's avatar.
     */
    protected function gravatar(): Attribute
    {
        return Attribute::make(
            get: fn (): string => Helper::generateGravatar($this->email),
        );
    }
}
