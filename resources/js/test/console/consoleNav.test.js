import { describe, expect, it } from 'vitest';
import { NAV_GROUPS, visibleGroups } from '@/Layouts/consoleNav';

describe('visibleGroups', () => {
    it('hides the Community group without the community feature', () => {
        const keys = visibleGroups(NAV_GROUPS, { features: {}, can: { manage_members: true } }).map(
            (g) => g.key
        );
        expect(keys).not.toContain('community');
    });

    it('hides the Venue group for organisations without manage_venue', () => {
        const keys = visibleGroups(NAV_GROUPS, { features: { community: true }, can: {} }).map(
            (g) => g.key
        );
        expect(keys).not.toContain('venue');
    });

    it('shows Payouts only with the community feature and read_finance', () => {
        const finance = visibleGroups(NAV_GROUPS, {
            features: { community: true, finance: true },
            can: { read_finance: true },
        }).find((g) => g.key === 'finance');
        expect(finance.items.map((i) => i.label)).toEqual(['Overview', 'Payouts']);
    });

    it('keeps the order of the spec', () => {
        expect(NAV_GROUPS.map((g) => g.key)).toEqual([
            'top',
            'events',
            'community',
            'venue',
            'finance',
            'organisation',
            'bottom',
        ]);
    });

    it('never returns an empty group', () => {
        visibleGroups(NAV_GROUPS, {}).forEach((g) => expect(g.items.length).toBeGreaterThan(0));
    });
});
