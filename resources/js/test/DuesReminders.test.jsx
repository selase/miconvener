import { afterEach, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import MoneyDues from '@/Pages/Tenant/Community/MoneyDues';
import Overview from '@/Pages/Tenant/Community/Overview';

vi.mock('@/Layouts/ConsoleLayout', () => ({ default: ({ children }) => children }));
afterEach(() => vi.restoreAllMocks());
const toast = vi.hoisted(() => vi.fn());
vi.mock('@/Components/Console/Toast', () => ({ useToast: () => toast }));

it('previews recipients before confirmation and reports queued delivery', async () => {
    const fetch = vi
        .spyOn(globalThis, 'fetch')
        .mockResolvedValueOnce({
            ok: true,
            json: async () => ({
                members: 3,
                sms_credits_needed: 2,
                sms_credits_remaining: 1,
                sms_monthly_remaining: 5,
                reminders_enabled: true,
            }),
        })
        .mockResolvedValueOnce({
            ok: true,
            json: async () => ({ status: 'queued', message: 'Arrears reminders queued.' }),
        });
    render(<MoneyDues membershipTypes={[]} canRemindArrears />);
    fireEvent.click(screen.getByRole('button', { name: 'Remind everyone in arrears' }));
    await screen.findByText(/3 members currently have overdue charges/);
    expect(fetch).toHaveBeenCalledTimes(1);
    expect(screen.getByText(/Up to 2 SMS credits/)).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Queue reminders' }));
    await waitFor(() => expect(toast).toHaveBeenCalledWith('Arrears reminders queued.'));
    expect(fetch.mock.calls[0][1].method).toBe('GET');
    expect(fetch.mock.calls[1][1].method).toBe('POST');
    expect(screen.queryByRole('dialog')).toBeNull();
    fetch.mockRestore();
});

it.each([
    [0, 'members currently have'],
    [1, 'member currently has'],
    [2, 'members currently have'],
])('uses correct arrears grammar for %i members', async (members, phrase) => {
    const fetch = vi
        .spyOn(globalThis, 'fetch')
        .mockResolvedValue({
            ok: true,
            json: async () => ({ members, sms_credits_needed: 0, reminders_enabled: true }),
        });
    render(<MoneyDues membershipTypes={[]} canRemindArrears />);
    fireEvent.click(screen.getByRole('button', { name: 'Remind everyone in arrears' }));
    expect(await screen.findByText(new RegExp(`${members} ${phrase}`))).toBeTruthy();
    fetch.mockRestore();
});

it('hides arrears action without finance permission', () => {
    render(<MoneyDues membershipTypes={[]} canRemindArrears={false} />);
    expect(screen.queryByRole('button', { name: 'Remind everyone in arrears' })).toBeNull();
});

it('disabled reminders and empty arrears cannot be queued', async () => {
    const fetch = vi.spyOn(globalThis, 'fetch').mockResolvedValue({
        ok: true,
        json: async () => ({ members: 0, sms_credits_needed: 0, reminders_enabled: false }),
    });
    render(<MoneyDues membershipTypes={[]} canRemindArrears />);
    fireEvent.click(screen.getByRole('button', { name: 'Remind everyone in arrears' }));
    await screen.findByRole('dialog');
    expect(screen.getByRole('button', { name: 'Queue reminders' }).disabled).toBe(true);
    expect(screen.getByRole('alert').textContent).toContain('Community → Settings');
    fetch.mockRestore();
});

it('settings tab edits and saves validated reminder preferences', async () => {
    const fetch = vi
        .spyOn(globalThis, 'fetch')
        .mockResolvedValueOnce({
            ok: false,
            status: 422,
            json: async () => ({ errors: { sms_monthly_limit: ['Use a whole number.'] } }),
        })
        .mockResolvedValueOnce({
            ok: true,
            json: async () => ({ message: 'Reminder settings saved.' }),
        });
    render(
        <Overview
            counts={{ members: 0, activeMembers: 0, openCharges: 0, overdueCharges: 0 }}
            collectedThisMonth={[]}
            reminderSettings={{
                reminders_enabled: true,
                reminder_offsets: { before: 7, on_day: 0, after_7: 7, after_30: 30 },
                frequent_digest: 'daily',
                sms_monthly_limit: null,
                week_starts_on: 1,
            }}
        />
    );
    fireEvent.click(screen.getByRole('tab', { name: 'Settings' }));
    fireEvent.change(screen.getByRole('spinbutton', { name: 'Monthly Community SMS limit' }), {
        target: { value: '10' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Save reminder settings' }));
    await screen.findByText('Use a whole number.');
    fireEvent.click(screen.getByRole('button', { name: 'Save reminder settings' }));
    await waitFor(() => expect(toast).toHaveBeenCalledWith('Reminder settings saved.'));
    expect(JSON.parse(fetch.mock.calls[1][1].body).sms_monthly_limit).toBe('10');
    fetch.mockRestore();
});

it('keeps saved reminder choices after returning to Settings and an unrelated save', async () => {
    const fetch = vi.spyOn(globalThis, 'fetch').mockResolvedValue({
        ok: true,
        json: async () => ({ message: 'Reminder settings saved.' }),
    });
    render(
        <Overview
            counts={{ members: 0, activeMembers: 0, openCharges: 0, overdueCharges: 0 }}
            collectedThisMonth={[]}
            reminderSettings={{
                reminders_enabled: true,
                reminder_offsets: { before: 7, on_day: 0, after_7: 7, after_30: 30 },
                frequent_digest: 'daily',
                sms_monthly_limit: null,
                week_starts_on: 1,
            }}
        />
    );
    fireEvent.click(screen.getByRole('tab', { name: 'Settings' }));
    fireEvent.change(screen.getByRole('combobox', { name: 'Dues reminders' }), {
        target: { value: 'off' },
    });
    fireEvent.change(screen.getByRole('spinbutton', { name: 'Days before due' }), {
        target: { value: '9' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Save reminder settings' }));
    await waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));
    await waitFor(() =>
        expect(screen.getByRole('button', { name: 'Save reminder settings' }).disabled).toBe(false)
    );
    fireEvent.click(screen.getByRole('tab', { name: 'Overview' }));
    fireEvent.click(screen.getByRole('tab', { name: 'Settings' }));
    expect(screen.getByRole('combobox', { name: 'Dues reminders' }).value).toBe('off');
    expect(screen.getByRole('spinbutton', { name: 'Days before due' }).value).toBe('9');
    fireEvent.change(screen.getByRole('spinbutton', { name: 'Monthly Community SMS limit' }), {
        target: { value: '20' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Save reminder settings' }));
    await waitFor(() => expect(fetch).toHaveBeenCalledTimes(2));
    expect(JSON.parse(fetch.mock.calls[1][1].body)).toMatchObject({
        reminders_enabled: false,
        reminder_offsets: { before: '9' },
        sms_monthly_limit: '20',
    });
    fetch.mockRestore();
});

it('preserves unsaved reminder inputs and validation errors across tab changes', async () => {
    const fetch = vi.spyOn(globalThis, 'fetch').mockResolvedValue({
        ok: false,
        status: 422,
        json: async () => ({ errors: { sms_monthly_limit: ['Use a whole number.'] } }),
    });
    render(
        <Overview
            counts={{ members: 0, activeMembers: 0, openCharges: 0, overdueCharges: 0 }}
            collectedThisMonth={[]}
            reminderSettings={{
                reminders_enabled: true,
                reminder_offsets: { before: 7, on_day: 0, after_7: 7, after_30: 30 },
                frequent_digest: 'daily',
                sms_monthly_limit: null,
                week_starts_on: 1,
            }}
        />
    );
    fireEvent.click(screen.getByRole('tab', { name: 'Settings' }));
    fireEvent.change(screen.getByRole('spinbutton', { name: 'Monthly Community SMS limit' }), {
        target: { value: '10' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Save reminder settings' }));
    await screen.findByText('Use a whole number.');
    fireEvent.click(screen.getByRole('tab', { name: 'Overview' }));
    fireEvent.click(screen.getByRole('tab', { name: 'Settings' }));
    expect(screen.getByRole('spinbutton', { name: 'Monthly Community SMS limit' }).value).toBe(
        '10'
    );
    expect(screen.getByText('Use a whole number.')).toBeTruthy();
    fetch.mockRestore();
});
