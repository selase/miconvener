/**
 * The console menu, grouped as in the Community spec (section 14). Plain data
 * with icon names, so it can be tested without React. Items carry an optional
 * `feature` (tenant.features key) and `permission` (auth.can key).
 */
export const NAV_GROUPS = [
    {
        key: 'top',
        items: [{ label: 'Dashboard', href: 'tenant.dashboard', icon: 'LayoutDashboard' }],
    },
    {
        key: 'events',
        label: 'Events',
        items: [{ label: 'Events', href: 'tenant.events.index', icon: 'Calendar' }],
    },
    {
        key: 'community',
        label: 'Community',
        items: [
            {
                label: 'Overview',
                href: 'tenant.community.overview',
                icon: 'LayoutGrid',
                feature: 'community',
                anyPermissions: ['manage_members', 'manage_giving'],
            },
            {
                label: 'People',
                href: 'tenant.community.people.index',
                icon: 'UsersRound',
                feature: 'community',
                permission: 'manage_members',
            },
            {
                label: 'Followers',
                href: 'tenant.community.followers.index',
                icon: 'UsersRound',
                feature: 'community_following',
                permission: 'manage_members',
            },
            {
                label: 'Follower announcements',
                href: 'tenant.following.announcements.index',
                icon: 'Inbox',
                feature: 'community_following',
                permission: 'send_community_messages',
            },
            {
                label: 'Messages',
                href: 'tenant.community.messages.index',
                icon: 'Inbox',
                anyFeatures: ['community_messages', 'community_inbox'],
                permission: 'send_community_messages',
            },
            {
                label: 'Polls & surveys',
                href: 'tenant.community.polls.index',
                icon: 'BarChart3',
                feature: 'community_polls_history',
                anyPermissions: ['manage_community_polls', 'read_community_poll_results'],
            },
            {
                label: 'Library',
                href: 'tenant.community.library.index',
                icon: 'FileText',
                feature: 'community_library_history',
                permission: 'manage_community_library',
            },
            {
                label: 'Giving',
                href: 'tenant.community.money.giving.index',
                icon: 'HandCoins',
                feature: 'giving_history',
                anyPermissions: ['manage_giving', 'read_finance'],
            },
            {
                label: 'Dues',
                href: 'tenant.community.money.dues.index',
                icon: 'Coins',
                feature: 'community',
                permission: 'manage_members',
            },
        ],
    },
    {
        key: 'venue',
        label: 'Venue',
        items: [
            {
                label: 'Facility operations',
                href: 'tenant.venue.operations.index',
                icon: 'ClipboardCheck',
                permission: 'manage_venue',
            },
            {
                label: 'Spaces',
                href: 'tenant.venue.spaces.index',
                icon: 'Building2',
                permission: 'manage_venue',
            },
            {
                label: 'Calendar',
                href: 'tenant.venue.calendar.index',
                icon: 'CalendarDays',
                permission: 'manage_venue',
            },
            {
                label: 'Staff bookings',
                href: 'tenant.venue.internal-bookings.index',
                icon: 'UserCheck',
                feature: 'staff_room_booking',
                permission: 'manage_room_bookings',
            },
            {
                label: 'Inquiries & leads',
                href: 'tenant.venue.inquiries.index',
                icon: 'Inbox',
                permission: 'manage_venue',
            },
            {
                label: 'RFQs & proposals',
                href: 'tenant.venue.quotes.index',
                icon: 'FileText',
                permission: 'manage_venue',
            },
        ],
    },
    {
        key: 'finance',
        label: 'Finance',
        items: [
            {
                label: 'Overview',
                href: 'tenant.finance.index',
                icon: 'Wallet',
                feature: 'finance',
                permission: 'read_finance',
            },
            {
                label: 'Payouts',
                href: 'tenant.finance.payouts.index',
                icon: 'HandCoins',
                feature: 'dues_payouts',
                permission: 'read_finance',
            },
        ],
    },
    {
        key: 'organisation',
        label: 'Organisation',
        items: [
            { label: 'Team', href: 'tenant.users.index', icon: 'Users' },
            { label: 'Roles', href: 'tenant.roles.index', icon: 'ShieldCheck' },
            {
                label: 'Billing',
                href: 'billing.index',
                icon: 'CreditCard',
                permission: 'manage_billing',
            },
            { label: 'Settings', href: 'tenant.settings.index', icon: 'Settings' },
            { label: 'API keys', href: 'tenant.api-keys.index', icon: 'KeyRound' },
            { label: 'AI usage', href: 'tenant.llm-usage.index', icon: 'Sparkles' },
            {
                label: 'AI configuration',
                href: 'tenant.llm-config.index',
                icon: 'SlidersHorizontal',
                feature: 'llm_byok',
            },
        ],
    },
    { key: 'bottom', items: [{ label: 'My account', href: 'tenant.account', icon: 'UserRound' }] },
];

/**
 * Whether a group's items are hidden. Unlabelled groups never collapse; the
 * group holding the current page never collapses; and nothing collapses in the
 * compact icon rail, which shows no labels to reopen a group with.
 */
export function isGroupCollapsed({ hasLabel, collapsed, containsActive, compact }) {
    return Boolean(hasLabel) && Boolean(collapsed) && !containsActive && !compact;
}

/**
 * The groups this person may see: items filtered by feature and permission,
 * and groups left with no items dropped.
 */
export function visibleGroups(groups, { features = {}, can = {} } = {}) {
    return groups
        .map((group) => ({
            ...group,
            items: group.items.filter(
                (item) =>
                    (!item.feature || features[item.feature]) &&
                    (!item.anyFeatures || item.anyFeatures.some((key) => features[key])) &&
                    (!item.permission || can[item.permission]) &&
                    (!item.anyPermissions || item.anyPermissions.some((key) => can[key]))
            ),
        }))
        .filter((group) => group.items.length > 0);
}
