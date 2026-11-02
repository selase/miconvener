import { formatInZone } from '@/lib/venueTime';

/** Which visual treatment a host-calendar entry gets. */
export function calendarEventTone(event) {
    if (event.approval_flow && event.status === 'pending_host_approval') {
        return 'request';
    }
    if (event.approval_flow && event.status === 'approved_hold') {
        return 'hold';
    }
    if (event.kind === 'internal') {
        return 'internal';
    }
    if (event.status === 'confirmed') {
        return 'confirmed';
    }
    if (event.is_blocked) {
        return 'blocked';
    }

    return 'pending';
}

/** A short line saying whether an approval-first entry actually holds its dates. */
export function calendarEventNote(event) {
    if (!event.approval_flow) {
        return null;
    }
    if (event.status === 'pending_host_approval') {
        return 'Request, dates not held';
    }
    if (event.status === 'approved_hold' && event.hold_expires_at) {
        return `Held until ${formatInZone(event.hold_expires_at, event.timezone || 'UTC')}`;
    }

    return null;
}
