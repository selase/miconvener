import { describe, it, expect, vi } from 'vitest';
import { render, screen, within, fireEvent } from '@testing-library/react';
import MyTicketPanel from '@/Pages/Public/Events/AttendeePortal/panels/MyTicketPanel';
import csrfFetch from '@/lib/csrfFetch';

vi.mock('@/lib/csrfFetch', () => ({ default: vi.fn() }));

const event = { name: 'Community service series', slug: 'community-service' };
const registration = {
    id: 'reg-layout',
    full_name: 'Selase Community pilot',
    status: 'confirmed',
    ticket_code: 'SAMPLE-ONLY-167',
    qr_image: 'data:image/png;base64,c2FtcGxl',
    seat_label: 'A12',
    room_name: 'Main auditorium',
};

describe('ticket presentation', () => {
    it('keeps the personal-ticket notice separate from the QR and ticket details', () => {
        render(
            <MyTicketPanel
                event={event}
                registration={{
                    ...registration,
                    member_benefit_required: true,
                    checkout_url: '/membership-check',
                    member_payment_review_url: '/payment-review',
                    member_extra_payments: 1,
                }}
            />
        );

        const ticket = screen.getByRole('region', { name: 'Your ticket' });
        const notice = screen.getByRole('note');
        expect(ticket.contains(notice)).toBe(false);
        expect(within(notice).getByText(/Tickets cannot be transferred/)).toBeTruthy();
        expect(within(notice).getByRole('link', { name: /Verify membership/ }).href).toContain(
            '/membership-check'
        );
        expect(within(notice).getByRole('link', { name: /View payment receipt/ }).href).toContain(
            '/payment-review'
        );
        expect(within(notice).getByRole('status').textContent).toContain('Do not pay again.');
        expect(
            within(ticket).getByRole('img', { name: 'Your ticket QR code' }).getAttribute('src')
        ).toBe(registration.qr_image);
        expect(within(ticket).getByText('SAMPLE-ONLY-167')).toBeTruthy();
        expect(within(ticket).getByText('A12 · Main auditorium')).toBeTruthy();
        expect(
            within(ticket).getByRole('link', { name: 'Download PDF' }).getAttribute('href')
        ).toBe('/my/events/reg-layout/ticket');
        expect(screen.queryByRole('button', { name: 'Transfer' })).toBeNull();
    });

    it('keeps ticket details and downloads available without a QR image or member notice', () => {
        render(<MyTicketPanel event={event} registration={{ ...registration, qr_image: null }} />);

        const ticket = screen.getByRole('region', { name: 'Your ticket' });
        expect(within(ticket).getByRole('heading', { name: registration.full_name })).toBeTruthy();
        expect(within(ticket).getByText('General admission')).toBeTruthy();
        expect(within(ticket).getByRole('link', { name: 'Download PDF' })).toBeTruthy();
        expect(screen.queryByRole('img')).toBeNull();
        expect(screen.queryByRole('note')).toBeNull();
    });

    it('retains the emailed-code transfer step for eligible tickets', async () => {
        window.history.pushState({}, '', '/my/events/reg-layout');
        csrfFetch.mockResolvedValue({ ok: true, json: async () => ({ message: 'Code sent.' }) });
        render(<MyTicketPanel event={event} registration={registration} />);

        fireEvent.click(screen.getByRole('button', { name: 'Transfer' }));
        fireEvent.change(screen.getByPlaceholderText('Their full name'), {
            target: { value: 'Ama Serwa' },
        });
        fireEvent.change(screen.getByPlaceholderText('Their email'), {
            target: { value: 'ama@example.test' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Send me a code' }));

        await screen.findByPlaceholderText('6-digit code');
        expect(csrfFetch).toHaveBeenCalledWith(
            '/my/events/reg-layout/transfer',
            expect.objectContaining({ method: 'POST' })
        );
        expect(screen.getByRole('button', { name: 'Complete transfer' })).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(screen.queryByPlaceholderText('6-digit code')).toBeNull();
    });

    it('preserves offline consent, verification gating and certificate actions', () => {
        const { rerender } = render(<MyTicketPanel event={event} registration={registration} />);
        expect(screen.getByRole('button', { name: 'Save ticket offline' }).disabled).toBe(true);

        rerender(
            <MyTicketPanel
                event={event}
                registration={registration}
                offlineIdentity={{ state: 'verified' }}
                certificate={{
                    role: 'Participant',
                    cpd_hours: 2,
                    download_url: '/certificate.pdf',
                    verification_url: '/verify-certificate',
                }}
            />
        );
        fireEvent.click(screen.getByRole('button', { name: 'Save ticket offline' }));
        expect(screen.getByText('Save ticket to this device?')).toBeTruthy();
        expect(screen.getByText(/Do not save on shared or public computers/)).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(screen.queryByText('Save ticket to this device?')).toBeNull();
        expect(screen.getByText('2 CPD hours')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Verify' }).getAttribute('href')).toBe(
            '/verify-certificate'
        );
        expect(
            screen
                .getAllByRole('link', { name: 'Download PDF' })
                .map((link) => link.getAttribute('href'))
        ).toEqual(['/my/events/reg-layout/ticket', '/certificate.pdf']);

        rerender(
            <MyTicketPanel
                event={event}
                registration={registration}
                isOnline={false}
                offlineIdentity={{ state: 'verified' }}
            />
        );
        expect(screen.getByRole('button', { name: 'Save ticket offline' }).disabled).toBe(true);
        expect(screen.queryByRole('button', { name: 'Transfer' })).toBeNull();
        expect(screen.getByText('Transfer (offline)')).toBeTruthy();
    });
});
