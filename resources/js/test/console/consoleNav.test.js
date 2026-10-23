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

    it('shows Staff bookings only with the plan feature and the permission', () => {
        const labels = (context) =>
            visibleGroups(NAV_GROUPS, context)
                .find((g) => g.key === 'venue')
                ?.items.map((i) => i.label) ?? [];

        expect(
            labels({
                features: { staff_room_booking: true },
                can: { manage_venue: true, manage_room_bookings: true },
            })
        ).toContain('Staff bookings');
        expect(
            labels({ features: {}, can: { manage_venue: true, manage_room_bookings: true } })
        ).not.toContain('Staff bookings');
        expect(
            labels({ features: { staff_room_booking: true }, can: { manage_venue: true } })
        ).not.toContain('Staff bookings');
    });

    it('keeps the order of the spec', () => {
        expect(NAV_GROUPS.map((g) => g.key)).toEqual([
            'top',
            'events',
            'community',
            'store',
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

it('keeps sent inbox announcements reachable when sending is switched off', () => {
    const group = visibleGroups(NAV_GROUPS, {
        features: { community: true, community_inbox: true },
        can: { send_community_messages: true },
    }).find((g) => g.key === 'community');
    expect(group.items.some((item) => item.label === 'Messages')).toBe(true);
});
it('giving staff can reach Overview without member management', () => {
    const community = visibleGroups(NAV_GROUPS, {
        features: { community: true, community_giving: true },
        can: { manage_giving: true },
    }).find((g) => g.key === 'community');
    expect(community.items.map((i) => i.href)).toContain('tenant.community.overview');
    expect(community.items.map((i) => i.href)).not.toContain('tenant.community.people.index');
});

it('keeps Library reachable for authorised staff with retained documents', () => {
    const groups = visibleGroups(NAV_GROUPS, {
        features: { community: false, community_library_history: true },
        can: { manage_community_library: true },
    });
    expect(
        groups.find((group) => group.key === 'community').items.map((item) => item.label)
    ).toContain('Library');
    const hidden = visibleGroups(NAV_GROUPS, {
        features: { community_library_history: true },
        can: {},
    });
    expect(hidden.find((group) => group.key === 'community')).toBeUndefined();
});

describe('Store catalog navigation', () => {
    it('requires the catalog rollout and its own permission without needing Community', () => {
        const keys = (context) => visibleGroups(NAV_GROUPS, context).map((group) => group.key);
        expect(
            keys({ features: { commerce_catalog: true }, can: { manage_store_catalog: true } })
        ).toContain('store');
        expect(keys({ features: {}, can: { manage_store_catalog: true } })).not.toContain('store');
        expect(
            keys({ features: { commerce_catalog: true }, can: { manage_venue: true } })
        ).not.toContain('store');
    });
});

it('hides inactive empty Store navigation but retains authorised order and finance recovery', () => {
    const can = {
        manage_store_orders: true,
        manage_store_finance: true,
        manage_store_catalog: true,
    };
    expect(
        visibleGroups(NAV_GROUPS, { features: {}, can }).find((g) => g.key === 'store')
    ).toBeUndefined();
    const retained = visibleGroups(NAV_GROUPS, { features: { commerce_history: true }, can }).find(
        (g) => g.key === 'store'
    );
    expect(retained.items.map((i) => i.label)).toEqual(['Store orders', 'Store finance']);
    expect(
        visibleGroups(NAV_GROUPS, { features: { commerce_history: true }, can: {} }).find(
            (g) => g.key === 'store'
        )
    ).toBeUndefined();
});
