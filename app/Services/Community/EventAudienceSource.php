<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Models\Event;
use App\Models\EventBlast;
use App\Models\Tenant;
use App\Scopes\TenantScope;

final class EventAudienceSource implements AudienceSource
{
    /** @param array<string, mixed> $selector
     * @return iterable<AudienceAddress>
     */
    public function addresses(Tenant $tenant, array $selector, string $channel): iterable
    {
        $event = Event::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->findOrFail($selector['event_id']);
        foreach (EventBlast::audienceQuery($event, $selector['event_audience'])->lazyById(100) as $registration) {
            $address = $channel === 'email' ? $registration->email : $registration->phone;
            if (filled($address)) {
                yield new AudienceAddress($address, $channel);
            }
        }
    }
}
