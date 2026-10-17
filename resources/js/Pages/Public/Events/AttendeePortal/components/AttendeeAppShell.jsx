import { Home, Ticket, Users, Wallet, UserRound, WifiOff } from 'lucide-react';
import PublicLayout from '@/Layouts/PublicLayout';

export const APP_SECTIONS = [
    ['home', 'Home', Home],
    ['tickets', 'Tickets', Ticket],
    ['communities', 'Communities', Users],
    ['money', 'Money', Wallet],
    ['me', 'Me', UserRound],
];

export function sectionFromLocation({ search = '', hash = '' } = {}) {
    const params = new URLSearchParams(search);
    const requested = params.get('section');
    if (requested) return APP_SECTIONS.some(([key]) => key === requested) ? requested : 'home';
    if (hash === '#communities') return 'communities';
    if (['certificates', 'abstracts', 'attendance'].includes(params.get('tab'))) return 'tickets';
    if (params.get('tab') === 'contributions') return 'money';
    return 'home';
}

export default function AttendeeAppShell({
    section,
    onSectionChange,
    children,
    detail = null,
    offline = false,
}) {
    const navigation = (mobile) =>
        APP_SECTIONS.map(([key, label, Icon]) => (
            <button
                key={key}
                type="button"
                aria-current={section === key ? 'page' : undefined}
                onClick={() => onSectionChange(key)}
                className={`flex min-h-12 min-w-0 items-center gap-2 px-3 py-3 text-sm transition-colors ${mobile ? 'flex-col justify-center gap-1 px-0 text-[11px]' : 'text-left'} ${section === key ? 'bg-accent/10 font-medium text-accent' : 'text-ink-secondary hover:bg-surface-hover hover:text-ink'}`}
            >
                <Icon className="h-4 w-4 shrink-0" aria-hidden="true" />
                <span>{label}</span>
            </button>
        ));
    return (
        <PublicLayout>
            <div className="attendee-app mx-auto max-w-[1440px] pb-24 md:pb-0">
                <a
                    href="#attendee-main"
                    className="sr-only focus:not-sr-only focus:block focus:p-4"
                >
                    Skip to content
                </a>
                {offline && (
                    <p
                        role="status"
                        className="flex items-center gap-2 border-b border-border bg-accent/5 px-6 py-3 text-sm text-ink-secondary"
                    >
                        <WifiOff className="h-4 w-4" aria-hidden="true" />
                        You’re offline. Only saved ticket passes are available.
                    </p>
                )}
                <div className="min-h-[calc(100vh-65px)] md:grid md:grid-cols-[180px_minmax(0,1fr)] lg:grid-cols-[210px_minmax(0,1fr)]">
                    <nav
                        aria-label="Personal app navigation"
                        className="hidden border-r border-border bg-surface p-4 md:flex md:flex-col md:gap-1"
                    >
                        {navigation(false)}
                    </nav>
                    <main id="attendee-main" className="min-w-0 px-5 py-7 sm:px-8 lg:px-10 lg:py-9">
                        {detail ? (
                            <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_300px]">
                                <div className="min-w-0">{children}</div>
                                <aside className="min-w-0">{detail}</aside>
                            </div>
                        ) : (
                            children
                        )}
                    </main>
                </div>
                <nav
                    aria-label="Mobile personal app navigation"
                    className="fixed inset-x-0 bottom-0 z-30 grid grid-cols-5 gap-0.5 border-t border-border bg-surface px-2 pb-[max(env(safe-area-inset-bottom),8px)] pt-2 md:hidden"
                >
                    {navigation(true)}
                </nav>
            </div>
        </PublicLayout>
    );
}
