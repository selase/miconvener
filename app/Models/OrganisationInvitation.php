<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $email
 * @property int $role_id
 * @property list<string> $event_ids
 * @property string $status
 * @property \Carbon\CarbonImmutable $expires_at
 * @property string|null $token_hash
 * @property int|string $invited_by
 * @property string|null $invited_user_id
 * @property int|null $invited_security_version
 * @property string|null $pending_email
 * @property int $revision
 * @property string|null $delivery_payload
 * @property string $delivery_status
 * @property \Carbon\CarbonImmutable|null $delivery_claimed_at
 * @property \Carbon\CarbonImmutable|null $delivered_at
 * @property \Carbon\CarbonImmutable|null $accepted_at
 * @property int|string|null $accepted_by
 * @property \Carbon\CarbonImmutable|null $revoked_at
 * @property int|string|null $revoked_by
 * @property \Carbon\CarbonInterface|null $created_at
 * @property \Carbon\CarbonInterface|null $updated_at
 * @property-read Tenant $tenant
 * @property-read User $inviter
 * @property-read Role $role
 */
final class OrganisationInvitation extends Model
{
    use HasUuids;

    protected $connection = 'landlord';

    protected $guarded = [];

    protected $attributes = ['revision' => 1, 'delivery_status' => 'pending'];

    protected $hidden = ['token_hash', 'delivery_payload', 'pending_email', 'invited_security_version'];

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['revision' => 'integer', 'invited_user_id' => 'string', 'invited_security_version' => 'integer', 'event_ids' => 'array', 'expires_at' => 'immutable_datetime', 'accepted_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime', 'delivery_claimed_at' => 'immutable_datetime', 'delivered_at' => 'immutable_datetime'];
    }
}
