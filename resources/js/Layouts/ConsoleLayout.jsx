import { Link, usePage } from '@inertiajs/react';
import {
    LayoutDashboard,
    Users,
    ShieldCheck,
    KeyRound,
    CreditCard,
    Wallet,
    Sparkles,
    SlidersHorizontal,
    Settings,
} from 'lucide-react';

const NAV_ITEMS = [
    { label: 'Dashboard', href: 'tenant.dashboard', icon: LayoutDashboard },
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
    const { tenant, auth, url } = usePage().props;
    const currentPath = typeof window !== 'undefined' ? window.location.pathname : url;

    return (
        <div className="flex h-screen overflow-hidden bg-surface font-console text-ink">
            <aside className="flex w-74 shrink-0 flex-col overflow-y-auto border-r border-border bg-surface-sunken px-4 py-5">
                <div className="flex items-center gap-3 px-2 pb-6">
                    <div className="grid h-12 w-12 shrink-0 place-items-center rounded-md bg-inverse text-sm font-semibold text-white">
                        {initials(tenant?.name ?? 'MC')}
                    </div>
                    <div className="min-w-0">
                        <div className="truncate text-[15px] font-semibold text-ink">{tenant?.name}</div>
                        <div className="truncate text-[13px] text-ink-secondary">{auth?.user?.email}</div>
                    </div>
                </div>

                <nav className="flex flex-col gap-0.5">
                    {NAV_ITEMS.filter((item) => !item.feature || tenant?.features?.[item.feature]).map((item) => {
                        const href = route(item.href);
                        const isActive = currentPath === new URL(href).pathname;
                        const Icon = item.icon;

                        return (
                            <Link
                                key={item.href}
                                href={href}
                                aria-current={isActive ? 'page' : undefined}
                                className={`flex items-center gap-3 rounded-md px-3 py-2.5 text-[15px] font-medium transition-colors duration-120 ease-out ${
                                    isActive ? 'bg-surface-hover text-ink' : 'text-ink-secondary hover:bg-surface-hover/70 hover:text-ink'
                                }`}
                            >
                                <Icon className="h-5 w-5 shrink-0" strokeWidth={1.75} />
                                {item.label}
                            </Link>
                        );
                    })}
                </nav>
            </aside>

            <div className="min-w-0 flex-1 overflow-y-auto">
                <main>{children}</main>
            </div>
        </div>
    );
}
