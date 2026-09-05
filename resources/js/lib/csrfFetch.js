export function readCookie(name) {
    const match = document.cookie.match(new RegExp(`(^| )${name}=([^;]+)`));
    return match ? decodeURIComponent(match[2]) : null;
}

/**
 * fetch() wrapper for multipart/form-data uploads — omits the JSON
 * Content-Type so the browser can set its own multipart boundary.
 */
export function csrfFetchFormData(url, formData, options = {}) {
    const token = readCookie('XSRF-TOKEN');

    return fetch(url, {
        method: 'POST',
        ...options,
        credentials: 'same-origin',
        body: formData,
        headers: {
            Accept: 'application/json',
            ...(token ? { 'X-XSRF-TOKEN': token } : {}),
            ...options.headers,
        },
    });
}

/** fetch() wrapper that attaches Laravel's XSRF header for POST requests made outside Inertia's router. */
export default function csrfFetch(url, options = {}) {
    const token = readCookie('XSRF-TOKEN');

    return fetch(url, {
        ...options,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            ...(token ? { 'X-XSRF-TOKEN': token } : {}),
            ...options.headers,
        },
    });
}
