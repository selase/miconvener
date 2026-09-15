<?php

declare(strict_types=1);

namespace App\Checks;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Health\Notifications\Notifiable;

/**
 * Sends health alerts to the application's superadmins: users holding the
 * global Superadmin role (the same test as the access-superadmin-dashboard
 * gate). The configured address is used only if there are none.
 */
final class SuperadminNotifiable extends Notifiable
{
    /**
     * @return list<string>
     */
    public static function superadminEmails(): array
    {
        // model_has_roles.model_id is a string column (roles also attach to
        // uuid-keyed models), so the ids are read first and compared as integers.
        $userIds = DB::connection('landlord')->table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', Role::SYSTEM_ROLES[0])
            ->where('model_has_roles.model_type', User::class)
            ->whereNull('model_has_roles.tenant_id')
            ->pluck('model_has_roles.model_id')
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return User::query()
            ->whereIn('id', $userIds)
            ->pluck('email')
            ->filter()
            ->map(fn (string $email): string => mb_strtolower($email))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return string|array<int, string>
     */
    public function routeNotificationForMail(): string|array
    {
        $emails = self::superadminEmails();

        return $emails !== [] ? $emails : (string) config('health.notifications.mail.to');
    }
}
