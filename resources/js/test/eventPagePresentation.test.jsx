import React, { useState } from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import Show from '@/Pages/Public/Events/Show';

const { submitted } = vi.hoisted(() => ({ submitted: vi.fn() }));
vi.mock('@inertiajs/react', () => ({
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
    it('keeps registration values while opening an interactive section and submits the existing contract', () => {
        render(<Show event={event} org={org} />);
        const first = screen.getByLabelText(/First name/);
        fireEvent.change(first, { target: { value: 'Ada' } });
        fireEvent.click(screen.getByRole('button', { name: 'Q&A' }));
        expect(screen.getByLabelText(/First name/).value).toBe('Ada');
        fireEvent.submit(first.closest('form'));
        expect(submitted).toHaveBeenCalledWith(
            expect.stringContaining('public.events.register'),
            expect.objectContaining({ first_name: 'Ada', ticket_type_id: '', form_answers: {} })
        );
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
        expect(
            screen.getByRole('button', { name: 'Continue to payment (GHS 105.00)' })
        ).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: /access code for hidden tickets/i }));
        expect(screen.getByPlaceholderText('e.g. VIP2026')).toBeTruthy();
        fireEvent.click(screen.getByRole('radio', { name: /Full workshop/ }));
        const waitlist = screen.getByRole('button', { name: 'Join waitlist' });
        fireEvent.submit(waitlist.closest('form'));
        expect(submitted).toHaveBeenCalledWith(
            expect.any(String),
            expect.objectContaining({ ticket_type_id: 'full' })
        );
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
