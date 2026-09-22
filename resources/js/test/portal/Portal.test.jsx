import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent, within } from '@testing-library/react';
import Portal from '@/Pages/Public/Events/AttendeePortal/Portal';
import { portalProps, keynote } from '../fixtures/portal';

vi.mock('@/Layouts/PublicLayout', () => ({ default: ({ children }) => children }));

const tabLabels = () =>
    within(screen.getByRole('navigation'))
        .queryAllByRole('button')
        .map((button) => button.textContent);

describe('attendee portal', () => {
    it('offers only the ticket when nothing else can be acted on', () => {
        render(<Portal {...portalProps({ event: { sessions: [] } })} />);
        expect(tabLabels()).toEqual(['My ticket']);
    });

    it('offers every tab once each has something in it, in order', () => {
        render(<Portal {...portalProps({ canRequestHelp: true, materials: [keynote] })} />);
        expect(tabLabels()).toEqual(['My ticket', 'My day', 'Get help', 'Downloads']);
    });

    it('shows no tabs until the address is confirmed', () => {
        render(<Portal {...portalProps({ registration: { email_verified: false } })} />);
        expect(screen.getByText(/Almost there, Ama Serwaa/)).toBeTruthy();
        expect(tabLabels()).toEqual([]);
    });

    it('shows the status notice instead of the portal for a cancelled registration', () => {
        render(<Portal {...portalProps({ registration: { status: 'cancelled' } })} />);
        expect(screen.getByText('Registration cancelled')).toBeTruthy();
        expect(screen.queryByRole('navigation')).toBeNull();
    });

    it('lists released materials on the Downloads tab', () => {
        render(<Portal {...portalProps({ materials: [keynote] })} />);
        fireEvent.click(screen.getByRole('button', { name: 'Downloads' }));
        expect(screen.getByText('Keynote')).toBeTruthy();
    });
});
