/**
 * Where a just-verified visitor may be sent back to: a path on the attendee
 * portal, or the private page of one venue request. Anything else is ignored,
 * so a crafted ?return_to cannot bounce a verified visitor elsewhere.
 */
const ALLOWED = /^\/(my(\/|$)|marketplace\/bookings\/[A-Za-z0-9-]+$)/;

export function safePortalReturn(requested) {
    if (typeof requested !== 'string' || requested.startsWith('//') || requested.includes('\\')) {
        return null;
    }

    return ALLOWED.test(requested) ? requested : null;
}
