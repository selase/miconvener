import { currentIdentity } from './attendeeIdentityCache';
const PREFIX = 'miconvener_offline_ticket_';
const DAY = 86400000;
const time = (value) => (typeof value === 'string' ? Date.parse(value) : NaN);
const text = (value, limit = 1000) => (typeof value === 'string' ? value.slice(0, limit) : '');
function valid(pass, proof) {
    if (
        !proof ||
        pass?.schema_version !== 3 ||
        pass.owner_key !== proof.owner_key ||
        pass.proof_expires_at !== proof.proof_expires_at ||
        !['confirmed', 'checked_in'].includes(pass.ticket?.status) ||
        !pass.ticket?.code ||
        !/^data:image\/(svg\+xml|png);base64,[A-Za-z0-9+/=]+$/.test(pass.qr_image || '')
    )
        return false;
    const saved = time(pass.saved_at);
    const confirmed = time(pass.last_confirmed_at);
    const ends = time(pass.event_dates?.ends_at);
    const starts = time(pass.event_dates?.starts_at);
    return (
        Number.isFinite(saved) &&
        Number.isFinite(confirmed) &&
        Number.isFinite(ends) &&
        Number.isFinite(starts) &&
        saved <= Date.now() + 60000 &&
        confirmed <= Date.now() + 60000 &&
        Date.now() < Math.min(proof.proof_expires_at, ends + 7 * DAY, confirmed + 30 * DAY)
    );
}
export function saveOfflineTicket(registration, event, context) {
    const proof = currentIdentity(context);
    if (context?.state !== 'verified' || !proof || !registration?.id || !event) return null;
    const now = new Date().toISOString();
    const pass = {
        schema_version: 3,
        owner_key: proof.owner_key,
        proof_expires_at: proof.proof_expires_at,
        registration_id: text(registration.id, 100),
        attendee_name: text(registration.full_name),
        event_name: text(event.name),
        organiser_name: text(
            event.tenant?.name ?? event.organiser_name ?? registration.tenant_name
        ),
        event_dates: {
            starts_at: event.starts_at,
            ends_at: event.ends_at,
            timezone: text(event.timezone, 100),
        },
        physical_venue_summary:
            event.location_type === 'virtual' ? 'Online event' : text(event.address),
        ticket: {
            code: text(registration.ticket_code),
            type: text(registration.ticket_type_name || 'General admission'),
            status: registration.status,
            seat: text(registration.seat_label, 200),
        },
        qr_image: registration.qr_image,
        saved_at: now,
        last_confirmed_at: now,
    };
    if (!valid(pass, proof)) return null;
    try {
        localStorage.setItem(PREFIX + pass.registration_id, JSON.stringify(pass));
        return pass;
    } catch {
        return null;
    }
}
export function getOfflineTicket(id, context) {
    try {
        const key = PREFIX + id;
        const raw = localStorage.getItem(key);
        if (!raw) return null;
        const pass = JSON.parse(raw);
        if (!valid(pass, currentIdentity(context))) {
            localStorage.removeItem(key);
            return null;
        }
        return pass;
    } catch {
        removeOfflineTicket(id);
        return null;
    }
}
export function listOfflineTickets(context) {
    try {
        const keys = Object.keys(localStorage).filter((key) => key.startsWith(PREFIX));
        return keys
            .map((key) => getOfflineTicket(key.slice(PREFIX.length), context))
            .filter(Boolean)
            .sort((a, b) => a.event_dates.starts_at.localeCompare(b.event_dates.starts_at));
    } catch {
        return [];
    }
}
export function removeOfflineTicket(id) {
    try {
        localStorage.removeItem(PREFIX + id);
    } catch {
        /* Storage may be disabled. */
    }
}
export function hasOfflineTicket(id, context) {
    return Boolean(getOfflineTicket(id, context));
}
export function clearAllOfflineTickets() {
    try {
        Object.keys(localStorage)
            .filter((key) => key.startsWith(PREFIX))
            .forEach((key) => localStorage.removeItem(key));
    } catch {
        /* Storage may be disabled. */
    }
}
export function purgeExpiredOfflineTickets(context) {
    listOfflineTickets(context);
}
