import { describe, it, expect, vi, beforeEach } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import NotificationsPanel from '@/Pages/Tenant/Events/panels/NotificationsPanel';
import csrfFetch from '@/lib/csrfFetch';

vi.mock('@/lib/csrfFetch', () => ({ default: vi.fn() }));

const respond = (json, ok = true) => Promise.resolve({ ok, json: () => Promise.resolve(json) });

const listing = {
    rules: [],
    audiences: [],
    settings: {
        sms_enabled: true,
        whatsapp_enabled: false,
        email_monthly_limit: 2500,
        email_used_this_month: 0,
    },
    recent_logs: [],
    presets: [],
};

beforeEach(() => {
    csrfFetch.mockReset();
    csrfFetch.mockImplementation((url, options) =>
        url.includes('test-send')
            ? respond({
                  message: 'Test notification dispatched.',
                  results: {
                      sms: { status: 'sent', message: 'SMS accepted by the provider for sending.' },
                  },
              })
            : respond(listing)
    );
});

async function openTestModal() {
    render(<NotificationsPanel event={{ id: 'e1' }} />);
    fireEvent.click(await screen.findByText(/Send Test/));
}

describe('sending a test notification', () => {
    it('asks for a phone number only when SMS is ticked', async () => {
        await openTestModal();

        expect(screen.queryByLabelText(/Send the test SMS to/)).toBeNull();
        fireEvent.click(screen.getByLabelText('SMS'));
        expect(screen.getByLabelText(/Send the test SMS to/)).toBeTruthy();
    });

    it('sends the typed number and reports what happened on each channel', async () => {
        await openTestModal();
        fireEvent.click(screen.getByLabelText('Email'));
        fireEvent.click(screen.getByLabelText('SMS'));
        fireEvent.change(screen.getByLabelText(/Send the test SMS to/), {
            target: { value: '+233208333151' },
        });
        fireEvent.click(screen.getByText('Send Live Test'));

        await waitFor(() =>
            expect(csrfFetch).toHaveBeenCalledWith(
                expect.stringContaining('test-send'),
                expect.anything()
            )
        );
        const body = JSON.parse(
            csrfFetch.mock.calls.find(([url]) => url.includes('test-send'))[1].body
        );
        expect(body.channels).toEqual(['sms']);
        expect(body.phone).toBe('+233208333151');
        expect(await screen.findByText(/SMS: sent/)).toBeTruthy();
    });
});
