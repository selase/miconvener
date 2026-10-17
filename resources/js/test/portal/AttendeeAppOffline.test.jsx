import { render, screen } from '@testing-library/react';
import { it, expect, vi } from 'vitest';
import AttendeeApp from '@/Pages/Public/Events/AttendeePortal/AttendeeApp';
import { reconcileAttendeeIdentity } from '@/lib/attendeeIdentityCache';
import { saveOfflineTicket } from '@/lib/offlineTicketStore';
vi.mock('@/Layouts/PublicLayout', () => ({ default: ({ children }) => children }));
it('keeps an anonymous fallback usable when the browser says online but the server is unreachable', async () => {
    localStorage.clear();
    const proof = {
        state: 'verified',
        owner_key: 'd'.repeat(64),
        proof_expires_at: Date.now() + 3600000,
    };
    reconcileAttendeeIdentity(proof);
    saveOfflineTicket(
        {
            id: 'saved',
            full_name: 'QA delegate',
            status: 'confirmed',
            ticket_code: 'PASS',
            qr_image: 'data:image/svg+xml;base64,PHN2Zy8+',
        },
        {
            name: 'Saved Conference',
            starts_at: new Date().toISOString(),
            ends_at: new Date(Date.now() + 86400000).toISOString(),
        },
        proof
    );
    const fetcher = vi
        .spyOn(globalThis, 'fetch')
        .mockRejectedValue(new Error('Server unreachable'));
    try {
        render(<AttendeeApp offline_identity={{ state: 'unknown_offline' }} />);
        expect(await screen.findByAltText('Ticket QR code')).toBeTruthy();
        expect(screen.getByText('Saved Conference')).toBeTruthy();
    } finally {
        fetcher.mockRestore();
        localStorage.clear();
    }
});
