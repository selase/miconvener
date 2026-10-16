import { afterEach, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import VerifyPrompt from '@/Pages/Public/Events/AttendeePortal/VerifyPrompt';
afterEach(() => vi.restoreAllMocks());
it('offers email first and an explicit secondary phone choice', () => {
    render(<VerifyPrompt onVerified={() => {}} organiserSlug="pilot" />);
    expect(screen.getByLabelText(/Your email address/i)).toBeTruthy();
    expect(screen.getByRole('button', { name: /use a phone number instead/i })).toBeTruthy();
    expect(screen.queryByLabelText('Phone number')).toBeNull();
});
it('preserves registration email proof and never substitutes phone ticket ownership', () => {
    render(
        <VerifyPrompt
            registrationId="registration"
            sentTo="aa@example.test"
            onVerified={() => {}}
        />
    );
    expect(screen.queryByRole('button', { name: /use a phone number instead/i })).toBeNull();
});
it('uses separate phone endpoints with a code cooldown and never calls the email verified callback', async () => {
    const fetch = vi
        .spyOn(globalThis, 'fetch')
        .mockResolvedValueOnce({
            ok: true,
            status: 200,
            json: async () => ({ message: 'Code sent' }),
        })
        .mockResolvedValueOnce({ ok: true, status: 200, json: async () => ({ verified: true }) });
    const verified = vi.fn();
    render(<VerifyPrompt onVerified={verified} organiserSlug="pilot" />);
    fireEvent.click(screen.getByRole('button', { name: /use a phone number instead/i }));
    fireEvent.change(screen.getByLabelText('Phone number'), { target: { value: '0241234567' } });
    fireEvent.click(screen.getByRole('button', { name: /(?:send|email).*code/i }));
    const code = await screen.findByLabelText(/verification code/i);
    expect(screen.getByRole('button', { name: /resend.*\d/i }).disabled).toBe(true);
    fireEvent.change(code, { target: { value: '123456' } });
    fireEvent.click(screen.getByRole('button', { name: /verify code/i }));
    await vi.waitFor(() => expect(verified).toHaveBeenCalledWith(null, 'phone', 'pilot'));
    expect(JSON.parse(fetch.mock.calls[0][1].body)).toMatchObject({
        phone: '0241234567',
        organiser: 'pilot',
    });
});

it('clears the email challenge widget when switching to phone and can request email again', async () => {
    const previous = window.turnstile;
    const widget = { render: vi.fn(() => 42), remove: vi.fn(), reset: vi.fn() };
    window.turnstile = widget;
    vi.spyOn(globalThis, 'fetch').mockResolvedValue({
        ok: false,
        status: 428,
        json: async () => ({ site_key: 'test-key', action: 'email-proof' }),
    });
    try {
        render(<VerifyPrompt onVerified={() => {}} organiserSlug="pilot" />);
        fireEvent.change(screen.getByLabelText(/Your email address/i), {
            target: { value: 'test@example.test' },
        });
        fireEvent.click(screen.getByRole('button', { name: /(?:send|email).*code/i }));
        await vi.waitFor(() => expect(widget.render).toHaveBeenCalledOnce());
        fireEvent.click(screen.getByRole('button', { name: /use a phone number instead/i }));
        expect(widget.remove).toHaveBeenCalledWith(42);
        fireEvent.click(screen.getByRole('button', { name: 'Use email instead' }));
        fireEvent.click(screen.getByRole('button', { name: /(?:send|email).*code/i }));
        await vi.waitFor(() => expect(widget.render).toHaveBeenCalledTimes(2));
    } finally {
        window.turnstile = previous;
    }
});
