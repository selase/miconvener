import { afterEach, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import Join from '@/Pages/Public/Community/Join';
afterEach(() => vi.restoreAllMocks());
it('explains an unavailable membership type without offering submission', () => {
    render(
        <Join
            joinOptions={{ enabled: false, reason: 'The organisation has not opened joining.' }}
            identity={{ email: { masked: 'a•@example.test' } }}
        />
    );
    expect(screen.getByText(/has not opened joining/)).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Request membership' })).toBeNull();
});
it('submits a reviewed pending request without marketing consent or caller-chosen dues', async () => {
    const fetch = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce({
        ok: true,
        status: 201,
        json: async () => ({ request: { id: 'join', status: 'pending', name: 'Ama' } }),
    });
    render(
        <Join
            joinUrl="/community/join"
            joinOptions={{
                enabled: true,
                mode: 'approval_required',
                type: { name: 'Regular', amount: 5000, currency: 'GHS' },
            }}
            identity={{ email: { masked: 'a•@example.test' } }}
        />
    );
    fireEvent.change(screen.getByLabelText('Your name'), { target: { value: 'Ama' } });
    fireEvent.click(screen.getByRole('button', { name: 'Request membership' }));
    await screen.findByText(/awaiting approval/i);
    const payload = JSON.parse(fetch.mock.calls[0][1].body);
    expect(payload).toMatchObject({ name: 'Ama', channel: 'email' });
    expect(payload).not.toHaveProperty('amount');
    expect(payload).not.toHaveProperty('resume_marketing');
});
