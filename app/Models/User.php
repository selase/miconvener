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
        'notification_preferences' => 'array',
    ];

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
