import csrfFetch from '@/lib/csrfFetch';

export async function communityRequest(url, method = 'GET', payload, { signal } = {}) {
    const response = await csrfFetch(url, {
        method,
        ...(signal ? { signal } : {}),
        cache: 'no-store',
        ...(payload === undefined ? {} : { body: JSON.stringify(payload) }),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        const fallback = {
            401: 'Your verification expired. Verify again to continue.',
            403: 'Your access changed. Refresh this page before continuing.',
            404: 'This item is no longer available to this verified contact.',
            409: 'This changed while you were reviewing it. Refresh and review it again.',
            410: 'This request has already been used or expired.',
            429: 'Too many attempts. Wait before trying again.',
            503: 'This service is temporarily unavailable. Try email or return later.',
        };
        const message =
            response.status >= 500
                ? (fallback[response.status] ?? 'Something went wrong. Please try again later.')
                : (Object.values(data.errors ?? {}).flat()[0] ??
                  ([401, 403, 404, 410, 429].includes(response.status)
                      ? fallback[response.status]
                      : data.message) ??
                  fallback[response.status] ??
                  'Please check the form and try again.');
        const error = new Error(message);
        error.status = response.status;
        throw error;
    }
    return data;
}
