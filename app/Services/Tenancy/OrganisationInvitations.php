<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enum\TenantStatusEnum;
use App\Mail\Users\OrganisationInvitationMail;
use App\Models\Event;
use App\Models\OrganisationInvitation;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

final class OrganisationInvitations
{
    public function __construct(private readonly OrganisationMemberships $memberships) {}

    /** @param list<string> $eventIds */
    public function invite(Tenant $tenant, User $actor, string $email, Role $role, array $eventIds): OrganisationInvitation
    {
        abort_unless(config('organisation_access.enabled', false), 503, 'Organisation invitations are currently paused.');
        $email = mb_strtolower(mb_trim($email));
        throw_unless(filter_var($email, FILTER_VALIDATE_EMAIL) && mb_strlen($email) <= 255, ValidationException::withMessages(['email' => 'Enter a valid email address.']));

        return $this->locked($tenant, function (Tenant $tenant) use ($actor, $email, $role, $eventIds): OrganisationInvitation {
            return $this->memberships->inTenant($tenant, [$actor], function () use ($tenant, $actor, $email, $role, $eventIds): OrganisationInvitation {
                $this->authorize($tenant, $actor);
                $role = $this->grants($tenant, $actor, $role->id, $eventIds);
                $this->expireReservations($tenant, $email);
                $pending = OrganisationInvitation::query()->where('tenant_id', $tenant->id)->where('pending_email', $email)->first();
                if ($pending) {
                    return $pending;
                }
                $subject = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
                $membership = $subject ? TenantMembership::query()->where('tenant_id', $tenant->id)->where('user_id', $subject->id)->first() : null;
                if ($membership?->status === 'suspended') {
                    throw ValidationException::withMessages(['email' => 'Manage suspended access from the existing membership.']);
                }
                $active = $membership?->status === 'active';
                if (! $active) {
                    $limit = $tenant->featureLimitValue('team_seats');
                    $occupied = TenantMembership::query()->where('tenant_id', $tenant->id)->whereIn('status', ['active', 'suspended'])->count();
                    if ($limit !== null && $occupied + $this->reservedSeats($tenant) >= $limit) {
                        throw ValidationException::withMessages(['email' => 'There are no available team seats.']);
                    }
                    $this->sendLimits($tenant, $actor, $email);
                }
                $token = bin2hex(random_bytes(32));
                $invitation = OrganisationInvitation::query()->create([
                    'tenant_id' => $tenant->id, 'email' => $email, 'pending_email' => $active ? null : $email,
                    'invited_user_id' => $subject?->id, 'invited_security_version' => $subject ? (int) $subject->security_version : null,
                    'role_id' => $role->id, 'event_ids' => array_values(array_unique($eventIds)), 'invited_by' => $actor->id,
                    'status' => $active ? 'accepted' : 'pending', 'expires_at' => now()->addDays(7),
                    'token_hash' => $active ? null : hash('sha256', $token), 'delivery_payload' => $active ? null : Crypt::encryptString($token),
                    'delivery_status' => $active ? 'not_required' : 'pending', 'accepted_at' => $active ? now() : null,
                    'accepted_by' => $active ? $subject->id : null,
                ]);
                if (! $active) {
                    $this->queue($invitation);
                }

                return $invitation;
            });
        });
    }

    public function accept(#[SensitiveParameter] string $token, User $actor): TenantMembership
    {
        abort_unless(config('organisation_access.enabled', false), 503, 'Organisation invitations are currently paused.');
        $invitation = $this->pendingToken($token);
        $this->acceptLimits($invitation, $actor);

        return $this->locked($invitation->tenant, function (Tenant $tenant) use ($invitation, $token, $actor): TenantMembership {
            $invitation = OrganisationInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            $this->assertPending($invitation, $token);
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->email_verified_at && mb_strtolower($actor->email) === $invitation->email && in_array($actor->status, [null, User::STATUS_ACTIVE], true), 403);
            abort_if($invitation->invited_user_id !== null && ((string) $invitation->invited_user_id !== (string) $actor->id || (int) $invitation->invited_security_version !== (int) $actor->security_version), 403);
            if ($tenant->require_2fa || ($actor->two_factor_secret && $actor->two_factor_confirmed_at)) {
                abort_unless($actor->two_factor_secret && $actor->two_factor_confirmed_at && request()->hasSession() && request()->session()->has('google2fa'), 403);
            }
            $inviter = User::query()->findOrFail($invitation->invited_by);

            return $this->memberships->inTenant($tenant, [$inviter, $actor], function () use ($tenant, $invitation, $inviter, $actor): TenantMembership {
                $this->authorize($tenant, $inviter);
                $role = $this->grants($tenant, $inviter, $invitation->role_id, $invitation->event_ids);
                $existing = TenantMembership::query()->where('tenant_id', $tenant->id)->where('user_id', $actor->id)->first();
                if ($existing?->status === 'suspended') {
                    throw ValidationException::withMessages(['invitation' => 'An administrator must restore suspended access.']);
                }
                // An unexpired reservation survives a downgrade; replacing it is never a net-new seat.
                $membership = $existing?->status === 'active' ? $existing : $this->memberships->admit($tenant, $inviter, $actor, $role, $invitation->event_ids);
                $invitation->forceFill(['status' => 'accepted', 'pending_email' => null, 'accepted_by' => $actor->id, 'accepted_at' => now(), 'token_hash' => null, 'delivery_payload' => null])->save();

                return $membership;
            });
        });
    }

