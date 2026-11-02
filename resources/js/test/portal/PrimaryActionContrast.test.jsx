import { expect, it } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import GetHelpPanel from '@/Pages/Public/Events/AttendeePortal/panels/GetHelpPanel';
import FollowControl from '@/Pages/Public/Community/FollowControl';
import LiveNowSection from '@/Pages/Public/Events/AttendeePortal/components/LiveNowSection';
import NeedsAttentionSection from '@/Pages/Public/Events/AttendeePortal/components/NeedsAttentionSection';

it.each([
    ['verification', <FollowControl identity={{}} />, 'link', 'Verify email to follow'],
    [
        'following',
        <FollowControl identity={{ email: { masked: 'a••@example.test' } }} />,
        'button',
        'Follow organisation',
    ],
    [
        'live workspace',
        <LiveNowSection
            items={[
                {
                    registration_id: 'r',
                    event: { name: 'Meeting' },
                    event_workspace_url: '/my/events/r',
                },
            ]}
        />,
        'link',
        'Open workspace',
    ],
    [
        'pending action',
        <NeedsAttentionSection
            items={[{ registration_id: 'r', event: { name: 'Meeting' }, reason: 'Confirm email' }]}
        />,
        'link',
        'View details',
    ],
])('uses the theme-aware solid-action foreground for %s', (_name, component, role, name) => {
    render(component);
    const action = screen.getByRole(role, { name });
    expect(action.classList.contains('bg-accent')).toBe(true);
    expect(action.classList.contains('text-accent-ink')).toBe(true);
    expect(action.classList.contains('text-white')).toBe(false);
});

it.each(['normal', 'medical'])('preserves the correct foreground for %s help', (kind) => {
    render(<GetHelpPanel event={{ slug: 'meeting' }} registration={{ id: 'r' }} />);
    if (kind === 'medical')
        fireEvent.click(screen.getByRole('button', { name: 'First Aid / Medical' }));
    const action = screen.getByRole('button', {
        name: kind === 'medical' ? 'Request Immediate First Aid' : 'Send Help Request',
    });
    expect(action.classList.contains(kind === 'medical' ? 'bg-red-600' : 'bg-accent')).toBe(true);
    expect(action.classList.contains(kind === 'medical' ? 'text-white' : 'text-accent-ink')).toBe(
        true
    );
});
