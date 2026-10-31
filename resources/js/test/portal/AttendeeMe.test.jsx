import { render, screen, fireEvent } from '@testing-library/react';
import { it, expect, vi } from 'vitest';
import AttendeeMe from '@/Pages/Public/Events/AttendeePortal/components/AttendeeMe';
it('shows only verified contacts and makes resuming announcements explicit', () => {
    const update = vi.fn();
    const remove = vi.fn();
    render(
        <AttendeeMe
            identity={{ email: { masked: 'a•••@example.test' }, phone: null }}
            preferences={{
                data: [
                    {
                        tenant_slug: 'org',
                        name: 'Organisation',
                        channels: { email: { marketing_enabled: false } },
                    },
                ],
            }}
            onPreference={update}
            onRemove={remove}
            onSignOut={vi.fn()}
            onVerify={vi.fn()}
        />
    );
    expect(screen.getByText('a•••@example.test')).toBeTruthy();
    expect(screen.queryByText('SMS announcements')).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Remove email from this device' }));
    expect(remove).toHaveBeenCalledWith('email');
    fireEvent.click(screen.getByRole('button', { name: 'Receive email announcements' }));
    expect(update).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('checkbox', { name: /I agree/ }));
    fireEvent.click(screen.getByRole('button', { name: 'Receive email announcements' }));
    expect(update).toHaveBeenCalledWith('org', 'email', true);
});
