/**
 * Props shaped like PublicEventController::confirmation sends them. Override
 * only what a test is about.
 */
export function portalProps(overrides = {}) {
    return {
        event: {
            slug: 'science-congress',
            name: 'National Science Congress',
            sessions: [
                {
                    id: 'sess-1',
                    title: 'Opening Plenary',
                    starts_at: '2026-10-01T09:00:00+00:00',
                    ends_at: '2026-10-01T10:00:00+00:00',
                    location: 'Main Hall',
                    capacity: null,
                    signup_count: 0,
                },
            ],
            ...overrides.event,
        },
        registration: {
            id: 'reg-1',
            full_name: 'Ama Serwaa',
            email: 'am•@stem.org',
            status: 'confirmed',
            ticket_code: 'EVT-ABCD-123',
            ticket_type_name: 'Delegate',
            qr_image: 'data:image/svg+xml;base64,PHN2Zy8+',
            agenda_session_ids: [],
            waitlist_position: null,
            approval_note: null,
            seat_label: null,
            room_name: null,
            checked_in: false,
            email_verified: true,
            awaiting_checkout: false,
            checkout_url: null,
            ...overrides.registration,
        },
        materials: overrides.materials ?? [],
        canRequestHelp: overrides.canRequestHelp ?? false,
    };
}

export const keynote = { id: 'm1', title: 'Keynote', remaining_attempts: 3, download_url: '/dl/m1' };
