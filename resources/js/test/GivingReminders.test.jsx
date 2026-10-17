import { expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import GivingReminderControl from '@/Pages/Public/Giving/ReminderControl';
import { sendJson } from '@/lib/dues';
vi.mock('@/lib/dues', () => ({ sendJson: vi.fn() }));
vi.mock('@/Pages/Public/Events/AttendeePortal/VerifyPrompt', () => ({
    default: ({ onVerified, allowPhone }) => (
        <button onClick={() => onVerified('g•••@example.test')}>Verify {String(allowPhone)}</button>
    ),
}));
it('optional reminders require email verification and separate unchecked consent', async () => {
    sendJson.mockResolvedValue({ ok: true, body: { message: 'Your reminders are on.' } });
    render(<GivingReminderControl slug="volunteers" />);
    expect(screen.queryByRole('checkbox')).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Verify false' }));
    const consent = screen.getByRole('checkbox');
    expect(consent.checked).toBe(false);
    expect(screen.getByRole('button', { name: 'Save email reminders' }).disabled).toBe(true);
    fireEvent.click(consent);
    fireEvent.click(screen.getByRole('button', { name: 'Save email reminders' }));
    await waitFor(() =>
        expect(sendJson).toHaveBeenCalledWith('POST', expect.any(String), {
            consent: true,
            cadence: 'monthly',
            anchor_day: 1,
        })
    );
    expect(await screen.findByText('Your reminders are on.')).toBeTruthy();
    expect(screen.getByText(/Nothing is charged automatically/)).toBeTruthy();
});
it('expired proof clears consent and asks for email verification again', async () => {
    sendJson.mockResolvedValue({ ok: false, status: 401, message: 'Verify again' });
    render(<GivingReminderControl slug="volunteers" />);
    fireEvent.click(screen.getByRole('button', { name: 'Verify false' }));
    fireEvent.click(screen.getByRole('checkbox'));
    fireEvent.click(screen.getByRole('button', { name: 'Save email reminders' }));
    expect(await screen.findByRole('button', { name: 'Verify false' })).toBeTruthy();
    expect(screen.queryByRole('checkbox')).toBeNull();
});
