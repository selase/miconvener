const KEY = 'miconvener_attendee_identity_v3';
const PREFIX = 'miconvener_offline_ticket_';
export const IDENTITY_EVENT = 'miconvener:attendee-identity';
export function storedIdentity() {
    try {
        const value = JSON.parse(localStorage.getItem(KEY));
        return validIdentity(value) ? value : null;
    } catch {
        return null;
    }
}
export function validIdentity(value) {
    return (
        value?.state === 'verified' &&
        /^[a-f0-9]{64}$/.test(value.owner_key) &&
        Number.isFinite(value.proof_expires_at) &&
        value.proof_expires_at > Date.now()
    );
}
function purgePasses() {
    try {
        Object.keys(localStorage)
            .filter((key) => key.startsWith(PREFIX))
            .forEach((key) => localStorage.removeItem(key));
    } catch {
        /* Storage may be disabled. */
    }
}
export function clearAttendeeIdentity() {
    purgePasses();
    try {
        localStorage.removeItem(KEY);
    } catch {
        /* Storage may be disabled. */
    }
    if (typeof window !== 'undefined') window.dispatchEvent(new Event(IDENTITY_EVENT));
    if (typeof BroadcastChannel !== 'undefined') {
        const channel = new BroadcastChannel('miconvener-attendee-identity');
        channel.postMessage('changed');
        channel.close();
    }
}
export function reconcileAttendeeIdentity(context) {
    if (context?.state === 'unknown_offline') {
        const stored = storedIdentity();
        if (!stored) clearAttendeeIdentity();
        return stored;
    }
    if (!validIdentity(context)) {
        clearAttendeeIdentity();
        return null;
    }
    const previous = storedIdentity();
    if (
        !previous ||
        previous.owner_key !== context.owner_key ||
        previous.proof_expires_at !== context.proof_expires_at
    )
        purgePasses();
    try {
        localStorage.setItem(
            KEY,
            JSON.stringify({
                state: 'verified',
                owner_key: context.owner_key,
                proof_expires_at: context.proof_expires_at,
            })
        );
    } catch {
        return null;
    }
    return context;
}
export function currentIdentity(context) {
    const stored = storedIdentity();
    if (!stored) return null;
    if (context?.state === 'unknown_offline' || !context) return stored;
    return validIdentity(context) &&
        context.owner_key === stored.owner_key &&
        context.proof_expires_at === stored.proof_expires_at
        ? stored
        : null;
}
export function isIdentityStorageEvent(event) {
    return event.key === KEY || event.key === null;
}
