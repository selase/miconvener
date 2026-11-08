import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import ShareQrCard from '@/Pages/Tenant/Events/panels/ShareQrCard';

const event = { name: 'Sorkor at 70', slug: 'sorkor-at-70' };
const qr = 'data:image/svg+xml;base64,PHN2Zy8+';

describe('ShareQrCard', () => {
    it('describes viewing the event when registration is hidden', () => {
        render(<ShareQrCard event={{ ...event, show_registration: false }} qr={qr} />);
        expect(screen.getByText('Scan to view event')).toBeTruthy();
        expect(screen.queryByText('Scan to register')).toBeNull();
        expect(screen.getByRole('img').getAttribute('alt')).toContain('event page');
    });
    it('shows the code with a description of where it leads', () => {
        render(<ShareQrCard event={event} qr={qr} />);

        expect(screen.getByRole('img').getAttribute('src')).toBe(qr);
        expect(screen.getByRole('img').getAttribute('alt')).toContain('Sorkor at 70');
        expect(screen.getByText('Scan to register')).toBeTruthy();
    });

    it('renders nothing when the page sends no code', () => {
        const { container } = render(<ShareQrCard event={event} qr={null} />);

        expect(container.innerHTML).toBe('');
    });

    it('downloads the SVG under a name taken from the event', async () => {
        const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
        globalThis.URL.createObjectURL = vi.fn(() => 'blob:qr');
        globalThis.URL.revokeObjectURL = vi.fn();
        globalThis.fetch = vi.fn(async () => ({ blob: async () => new Blob(['<svg/>']) }));

        render(<ShareQrCard event={event} qr={qr} />);
        fireEvent.click(screen.getByRole('button', { name: /download svg/i }));

        await vi.waitFor(() => expect(click).toHaveBeenCalledTimes(1));
        expect(globalThis.fetch).toHaveBeenCalledWith(qr);
    });
});