    public function resend(OrganisationInvitation $invitation, User $actor): void
    {
        $this->manage($invitation, $actor, function (OrganisationInvitation $invitation, Tenant $tenant) use ($actor): void {
            $this->assertPending($invitation);
            abort_if(in_array($invitation->delivery_status, ['sending', 'uncertain'], true), 409, 'Reconcile the previous delivery before sending again.');
            $this->grants($tenant, $actor, $invitation->role_id, $invitation->event_ids);
            if ($invitation->invited_user_id !== null) {
                $subject = User::query()->whereKey($invitation->invited_user_id)->lockForUpdate()->firstOrFail();
                abort_unless(mb_strtolower($subject->email) === $invitation->email, 403, 'Revoke this invitation and issue a fresh one for the intended identity.');
                $invitation->invited_security_version = (int) $subject->security_version;
            }
            $this->sendLimits($tenant, $actor, $invitation->email);
            $token = bin2hex(random_bytes(32));
            $invitation->forceFill(['invited_by' => $actor->id, 'revision' => $invitation->revision + 1, 'token_hash' => hash('sha256', $token), 'delivery_payload' => Crypt::encryptString($token), 'delivery_status' => 'pending', 'delivery_claimed_at' => null, 'delivered_at' => null])->save();
            $this->queue($invitation);
        });
    }

    public function revoke(OrganisationInvitation $invitation, User $actor): void
    {
        $this->manage($invitation, $actor, function (OrganisationInvitation $invitation) use ($actor): void {
            abort_unless($invitation->status === 'pending', 409);
            $invitation->forceFill(['status' => 'revoked', 'pending_email' => null, 'token_hash' => null, 'delivery_payload' => null, 'revoked_at' => now(), 'revoked_by' => $actor->id])->save();
        });
    }

    public function pendingToken(#[SensitiveParameter] string $token): OrganisationInvitation
    {
        abort_unless(preg_match('/\A[a-f0-9]{64}\z/D', $token), 404);
        $invitation = OrganisationInvitation::query()->where('token_hash', hash('sha256', $token))->first();
        abort_unless($invitation !== null, 404);
        $this->assertPending($invitation, $token);

        return $invitation;
    }

    public function reservedSeats(Tenant $tenant): int
    {
        return OrganisationInvitation::query()->where('tenant_id', $tenant->id)->where('status', 'pending')->where('expires_at', '>', now())->count();
    }

    /** Recover bounded untouched sends; interrupted transport requires operator reconciliation. */
    public function maintain(Tenant $tenant): bool
    {
        return $this->locked($tenant, function (Tenant $tenant): bool {
            $base = OrganisationInvitation::query()->where('tenant_id', $tenant->id);
            $expired = (clone $base)->where('status', 'pending')->where('expires_at', '<=', now())->orderBy('id')->limit(100)->lockForUpdate()->pluck('id');
            OrganisationInvitation::query()->whereKey($expired)->update(['status' => 'expired', 'pending_email' => null, 'token_hash' => null, 'delivery_payload' => null]);
            $interrupted = (clone $base)->where('delivery_status', 'sending')->where('delivery_claimed_at', '<=', now()->subMinutes(10))->orderBy('id')->limit(100)->lockForUpdate()->pluck('id');
            OrganisationInvitation::query()->whereKey($interrupted)->update(['delivery_status' => 'uncertain', 'delivery_payload' => null]);
            $oldSecrets = (clone $base)->where('created_at', '<=', now()->subDays(30))->where(fn ($query) => $query->whereNotNull('token_hash')->orWhereNotNull('delivery_payload'))->orderBy('id')->limit(100)->lockForUpdate()->pluck('id');
            OrganisationInvitation::query()->whereKey($oldSecrets)->update(['delivery_payload' => null, 'token_hash' => null]);
            $untouched = (clone $base)->where('status', 'pending')->where('expires_at', '>', now())->where('delivery_status', 'pending')->whereNotNull('delivery_payload')->where('updated_at', '<=', now()->subMinutes(5))->orderBy('id')->limit(100)->lockForUpdate()->get();
            foreach ($untouched as $invitation) {
                $invitation->touch();
                $this->queue($invitation);
            }

            return max($expired->count(), $interrupted->count(), $oldSecrets->count(), $untouched->count()) === 100;
        }, false);
    }

    /** An operator supplies only an externally confirmed delivery outcome; this never sends mail. */
    public function reconcileDelivery(Tenant $tenant, string $invitationId, string $outcome): void
    {
        abort_unless(in_array($outcome, ['sent', 'refused'], true), 422);
        $this->locked($tenant, function (Tenant $tenant) use ($invitationId, $outcome): void {
            $invitation = OrganisationInvitation::query()->where('tenant_id', $tenant->id)->whereKey($invitationId)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($invitation->delivery_status, ['sending', 'uncertain'], true), 409);
            $invitation->forceFill(['delivery_status' => $outcome, 'delivery_payload' => null, 'delivered_at' => $outcome === 'sent' ? now() : null])->save();
        }, false);
    }

