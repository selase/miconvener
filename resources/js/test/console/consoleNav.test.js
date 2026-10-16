import { describe, expect, it } from 'vitest';
import { NAV_GROUPS, isGroupCollapsed, visibleGroups } from '@/Layouts/consoleNav';

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

    it('shows Payouts with dues payouts and read_finance, even with Community off', () => {
        const finance = visibleGroups(NAV_GROUPS, {
            features: { community: false, dues_payouts: true, finance: true },
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

describe('isGroupCollapsed', () => {
    it('collapses a labelled group the person collapsed', () => {
        expect(
            isGroupCollapsed({
                hasLabel: true,
                collapsed: true,
                containsActive: false,
                compact: false,
            })
        ).toBe(true);
    });

    it('never collapses the group holding the current page', () => {
        expect(
            isGroupCollapsed({
                hasLabel: true,
                collapsed: true,
                containsActive: true,
                compact: false,
            })
        ).toBe(false);
    });

    it('never collapses anything in the compact icon rail, which has no labels to reopen them', () => {
        expect(
            isGroupCollapsed({
                hasLabel: true,
                collapsed: true,
                containsActive: false,
                compact: true,
            })
        ).toBe(false);
    });
});

it('gates Community Messages by its own rollout and sending permission', () => {
    const allowed = {
        features: { community: true, community_messages: true },
        can: { send_community_messages: true },
    };
    expect(
        visibleGroups(NAV_GROUPS, allowed)
            .flatMap((group) => group.items)
            .some((item) => item.href === 'tenant.community.messages.index')
    ).toBe(true);
    for (const context of [
        { ...allowed, features: { community: true } },
        { ...allowed, can: { manage_members: true } },
    ]) {
        expect(
            visibleGroups(NAV_GROUPS, context)
                .flatMap((group) => group.items)
                .some((item) => item.href === 'tenant.community.messages.index')
        ).toBe(false);
    }
});
