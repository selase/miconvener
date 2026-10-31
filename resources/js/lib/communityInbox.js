import { communityRequest } from './community';
export function inboxRequest(url, method = 'GET', payload, signal) {
    return communityRequest(url, method, payload, { signal });
}
export const inboxBase = (slug) => `/my/communities/${encodeURIComponent(slug)}/inbox`;
