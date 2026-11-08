<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\Event;
use Illuminate\Validation\ValidationException;

final class RegistrationQuota
{
    /** Acquired only after event/business locks; held until the caller commits. */
    public function acquire(Event $event): void
    {
        $db = $event->getConnection();
        $db->table('event_registration_quota_locks')->insertOrIgnore(['tenant_id' => $event->tenant_id]);
        $db->table('event_registration_quota_locks')->where('tenant_id', $event->tenant_id)->lockForUpdate()->first();
    }

    public function hasRoom(Event $event): bool
    {
        if ($event->isGrandfathered()) {
            return true;
        }
        $tenant = $event->tenant;
        $limit = $tenant->featureLimitValue('event_registrations');
        if ($limit === null) {
            return true;
        }
        $used = (int) $tenant->usage()->where('feature_slug', 'event_registrations')->whereNull('period_start')->sum('used_count');

        return $used < $limit;
    }

    public function assertRoom(Event $event): void
    {
        if (! $this->hasRoom($event)) {
            throw ValidationException::withMessages(['rows' => 'The organisation registration allowance is exhausted.']);
        }
    }
}
