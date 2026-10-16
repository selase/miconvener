import Followers from '@/Pages/Tenant/Community/Followers';
import { afterEach, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import FollowControl from '@/Pages/Public/Community/FollowControl';
vi.mock('@/Layouts/ConsoleLayout', () => ({ default: ({ children }) => children }));
afterEach(() => vi.restoreAllMocks());
it('requires explicit address consent and stops marketing when unfollowing', async () => {
    const fetch = vi
        .spyOn(globalThis, 'fetch')
        .mockResolvedValueOnce({ ok: true, status: 201, json: async () => ({ following: true }) })
        .mockResolvedValueOnce({ ok: true, status: 200, json: async () => ({ following: false }) });
    render(
        <FollowControl
            identity={{ email: { masked: 'a•@example.test' }, phone: null }}
            initialFollowing={{ email: false, sms: false }}
            followUrl="/community/follow"
        />
    );
    expect(screen.getByRole('button', { name: 'Follow organisation' }).disabled).toBe(true);
    fireEvent.click(screen.getByLabelText(/Send me announcements/));
    fireEvent.click(screen.getByRole('button', { name: 'Follow organisation' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Unfollow' }));
    await screen.findByRole('button', { name: 'Follow organisation' });
    expect(JSON.parse(fetch.mock.calls[0][1].body)).toMatchObject({
        channel: 'email',
        resume_marketing: true,
    });
    expect(fetch.mock.calls[1][1].method).toBe('DELETE');
});

it('requires a reviewed event audience and invalidates the send review when copy changes', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue({
        ok: true,
        status: 200,
        json: async () => ({
            counts: { email: 1 },
            email_credits_remaining: 10,
            sms_credits_remaining: 10,
            sms_monthly_remaining: 10,
            event: { name: 'Public Event', url: 'https://example.test/e/event' },
            body: 'Reviewed copy with event link',
        }),
    });
    render(
        <Followers
            followers={{ data: [] }}
            messages={{ data: [] }}
            canManage
            canSend
            events={[{ id: 'event', name: 'Public Event' }]}
        />
    );
    fireEvent.click(screen.getByRole('button', { name: 'Announce event' }));
    fireEvent.change(screen.getByLabelText('Published event'), { target: { value: 'event' } });
    fireEvent.change(screen.getByLabelText('Subject'), { target: { value: 'Come along' } });
    fireEvent.change(screen.getByLabelText('Announcement'), {
        target: { value: 'Join our next event.' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Review audience and cost' }));
    await screen.findByText('Email: 1');
    fireEvent.click(screen.getByLabelText(/I am authorised/));
    expect(screen.getByRole('button', { name: 'Send announcement' }).disabled).toBe(false);
    fireEvent.change(screen.getByLabelText('Subject'), { target: { value: 'Changed subject' } });
    expect(screen.getByRole('button', { name: 'Send announcement' }).disabled).toBe(true);
});
