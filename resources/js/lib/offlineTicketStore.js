/**
 * Offline ticket snapshot storage.
 *
 * Per design spec §7.7:
 * - Minimal snapshot containing only the attendee's name, event and organiser name,
 *   event dates/timezone/venue, ticket details, embedded QR image/token, personal agenda,
 *   and timestamps.
 * - Explicitly excludes sensitive/extraneous data (emails, phone, certificates, abstracts, notes).
 * - Purges saved tickets 7 days after event ends (or 30 days after last confirmation if no end date).
 * - Clears all portal-managed offline records on sign-out.
 */

const STORAGE_PREFIX = 'miconvener_offline_ticket_';
const SEVEN_DAYS_MS = 7 * 24 * 60 * 60 * 1000;
const THIRTY_DAYS_MS = 30 * 24 * 60 * 60 * 1000;

function isExpired(snapshot) {
    if (!snapshot) return true;

    const now = Date.now();
    const endsAt = snapshot.event_dates?.ends_at ? new Date(snapshot.event_dates.ends_at).getTime() : null;

    if (endsAt && !isNaN(endsAt)) {
        return now > (endsAt + SEVEN_DAYS_MS);
    }

    const lastConfirmedAt = snapshot.last_confirmed_at ? new Date(snapshot.last_confirmed_at).getTime() : null;
    if (lastConfirmedAt && !isNaN(lastConfirmedAt)) {
        return now > (lastConfirmedAt + THIRTY_DAYS_MS);
    }

    return false;
}

export function saveOfflineTicket(registration, event) {
    if (typeof window === 'undefined' || !window.localStorage || !registration?.id) {
        return null;
    }

    // Filter personal agenda sessions
    const agendaIds = registration.agenda_session_ids ?? [];
    const personalAgenda = (event.sessions ?? [])
        .filter((s) => agendaIds.includes(s.id))
        .map((s) => ({
            id: s.id,
            title: s.title,
            starts_at: s.starts_at,
            ends_at: s.ends_at,
            location: s.location,
            type: s.type,
        }));

    const nowIso = new Date().toISOString();

    const snapshot = {
        registration_id: registration.id,
        attendee_name: registration.full_name,
        event_name: event.name,
        organiser_name: event.tenant?.name ?? registration.tenant_name ?? '',
        event_dates: {
            starts_at: event.starts_at,
            ends_at: event.ends_at,
            timezone: event.timezone,
        },
        venue_summary: event.address ?? event.virtual_link ?? '',
        ticket: {
            code: registration.ticket_code,
            type: registration.ticket_type_name ?? 'General admission',
            status: registration.status,
            seat: registration.seat_label
                ? `${registration.seat_label}, ${registration.room_name}`
                : null,
        },
        qr_image: registration.qr_image ?? null,
        agenda: personalAgenda,
        saved_at: nowIso,
        last_confirmed_at: nowIso,
    };

    try {
        localStorage.setItem(STORAGE_PREFIX + registration.id, JSON.stringify(snapshot));
        return snapshot;
    } catch {
        return null;
    }
}

export function getOfflineTicket(registrationId) {
    if (typeof window === 'undefined' || !window.localStorage || !registrationId) {
        return null;
    }

    try {
        const item = localStorage.getItem(STORAGE_PREFIX + registrationId);
        if (!item) return null;

        const snapshot = JSON.parse(item);
        if (isExpired(snapshot)) {
            removeOfflineTicket(registrationId);
            return null;
        }

        return snapshot;
    } catch {
        return null;
    }
}

export function removeOfflineTicket(registrationId) {
    if (typeof window === 'undefined' || !window.localStorage || !registrationId) {
        return;
    }

    try {
        localStorage.removeItem(STORAGE_PREFIX + registrationId);
    } catch {
        // Ignored
    }
}

export function hasOfflineTicket(registrationId) {
    return Boolean(getOfflineTicket(registrationId));
}

export function clearAllOfflineTickets() {
    if (typeof window === 'undefined' || !window.localStorage) {
        return;
    }

    try {
        const keysToRemove = [];
        for (let i = 0; i < localStorage.length; i++) {
            const key = localStorage.key(i);
            if (key && key.startsWith(STORAGE_PREFIX)) {
                keysToRemove.push(key);
            }
        }
        keysToRemove.forEach((k) => localStorage.removeItem(k));
    } catch {
        // Ignored
    }
}

export function purgeExpiredOfflineTickets() {
    if (typeof window === 'undefined' || !window.localStorage) {
        return;
    }

    try {
        for (let i = 0; i < localStorage.length; i++) {
            const key = localStorage.key(i);
            if (key && key.startsWith(STORAGE_PREFIX)) {
                const id = key.substring(STORAGE_PREFIX.length);
                getOfflineTicket(id); // auto-purges if expired
            }
        }
    } catch {
        // Ignored
    }
}
