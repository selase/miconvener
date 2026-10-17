import { render, screen, fireEvent, within } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import AttendeeAppShell, {
    sectionFromLocation,
} from '@/Pages/Public/Events/AttendeePortal/components/AttendeeAppShell';

describe('personal app shell', () => {
    it('offers the same five sections on desktop and phone', () => {
        const change = vi.fn();
        render(
            <AttendeeAppShell section="home" onSectionChange={change}>
                <p>My content</p>
            </AttendeeAppShell>
        );
        for (const name of ['Personal app navigation', 'Mobile personal app navigation']) {
            const nav = within(screen.getByRole('navigation', { name }));
            expect(nav.getAllByRole('button').map((b) => b.textContent)).toEqual([
                'Home',
                'Tickets',
                'Communities',
                'Money',
                'Me',
            ]);
            fireEvent.click(nav.getByRole('button', { name: 'Money' }));
        }
        expect(change).toHaveBeenCalledWith('money');
        expect(screen.getByText('My content')).toBeTruthy();
    });
    it.each([
        ['?section=me', '', 'me'],
        ['?section=invalid', '', 'home'],
        ['?tab=certificates', '', 'tickets'],
        ['?tab=contributions', '', 'money'],
        ['', '#communities', 'communities'],
        ['?organiser=pilot', '', 'home'],
    ])('restores safe legacy and current locations %s', (search, hash, expected) => {
        expect(sectionFromLocation({ search, hash })).toBe(expected);
    });
});
