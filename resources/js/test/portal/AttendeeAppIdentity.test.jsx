import { render, screen, act } from '@testing-library/react';
import { it, expect, vi } from 'vitest';
import AttendeeApp from '@/Pages/Public/Events/AttendeePortal/AttendeeApp';
const callbacks = vi.hoisted(() => ({ issue: null }));
vi.mock('@/Layouts/PublicLayout', () => ({ default: ({ children }) => children }));
vi.mock('@/Pages/Public/Community/CommunitiesPanel', () => ({
    default: ({ onCredential }) => {
        callbacks.issue = () => onCredential({ selector: 'OLD', secret: 'PRIVATE-SECRET' });
        return <p>Community tools</p>;
    },
}));
it('rejects a recovery credential returned by a request from the previous identity', () => {
    window.history.replaceState({}, '', '/my?section=communities');
    try {
        render(
            <AttendeeApp
                verifiedEmail="a••@example.test"
                offline_identity={{
                    state: 'verified',
                    owner_key: 'a'.repeat(64),
                    proof_expires_at: Date.now() + 3600000,
                }}
            />
        );
        const old = callbacks.issue;
        act(() =>
            window.dispatchEvent(
                new StorageEvent('storage', {
                    key: 'miconvener_attendee_identity_v3',
                    newValue: null,
                })
            )
        );
        act(() => old());
        expect(screen.queryByText('OLD.PRIVATE-SECRET')).toBeNull();
    } finally {
        window.history.replaceState({}, '', '/');
    }
});
