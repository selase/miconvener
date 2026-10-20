import { useEffect, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { useToast } from '@/Components/Console/Toast';
import {
    LayoutDashboard,
    LayoutGrid,
    LogOut,
    Calendar,
    ChevronDown,
    Coins,
    Users,
    ShieldCheck,
    KeyRound,
    CreditCard,
    Wallet,
    UsersRound,
    HandCoins,
    Sparkles,
    SlidersHorizontal,
    Settings,
    UserRound,
    Building2,
    CalendarDays,
    Inbox,
    ClipboardCheck,
    UserCheck,
    FileText,
    Menu,
    X,
} from 'lucide-react';
import ThemeToggle from '@/Components/Console/ThemeToggle';
import { NAV_GROUPS, isGroupCollapsed, visibleGroups } from '@/Layouts/consoleNav';

const ICONS = {
    LayoutDashboard,
    LayoutGrid,
    Calendar,
    Coins,
    Users,
    ShieldCheck,
    KeyRound,
    CreditCard,
    Wallet,
    UsersRound,
    HandCoins,
    Sparkles,
    SlidersHorizontal,
    Settings,
    UserRound,
    Building2,
    CalendarDays,
    Inbox,
    ClipboardCheck,
    UserCheck,
    FileText,
};

const COLLAPSE_KEY = (key) => `console.nav.collapsed.${key}`;

/** Storage can throw (private windows, blocked site data); the menu then stays expanded. */
function readCollapsed(key) {
    try {
        return window.localStorage.getItem(COLLAPSE_KEY(key)) === '1';
    } catch {
        return false;
    }
}

function writeCollapsed(key, collapsed) {
    try {
        window.localStorage.setItem(COLLAPSE_KEY(key), collapsed ? '1' : '0');
    } catch {
        // Not remembering the choice is fine.
    }
}

function pathOf(href) {
    return new URL(href).pathname;
}

function initials(name) {
    return name
        .split(' ')
        .map((part) => part[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();
}

/**
 * @param {boolean} compact  Inside an event the main menu shrinks to an icon rail
 *                          from md up, which pays for the event's own section
 *                          column. The phone drawer is unchanged.
 */
export default function ConsoleLayout({ children, compact = false }) {
    const { tenant, auth, url, flash } = usePage().props;
    const currentPath = typeof window !== 'undefined' ? window.location.pathname : url;
    const toast = useToast();
    // Below md the sidebar is an off-canvas drawer. Fixed at 224px it left a
    // phone about 165px of content: too little to reach a registration's
    // approve button or read the check-in station names.
    const [navOpen, setNavOpen] = useState(false);
    const groups = visibleGroups(NAV_GROUPS, {
        features: tenant?.features ?? {},
        can: auth?.can ?? {},
    });
    const [collapsed, setCollapsed] = useState(() =>
        typeof window === 'undefined'
            ? {}
            : Object.fromEntries(NAV_GROUPS.map((group) => [group.key, readCollapsed(group.key)]))
    );
    const toggleGroup = (key) =>
        setCollapsed((current) => {
            const next = { ...current, [key]: !current[key] };
            writeCollapsed(key, next[key]);
            return next;
        });
    // The item whose path is the longest prefix of the current page is the
    // active one, so Community > Overview (/community) is not lit on
    // /community/people, and Events stays lit inside /events/{id}.
    const activeHref = groups
        .flatMap((group) => group.items)
        .map((item) => ({ href: item.href, path: pathOf(route(item.href)) }))
        .filter(({ path }) => currentPath === path || currentPath.startsWith(`${path}/`))
        .sort((a, b) => b.path.length - a.path.length)[0]?.href;

    useEffect(() => {
        if (!navOpen) return undefined;
        const onKey = (event) => event.key === 'Escape' && setNavOpen(false);
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [navOpen]);

    useEffect(() => {
        if (flash?.error) {
            toast?.(flash.error);
        } else if (flash?.success) {
            toast?.(flash.success);
        }
        // Dependencies deliberately limited to the ids above (re-run only when they change).
    }, [flash?.success, flash?.error]);

    return (
        <div className="flex h-screen overflow-hidden bg-canvas font-console text-ink">
            {navOpen && (
                <div
                    className="fixed inset-0 z-35 bg-ink/30 md:hidden"
                    aria-hidden="true"
                    onClick={() => setNavOpen(false)}
                />
            )}
            <aside
                id="console-nav"
                className={`fixed inset-y-0 left-0 z-35 flex w-64 shrink-0 flex-col overflow-y-auto border-r border-border bg-surface transition-transform duration-200 ease-out motion-reduce:transition-none md:static md:z-auto ${compact ? 'md:w-14' : 'md:w-56'} md:translate-x-0 ${
                    navOpen ? 'translate-x-0' : '-translate-x-full'
                }`}
            >
                <div
                    className={`flex items-center gap-2.5 border-b border-border px-4.5 py-4 ${compact ? 'md:justify-center md:px-0' : ''}`}
                >
                    <div className="grid h-8 w-8 shrink-0 place-items-center bg-inverse text-xs font-semibold text-inverse-ink">
                        {initials(tenant?.name ?? 'MC')}
                    </div>
                    <div className={`min-w-0 flex-1 ${compact ? 'md:hidden' : ''}`}>
                        <div className="truncate text-[13px] font-medium text-ink">
                            {tenant?.name}
                        </div>
                        <div className="truncate text-[11.5px] text-ink-secondary">
                            {auth?.user?.email}
                        </div>
                    </div>
                    <div className={compact ? 'md:hidden' : undefined}>
                        <ThemeToggle />
                    </div>
                    <button
                        type="button"
                        onClick={() => setNavOpen(false)}
                        className="grid h-8 w-8 place-items-center text-ink-secondary hover:text-ink md:hidden"
                        aria-label="Close navigation"
                    >
                        <X className="h-4 w-4" strokeWidth={1.75} />
                    </button>
                </div>

                <nav className="flex flex-col py-2">
                    {groups.map((group, index) => {
                        const containsActive = group.items.some((item) => item.href === activeHref);
                        const isCollapsed = isGroupCollapsed({
                            hasLabel: Boolean(group.label),
                            collapsed: collapsed[group.key],
                            containsActive,
                            compact,
                        });

                        return (
                            <div key={group.key} className={index > 0 ? 'mt-1' : undefined}>
                                {group.label && (
                                    <>
                                        <button
                                            type="button"
                                            onClick={() => toggleGroup(group.key)}
                                            aria-expanded={!isCollapsed}
                                            className={`flex w-full items-center justify-between px-4.5 pb-1 pt-3 text-[11px] font-semibold uppercase tracking-wide text-ink-tertiary hover:text-ink ${
                                                compact ? 'md:hidden' : ''
                                            }`}
                                        >
                                            {group.label}
                                            <ChevronDown
                                                className={`h-3.5 w-3.5 transition-transform motion-reduce:transition-none ${isCollapsed ? '-rotate-90' : ''}`}
                                                strokeWidth={1.75}
                                            />
                                        </button>
                                        {compact && (
                                            <div className="mx-3 my-1.5 hidden border-t border-border md:block" />
                                        )}
                                    </>
                                )}
                                {!isCollapsed &&
                                    group.items.map((item) => {
                                        const href = route(item.href);
                                        const isActive = item.href === activeHref;
                                        const Icon = ICONS[item.icon] ?? LayoutGrid;

                                        return (
                                            <Link
                                                key={item.href}
                                                href={href}
                                                aria-current={isActive ? 'page' : undefined}
                                                onClick={() => setNavOpen(false)}
                                                title={compact ? item.label : undefined}
                                                className={`flex items-center gap-2.5 px-4.5 py-1.75 text-[13px] transition-colors duration-120 ease-out ${
                                                    compact
                                                        ? 'md:justify-center md:px-0 md:py-2.5'
                                                        : ''
                                                } ${
                                                    isActive
                                                        ? 'bg-surface-hover text-ink shadow-[inset_2px_0_0_var(--color-accent)]'
                                                        : 'text-ink-secondary hover:bg-surface-hover hover:text-ink'
                                                }`}
                                            >
                                                <Icon
                                                    className="h-4 w-4 shrink-0"
                                                    strokeWidth={1.75}
                                                />
                                                <span
                                                    className={compact ? 'md:sr-only' : undefined}
                                                >
                                                    {item.label}
                                                </span>
                                            </Link>
                                        );
                                    })}
                            </div>
                        );
                    })}
                </nav>

                {/* A console is signed into on venue laptops and shared desks,
                    so there has to be a way out of it that is not clearing
                    cookies. Inertia's method="post" carries the CSRF token. */}
                <div className="mt-auto border-t border-border py-2">
                    <Link
                        href={route('logout')}
                        method="post"
                        as="button"
                        type="button"
                        onClick={() => setNavOpen(false)}
                        title={compact ? 'Sign out' : undefined}
                        className={`flex w-full items-center gap-2.5 px-4.5 py-1.75 text-[13px] text-ink-secondary transition-colors duration-120 ease-out hover:bg-surface-hover hover:text-ink ${
                            compact ? 'md:justify-center md:px-0 md:py-2.5' : ''
                        }`}
                    >
                        <LogOut className="h-4 w-4 shrink-0" strokeWidth={1.75} />
                        <span className={compact ? 'md:sr-only' : undefined}>Sign out</span>
                    </Link>
                </div>
            </aside>

            <div className="min-w-0 flex-1 overflow-y-auto bg-surface">
                <div className="flex items-center gap-3 border-b border-border bg-surface px-4 py-2.5 md:hidden">
                    <button
                        type="button"
                        onClick={() => setNavOpen(true)}
                        className="grid h-9 w-9 place-items-center rounded-md text-ink hover:bg-surface-hover"
                        aria-label="Open navigation"
                        aria-controls="console-nav"
                        aria-expanded={navOpen}
                    >
                        <Menu className="h-5 w-5" strokeWidth={1.75} />
                    </button>
                    <span className="truncate text-[13px] font-medium text-ink">
                        {tenant?.name}
                    </span>
                </div>
                <main>{children}</main>
            </div>
        </div>
    );
}
