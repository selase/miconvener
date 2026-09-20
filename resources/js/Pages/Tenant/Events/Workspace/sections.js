/**
 * Where a section of an event lives. The one place section URLs are built, so
 * moving them is a change here and in routes, nowhere else.
 */
export function sectionHref(eventId, slug) {
    return slug === 'overview'
        ? route('tenant.events.show', { event: eventId })
        : route('tenant.events.section', { event: eventId, section: slug });
}
