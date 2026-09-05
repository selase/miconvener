import { useEffect } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { useToast } from '@/Components/Console/Toast';
import {
    LayoutDashboard,
    Calendar,
    Users,
    ShieldCheck,
    KeyRound,
    CreditCard,
    Wallet,
    Sparkles,
    SlidersHorizontal,
    Settings,
} from 'lucide-react';
import ThemeToggle from '@/Components/Console/ThemeToggle';

const NAV_ITEMS = [
    { label: 'Dashboard', href: 'tenant.dashboard', icon: LayoutDashboard },
    { label: 'Events', href: 'tenant.events.index', icon: Calendar },
    { label: 'Team', href: 'tenant.users.index', icon: Users },
    { label: 'Roles', href: 'tenant.roles.index', icon: ShieldCheck },
    { label: 'API Keys', href: 'tenant.api-keys.index', icon: KeyRound },
    { label: 'Billing', href: 'billing.index', icon: CreditCard },
    { label: 'Finance', href: 'tenant.finance.index', icon: Wallet, feature: 'commerce' },
    { label: 'LLM Usage', href: 'tenant.llm-usage.index', icon: Sparkles },
    { label: 'LLM Config', href: 'tenant.llm-config.index', icon: SlidersHorizontal, feature: 'llm_byok' },
    { label: 'Settings', href: 'tenant.settings.index', icon: Settings },
];

function initials(name) {
    return name
        .split(' ')
        .map((part) => part[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();
}

export default function ConsoleLayout({ children }) {
    const { tenant, auth, url, flash } = usePage().props;
    const currentPath = typeof window !== 'undefined' ? window.location.pathname : url;
    const toast = useToast();

    useEffect(() => {
        if (flash?.error) {
            toast?.(flash.error);
        } else if (flash?.success) {
            toast?.(flash.success);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [flash?.success, flash?.error]);

    return (
        <div className="flex h-screen overflow-hidden bg-canvas font-console text-ink">
            <aside className="flex w-56 shrink-0 flex-col overflow-y-auto border-r border-border bg-surface">
                <div className="flex items-center gap-2.5 border-b border-border px-4.5 py-4">
                    <div className="grid h-8 w-8 shrink-0 place-items-center bg-inverse text-xs font-semibold text-inverse-ink">
                        {initials(tenant?.name ?? 'MC')}
                    </div>
                    <div className="min-w-0 flex-1">
                        <div className="truncate text-[13px] font-medium text-ink">{tenant?.name}</div>
                        <div className="truncate text-[11.5px] text-ink-secondary">{auth?.user?.email}</div>
                    </div>
                    <ThemeToggle />
                </div>

                <nav className="flex flex-col py-2">
                    {NAV_ITEMS.filter((item) => !item.feature || tenant?.features?.[item.feature]).map((item) => {
                        const href = route(item.href);
                        const isActive = currentPath === new URL(href).pathname;
                        const Icon = item.icon;

                        return (
                            <Link
                                key={item.href}
                                href={href}
                                aria-current={isActive ? 'page' : undefined}
                                className={`flex items-center gap-2.5 px-4.5 py-1.75 text-[13px] transition-colors duration-120 ease-out ${
                                    isActive
                                        ? 'bg-surface-hover text-ink shadow-[inset_2px_0_0_var(--color-accent)]'
                                        : 'text-ink-secondary hover:bg-surface-hover hover:text-ink'
                                }`}
                            >
                                <Icon className="h-4 w-4 shrink-0" strokeWidth={1.75} />
                                {item.label}
                            </Link>
                        );
                    })}
                </nav>
            </aside>

            <div className="min-w-0 flex-1 overflow-y-auto bg-surface">
                <main>{children}</main>
            </div>
        </div>
    );
}
