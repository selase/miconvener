import { describe, it, expect, vi, beforeEach } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import BlastsPanel from '@/Pages/Tenant/Events/BlastsPanel';
import csrfFetch from '@/lib/csrfFetch';

vi.mock('@/lib/csrfFetch', () => ({ default: vi.fn() }));
vi.mock('@/Components/Console/Toast', () => ({ useToast: () => vi.fn() }));

const listing = (sms, blasts = []) => ({
    blasts,
    audience_options: [{ key: 'all', label: 'All confirmed' }],
    sms,
});

const respond = (json, ok = true) => Promise.resolve({ ok, json: () => Promise.resolve(json) });

beforeEach(() => {
    globalThis.route = vi.fn((name) => `/${name}`);
    csrfFetch.mockReset();
});

describe('texting an announcement', () => {
    it('cannot be chosen while the organizer has SMS switched off', async () => {
        csrfFetch.mockReturnValue(respond(listing({ enabled: false, remaining: 0 })));
        render(<BlastsPanel event={{ id: 'e1' }} />);

        const box = await screen.findByLabelText(/Also send by SMS/);
        expect(box.disabled).toBe(true);
        expect(screen.getByText(/Turn on SMS in Notifications/)).toBeTruthy();
    });

    it('shows the credits left and sends the choice with the message', async () => {
        csrfFetch.mockReturnValue(respond(listing({ enabled: true, remaining: 42 })));
        const { container } = render(<BlastsPanel event={{ id: 'e1' }} />);

        fireEvent.click(await screen.findByLabelText(/Also send by SMS/));
        expect(screen.getByText(/You have 42 left/)).toBeTruthy();

        const [subject] = container.querySelectorAll('input[type="text"]');
        fireEvent.change(subject, { target: { value: 'Room change' } });
        fireEvent.change(container.querySelector('textarea'), { target: { value: 'Hall B' } });
        fireEvent.submit(container.querySelector('form'));

        await waitFor(() =>
            expect(csrfFetch).toHaveBeenCalledWith('/tenant.events.blasts.store', expect.anything())
        );
        const body = JSON.parse(
            csrfFetch.mock.calls.find(([url]) => url === '/tenant.events.blasts.store')[1].body
        );
        expect(body.send_sms).toBe(true);
    });

    it('reports how many texts a sent announcement reached', async () => {
        csrfFetch.mockReturnValue(
            respond(
                listing({ enabled: true, remaining: 5 }, [
                    {
                        id: 'b1',
                        subject: 'Hi',
                        audience_label: 'All confirmed',
                        recipients_count: 3,
                        opened_count: 1,
                        send_sms: true,
                        sms_sent_count: 2,
                        status: 'sent',
                        sent_at: '2026-10-04T10:00:00Z',
                        created_at: '2026-10-04T10:00:00Z',
                    },
                ])
            )
        );
        render(<BlastsPanel event={{ id: 'e1' }} />);

        expect(await screen.findByText(/2 by SMS/)).toBeTruthy();
    });
});
