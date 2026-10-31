import React, { useState } from 'react';
import { render, screen, fireEvent, within, act } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import Show from '@/Pages/Public/Events/Show';
import LiveEventAccess from '@/Pages/Public/Events/LiveEventAccess';
import { router } from '@inertiajs/react';

const { submitted } = vi.hoisted(() => ({ submitted: vi.fn() }));
vi.mock('@inertiajs/react', () => ({
    router: { reload: vi.fn() },
    Link: ({ href, children, ...props }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    Head: () => null,
    usePage: () => ({ props: { flash: {} } }),
    useForm: (initial) => {
        const [data, update] = useState(initial);
        return {
            data,
            setData: (key, value) =>
                update((previous) =>
                    typeof key === 'string'
                        ? { ...previous, [key]: value }
                        : typeof key === 'function'
                          ? key(previous)
                          : key
                ),
            errors: {},
            processing: false,
            post: (url) => submitted(url, data),
        };
    },
}));
vi.mock('@/lib/csrfFetch', () => ({
    default: vi.fn(async () => ({ ok: true, json: async () => [] })),
    csrfFetchFormData: vi.fn(),
}));
const event = {
    slug: 'conference',
    name: 'A real conference',
    description: 'Meet, learn and connect.',
    starts_at: '2026-11-01T09:00:00Z',
    ends_at: '2026-11-01T17:00:00Z',
    timezone: 'Africa/Accra',
    location_type: 'in_person',
    address: 'Accra Conference Hall',
    currency: 'GHS',
    ticket_price: 0,
    ticket_types: [],
    form_fields: [],
    sponsors: [{ id: 1, name: 'Our sponsor' }],
    going_count: 12,
    sessions: [
        {
            id: 1,
            title: 'Opening session',
            starts_at: '2026-11-01T09:00:00Z',
            ends_at: '2026-11-01T10:00:00Z',
            type: 'talk',
        },
    ],
    speakers: [{ id: 1, name: 'Dr Example', bio: 'Expert speaker.' }],
    plan_your_visit_content: 'Take the north entrance.',
};
const org = {
    name: 'Our organisation',
    description: 'We bring people together.',
    contact_email: 'hello@example.test',
    links: { website: 'https://example.test', instagram: 'https://instagram.com/example' },
};

describe('public event presentation', { timeout: 30000 }, () => {
    it('refreshes activity availability only while visible and online and stops after leaving', () => {
        vi.useFakeTimers();
        const visible = vi.spyOn(document, 'hidden', 'get').mockReturnValue(false);
        const online = vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(false);
        try {
            const view = render(
                <LiveEventAccess participation={{ entry_url: '/my/join/event-1' }} />
            );
            act(() => vi.advanceTimersByTime(30000));
            expect(router.reload).not.toHaveBeenCalled();
            online.mockReturnValue(true);
            act(() => vi.advanceTimersByTime(30000));
            expect(router.reload).toHaveBeenCalledTimes(1);
            expect(router.reload).toHaveBeenCalledWith({ only: ['participation'] });
            visible.mockReturnValue(true);
            act(() => vi.advanceTimersByTime(30000));
            expect(router.reload).toHaveBeenCalledTimes(1);
            view.unmount();
            visible.mockReturnValue(false);
            act(() => vi.advanceTimersByTime(30000));
            expect(router.reload).toHaveBeenCalledTimes(1);
        } finally {
            visible.mockRestore();
            online.mockRestore();
            vi.useRealTimers();
        }
    });
    it('makes attendee activities discoverable without exposing their contents', () => {
        render(
            <Show
                event={event}
                org={org}
                participation={{
                    entry_url: '/my/join/event-1',
                    is_live: true,
                    has_live_poll: true,
                    has_forms: true,
                }}
            />
        );
        const access = within(screen.getByRole('region', { name: 'Join the live event' }));
        expect(access.getByText('Happening now')).toBeTruthy();
        expect(access.getByRole('link', { name: /Join the live event/ }).getAttribute('href')).toBe(
            '/my/join/event-1?tab=agenda'
        );
        expect(access.getByRole('link', { name: /Live poll/ }).getAttribute('href')).toBe(
            '/my/join/event-1?tab=poll'
        );
        expect(access.getByRole('link', { name: /Surveys & feedback/ }).getAttribute('href')).toBe(
            '/my/join/event-1?tab=forms'
        );
        expect(access.getByRole('link', { name: /Ask a question/ }).getAttribute('href')).toBe(
            '/my/join/event-1?tab=forum'
        );
        expect(screen.queryByRole('textbox', { name: 'Your question' })).toBeNull();
    });

    it('does not advertise an active poll or survey when nothing has been opened', () => {
        render(
            <Show
                event={event}
                org={org}
                participation={{
                    entry_url: '/my/join/event-1',
                    is_live: false,
                    has_live_poll: false,
                    has_forms: false,
                }}
            />
        );
        const access = within(screen.getByRole('region', { name: 'Join the live event' }));
        expect(access.queryByText('Happening now')).toBeNull();
        expect(access.queryByText('Active')).toBeNull();
        expect(access.queryByRole('link', { name: /Surveys/ })).toBeNull();
        expect(access.getByRole('link', { name: /Open attendee area/ })).toBeTruthy();
    });
    it('shows the real programme, faculty, organiser and anonymous attendance together', () => {
        render(<Show event={event} org={org} />);
        expect(screen.getByText('Opening session')).toBeTruthy();
        expect(screen.getByText('Dr Example')).toBeTruthy();
        expect(screen.getByText('12 going')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Instagram' }).href).toBe(org.links.instagram);
        expect(screen.getByText('Our sponsor')).toBeTruthy();
        expect(screen.getByText('Take the north entrance.')).toBeTruthy();
        expect(screen.getByRole('link', { name: /directions/i }).href).toContain(
            encodeURIComponent(event.address)
        );
    });
    it('keeps the ticket card compact and retains registration values between opening and closing the form', () => {
        render(<Show event={event} org={org} />);
        expect(screen.queryByLabelText(/First name/)).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: 'Continue', exact: true }));
        const dialog = screen.getByRole('dialog', { name: 'Complete your registration' });
        const first = within(dialog).getByLabelText(/First name/);
        fireEvent.change(first, { target: { value: 'Ada' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Close', exact: true }));
        fireEvent.click(screen.getByRole('button', { name: 'Q&A', exact: true }));
        fireEvent.click(screen.getByRole('button', { name: 'Continue', exact: true }));
        const reopened = screen.getByRole('dialog', { name: 'Complete your registration' });
        expect(within(reopened).getByLabelText(/First name/).value).toBe('Ada');
        fireEvent.submit(
            within(reopened)
                .getByLabelText(/First name/)
                .closest('form')
        );
        expect(submitted).toHaveBeenCalledWith(
            expect.stringContaining('public.events.register'),
            expect.objectContaining({ first_name: 'Ada', ticket_type_id: '', form_answers: {} })
        );
    });

    it('opens the full speaker profile from the portrait card and restores keyboard focus', () => {
        render(<Show event={event} org={org} />);
        expect(screen.queryByText('Expert speaker.')).toBeNull();
        const card = screen.getByRole('button', { name: 'View profile: Dr Example' });
        card.focus();
        fireEvent.click(card);
        expect(screen.getByRole('dialog', { name: 'Dr Example' })).toBeTruthy();
        expect(screen.getByText('Expert speaker.')).toBeTruthy();
        fireEvent.keyDown(document, { key: 'Escape' });
        expect(screen.queryByRole('dialog')).toBeNull();
        expect(document.activeElement).toBe(card);
    });

    it('places the organiser social row above the event heading', () => {
        render(<Show event={event} org={org} />);
        const row = screen.getByRole('region', { name: 'Event organiser' });
        expect(within(row).getByRole('link', { name: 'Instagram' })).toBeTruthy();
        expect(
            Boolean(
                row.compareDocumentPosition(screen.getByRole('heading', { level: 1 })) &
                Node.DOCUMENT_POSITION_FOLLOWING
            )
        ).toBe(true);
    });
    it('preserves paid-ticket totals, invite-code entry and waitlist actions', () => {
        render(
            <Show
                event={{
                    ...event,
                    fee_bearer: 'attendee',
                    platform_fee_percentage: 5,
                    platform_fee_cap_amount: 500,
                    ticket_types: [
                        { id: 'standard', name: 'Standard', price: 10000 },
                        { id: 'full', name: 'Full workshop', price: 15000, is_sold_out: true },
                    ],
                }}
                org={org}
            />
        );
        fireEvent.click(screen.getByRole('button', { name: 'Continue', exact: true }));
        const registration = within(
            screen.getByRole('dialog', { name: 'Complete your registration' })
        );
        expect(
            registration.getByRole('button', { name: 'Continue to payment (GHS 105.00)' })
        ).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: /access code for hidden tickets/i }));
        expect(screen.getByPlaceholderText('e.g. VIP2026')).toBeTruthy();
        fireEvent.click(registration.getByRole('button', { name: 'Close', exact: true }));
        fireEvent.click(screen.getByRole('radio', { name: /Full workshop/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Continue', exact: true }));
        const waitlist = screen.getByRole('button', { name: 'Join waitlist' });
        fireEvent.submit(waitlist.closest('form'));
        expect(submitted).toHaveBeenCalledWith(
            expect.any(String),
            expect.objectContaining({ ticket_type_id: 'full' })
        );
    });

    it('retains conditional answers and price changes when registration is reopened', () => {
        render(
            <Show
                event={{
                    ...event,
                    ticket_price: 1000,
                    form_fields: [
                        {
                            id: 'mode',
                            field_key: 'mode',
                            field_type: 'select',
                            label: 'Attendance mode',
                            options: [{ value: 'room', label: 'In person', price: 500 }],
                            is_required: true,
                        },
                        {
                            id: 'seat',
                            field_key: 'seat',
                            field_type: 'text',
                            label: 'Seating preference',
                            conditional_logic: { depends_on_field: 'mode', value: 'room' },
                        },
                    ],
                }}
                org={org}
            />
        );
        fireEvent.click(screen.getByRole('button', { name: 'Continue', exact: true }));
        expect(screen.queryByLabelText('Seating preference')).toBeNull();
        fireEvent.change(screen.getByLabelText(/Attendance mode/), { target: { value: 'room' } });
        fireEvent.change(screen.getByLabelText('Seating preference'), {
            target: { value: 'Front' },
        });
        expect(
            screen.getByRole('button', { name: 'Continue to payment (GHS 15.00)' })
        ).toBeTruthy();
        fireEvent.keyDown(document, { key: 'Escape' });
        fireEvent.click(screen.getByRole('button', { name: 'Continue', exact: true }));
        expect(screen.getByLabelText('Seating preference').value).toBe('Front');
    });

    it('keeps the event finish time visible even without a programme', () => {
        render(<Show event={{ ...event, sessions: [] }} org={org} />);
        const endTime = new Date(event.ends_at).toLocaleTimeString(undefined, {
            timeZone: event.timezone,
            hour: 'numeric',
            minute: '2-digit',
        });
        expect(
            within(document.getElementById('register-section')).getByText(
                (text) => text.includes('–') && text.includes(endTime)
            )
        ).toBeTruthy();
    });

    it('opens programme notes in a keyboard-accessible dialog and retains slide links', () => {
        render(
            <Show
                event={{
                    ...event,
                    sessions: [
                        {
                            ...event.sessions[0],
                            notes: 'Practical session notes.',
                            presentation_url: 'https://example.test/slides',
                        },
                    ],
                }}
                org={org}
            />
        );
        fireEvent.click(screen.getByRole('button', { name: 'Notes', exact: true }));
        expect(screen.getByRole('dialog', { name: 'Opening session' })).toBeTruthy();
        expect(screen.getByText('Practical session notes.')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Open Presentation Slides' }).href).toBe(
            'https://example.test/slides'
        );
        fireEvent.keyDown(document, { key: 'Escape' });
        expect(screen.queryByRole('dialog')).toBeNull();
    });

    it('shares a selectable public URL and does not expose attendance on private events', () => {
        render(
            <Show
                event={{ ...event, going_count: null, sessions: [], speakers: [] }}
                org={{ name: 'Private organiser' }}
                isPrivate
            />
        );
        expect(screen.queryByText(/12 going/)).toBeNull();
        expect(screen.queryByRole('link', { name: 'Instagram' })).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: 'Share event' }));
        expect(screen.getByRole('dialog', { name: 'Share this event' })).toBeTruthy();
        expect(screen.getByLabelText('Event link').readOnly).toBe(true);
        expect(screen.queryByRole('link', { name: 'Add to calendar' })).toBeNull();
    });
});
