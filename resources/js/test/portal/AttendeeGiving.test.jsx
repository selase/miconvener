import { expect, it, vi } from 'vitest';
import { act, fireEvent, render, screen } from '@testing-library/react';
import GivingSection from '@/Pages/Public/Events/AttendeePortal/components/GivingSection';
import AttendeeMoney from '@/Pages/Public/Events/AttendeePortal/components/AttendeeMoney';
vi.mock('@/Layouts/PublicLayout', () => ({ default: ({ children }) => children }));
const item = {
    key: 'gift:g',
    id: 'g',
    source_type: 'gift',
    amount: 10000,
    currency: 'GHS',
    fund: 'Volunteer Project',
    organisation: 'Community',
    source: 'recorded_cash',
    access_role: 'self',
    receipt_url: '/my/giving/gift/g/receipt',
    date: '2026-10-10T12:00:00Z',
};
it('Giving is the default for someone without dues and uses inclusive copy', () => {
    render(
        <AttendeeMoney
            status="success"
            data={{ dues: { data: [] }, giving: { data: [item] }, contributions: { data: [] } }}
        />
    );
    expect(screen.getByRole('heading', { name: 'Your contributions and payments' })).toBeTruthy();
    expect(screen.getByText('Volunteer Project')).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Giving' }).getAttribute('aria-pressed')).toBe(
        'true'
    );
});
it('representative access labels the member and does not fabricate a receipt link', () => {
    render(
        <GivingSection
            giving={{
                data: [
                    {
                        ...item,
                        access_role: 'representative',
                        member_name: 'Alex',
                        receipt_url: null,
                    },
                ],
            }}
        />
    );
    expect(screen.getByText(/For Alex.*representative/)).toBeTruthy();
    expect(screen.queryByRole('link', { name: 'Download receipt' })).toBeNull();
});
it('an explicit legacy contributions tab is still respected', () => {
    render(
        <AttendeeMoney
            initialTab="contributions"
            status="success"
            data={{ dues: { data: [] }, giving: { data: [item] }, contributions: { data: [] } }}
        />
    );
    expect(
        screen
            .getByRole('button', { name: 'Contributions & receipts' })
            .getAttribute('aria-pressed')
    ).toBe('true');
    expect(screen.queryByText('Volunteer Project')).toBeNull();
});
it('Giving pagination delegates to the protected parent fetch', () => {
    const onPage = vi.fn();
    render(
        <GivingSection giving={{ data: [item], current_page: 1, last_page: 2 }} onPage={onPage} />
    );
    fireEvent.click(screen.getByRole('button', { name: 'Next contributions' }));
    expect(onPage).toHaveBeenCalledWith('giving', 2);
});

it('private fallback hides records offline and cannot restore a response after identity invalidation', async () => {
    const { default: History } = await import('@/Pages/Public/Giving/History');
    const fetchMock = vi.spyOn(await import('@/lib/csrfFetch'), 'default');
    let reply;
    fetchMock.mockImplementation(
        () =>
            new Promise((resolve) => {
                reply = resolve;
            })
    );
    const write = vi.spyOn(Storage.prototype, 'setItem');
    try {
        const identity = {
            state: 'verified',
            owner_key: 'a'.repeat(64),
            access_key: 'scope',
            proof_expires_at: Date.now() + 3600000,
        };
        render(<History giving={{ data: [item] }} identity={identity} />);
        expect(screen.getByText('Volunteer Project')).toBeTruthy();
        fireEvent(window, new Event('offline'));
        expect(screen.queryByText('Volunteer Project')).toBeNull();
        fireEvent(window, new Event('focus'));
        fireEvent(window, new Event('miconvener:attendee-identity'));
        await act(async () => {
            reply({ ok: true, json: async () => identity });
        });
        expect(screen.queryByText('Volunteer Project')).toBeNull();
        expect(write).not.toHaveBeenCalled();
    } finally {
        fetchMock.mockRestore();
        write.mockRestore();
    }
});
