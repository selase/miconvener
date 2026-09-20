/**
 * Where a section of an event lives. The one place section URLs are built, so
 * moving them is a change here and in routes, nowhere else.
 */
export function sectionHref(eventId, slug) {
    const base = route('tenant.events.show', { event: eventId });
    return slug === 'overview' ? base : `${base}?section=${encodeURIComponent(slug)}`;
}
