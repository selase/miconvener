import csrfFetch from '@/lib/csrfFetch';

/** "GHS 50.00" from minor units, as the event Finance panel writes it. */
export function formatDuesMoney(amount, currency) {
    return `${currency} ${(Number(amount) / 100).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`;
}

/**
 * A member's or a membership type's dues: "No dues" when the type carries
 * none (null), so it never reads like a waived GHS 0.00.
 */
export function formatMemberDues(amount, currency) {
    return amount === null || amount === undefined ? 'No dues' : formatDuesMoney(amount, currency);
}

/**
 * Minor units from what the user typed in major units. A blank box is
 * null (no value); anything that is not a non-negative number is NaN so the
 * caller can refuse it instead of sending a wrong amount.
 */
export function toMinorUnits(input) {
    const text = String(input ?? '').trim();

    if (text === '') {
        return null;
    }

    if (!/^\d+(\.\d{1,2})?$/.test(text)) {
        return Number.NaN;
    }

    return Math.round(parseFloat(text) * 100);
}

/** Major units for an edit box, from minor units. */
export function toMajorUnits(amount) {
    return amount === null || amount === undefined ? '' : (amount / 100).toFixed(2);
}

/**
 * Sends a JSON request outside Inertia's router. Resolves to
 * { ok, status, body, errors } where errors maps each invalid field to its
 * first message and message is the sentence to show the user on failure.
 */
export async function sendJson(method, url, payload) {
    let response;

    try {
        response = await csrfFetch(url, {
            method,
            body: payload === undefined ? undefined : JSON.stringify(payload),
        });
    } catch {
        return {
            ok: false,
            status: 0,
            body: {},
            errors: {},
            message: 'Could not reach the server. Check your connection and try again.',
        };
    }

    const body = await response.json().catch(() => ({}));
    const errors = Object.fromEntries(
        Object.entries(body.errors ?? {}).map(([field, messages]) => [field, messages[0]])
    );

    return {
        ok: response.ok,
        status: response.status,
        body,
        errors,
        message: response.ok ? null : (body.message ?? 'Something went wrong. Please try again.'),
    };
}