    private function assertPending(OrganisationInvitation $invitation, #[SensitiveParameter] ?string $token = null): void
    {
        abort_unless($invitation->status === 'pending' && $invitation->expires_at->isFuture(), 410);
        abort_if($token !== null && ! hash_equals((string) $invitation->token_hash, hash('sha256', $token)), 410);
    }

    /**
     * @template TResult
     *
     * @param  Closure(Tenant): TResult  $operation
     * @return TResult
     */
    private function locked(Tenant $tenant, Closure $operation, bool $requireActive = true): mixed
    {
        return DB::connection('landlord')->transaction(function () use ($tenant, $operation, $requireActive): mixed {
            $tenant = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            abort_if($requireActive && $tenant->status !== TenantStatusEnum::ACTIVE, 403);

            return $operation($tenant);
        });
    }

    private function authorize(Tenant $tenant, User $actor): void
    {
        abort_unless(in_array($actor->fresh()->status, [null, User::STATUS_ACTIVE], true), 403);
        abort_if($actor->hasRole('Event Organiser') && ! $actor->hasAnyRole(['Org Superadmin', 'Org Admin']) && ! $actor->isGlobalSuperAdmin(), 403);
        abort_unless($actor->isGlobalSuperAdmin() || ($this->memberships->isActive($actor, $tenant) && $actor->can('create user')), 403);
    }

    /** @param list<string> $eventIds */
    private function grants(Tenant $tenant, User $actor, int|string $roleId, array $eventIds): Role
    {
        $role = Role::query()->findOrFail($roleId);
        $this->memberships->authorizeRole($tenant, $actor, $role);
        $ids = array_values(array_unique($eventIds));
        abort_unless(Event::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereNull('deleted_at')->whereIn('id', $ids)->count() === count($ids), 422);

        return $role;
    }

    /** @param Closure(OrganisationInvitation, Tenant): void $operation */
    private function manage(OrganisationInvitation $invitation, User $actor, Closure $operation): void
    {
        $this->locked($invitation->tenant, function (Tenant $tenant) use ($invitation, $actor, $operation): void {
            $this->memberships->inTenant($tenant, [$actor], function () use ($invitation, $tenant, $actor, $operation): void {
                $this->authorize($tenant, $actor);
                $current = OrganisationInvitation::query()->whereKey($invitation->id)->where('tenant_id', $tenant->id)->lockForUpdate()->firstOrFail();
                $this->memberships->authorizeRole($tenant, $actor, Role::query()->findOrFail($current->role_id));
                $operation($current, $tenant);
            });
        });
    }

    private function expireReservations(Tenant $tenant, string $email): void
    {
        OrganisationInvitation::query()->where('tenant_id', $tenant->id)->where('pending_email', $email)->where('status', 'pending')->where('expires_at', '<=', now())->update(['status' => 'expired', 'pending_email' => null, 'token_hash' => null, 'delivery_payload' => null]);
    }

    private function queue(OrganisationInvitation $invitation): void
    {
        $this->memberships->inTenant($invitation->tenant, [], function () use ($invitation): void {
            Mail::to($invitation->email)->queue((new OrganisationInvitationMail($invitation->id, $invitation->revision))->afterCommit());
        });
    }

    private function sendLimits(Tenant $tenant, User $actor, string $email): void
    {
        $this->limits([
            ['sender:'.$actor->id, 20], ['tenant:'.$tenant->id, 50], ['recipient:'.hash('sha256', $email), 3],
            ['ip:'.request()->ip(), 60], ['application', 100],
        ]);
    }

    private function acceptLimits(OrganisationInvitation $invitation, User $actor): void
    {
        $this->limits([['accept:actor:'.$actor->id, 20], ['accept:tenant:'.$invitation->tenant_id, 50], ['accept:recipient:'.hash('sha256', $invitation->email), 3], ['ip:'.request()->ip(), 60]]);
    }

    /** @param list<array{string, int}> $limits */
    private function limits(array $limits): void
    {
        foreach ($limits as [$key, $limit]) {
            abort_if(RateLimiter::tooManyAttempts('organisation-invitations:'.$key, $limit), 429);
        }
        foreach ($limits as [$key]) {
            RateLimiter::hit('organisation-invitations:'.$key, 3600);
        }
    }
}
