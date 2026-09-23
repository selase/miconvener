import { useEffect, useRef, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import PublicLayout from '@/Layouts/PublicLayout';
import {
    ArrowLeft,
    Calendar,
    CheckCircle2,
    Clock,
    Download,
    HelpCircle,
    ListOrdered,
    Loader2,
    MoreHorizontal,
    RefreshCw,
    Ticket,
    WifiOff,
    XCircle,
} from 'lucide-react';
import MyTicketPanel from './panels/MyTicketPanel';
import MyDayPanel from './panels/MyDayPanel';
import GetHelpPanel from './panels/GetHelpPanel';
import DownloadsPanel from './panels/DownloadsPanel';
import { getOfflineTicket, purgeExpiredOfflineTickets } from '@/lib/offlineTicketStore';
import { registerAttendeeServiceWorker } from '@/lib/registerServiceWorker';

const STATUS_NOTICE = {
    pending_payment: {
        icon: Clock,
        color: 'text-warning-fg',
        title: (registration) =>
            registration.awaiting_checkout ? 'One step left' : 'Confirming payment',
        description: (event, registration) =>
            registration.awaiting_checkout
                ? `Your place at ${event.name} is approved. Pay to secure it — your spot is held once the payment goes through.`
                : `We're confirming your payment for ${event.name}. This page will show your ticket once it's confirmed — check your email shortly.`,
    },
    pending_approval: {
        icon: Clock,
        color: 'text-warning-fg',
        title: 'Awaiting approval',
        description: (event) =>
            `The organizers of ${event.name} review registrations before confirming a spot. We'll email you as soon as yours is reviewed.`,
    },
    waitlisted: {
        icon: ListOrdered,
        color: 'text-warning-fg',
        title: "You're on the waitlist",
        description: (event, registration) =>
            `${event.name} is at capacity — you're number ${registration.waitlist_position} in line. We'll email you the moment a spot opens up.`,
    },
    rejected: {
        icon: XCircle,
        color: 'text-danger-fg',
        title: 'Registration not approved',
        description: (event) => `The organizers of ${event.name} could not confirm this registration.`,
    },
    cancelled: {
        icon: XCircle,
        color: 'text-danger-fg',
        title: 'Registration cancelled',
        description: (event) => `This registration for ${event.name} has been cancelled.`,
    },
};

/**
 * The attendee's page for one registration, reached by the link in their
 * confirmation email, Paystack return, or central attendee dashboard.
 */
export default function Portal({
    event,
    registration,
    materials = [],
    canRequestHelp = false,
    poll_payment = false,
}) {
    const [currentRegistration, setCurrentRegistration] = useState(registration);
    const [isPolling, setIsPolling] = useState(
        poll_payment || (registration.status === 'pending_payment' && !registration.awaiting_checkout)
    );
    const [pollingDelayed, setPollingDelayed] = useState(false);
    const [isOnline, setIsOnline] = useState(typeof navigator !== 'undefined' ? navigator.onLine : true);
    const [offlineSnapshot, setOfflineSnapshot] = useState(null);
    const pollAttempts = useRef(0);
    const MAX_POLL_ATTEMPTS = 24; // 2s initial + ~23 * 5s = ~2 minutes

    useEffect(() => {
        registerAttendeeServiceWorker();
        purgeExpiredOfflineTickets();
        setOfflineSnapshot(getOfflineTicket(registration.id));

        const handleOnline = () => setIsOnline(true);
        const handleOffline = () => setIsOnline(false);

        window.addEventListener('online', handleOnline);
        window.addEventListener('offline', handleOffline);

        return () => {
            window.removeEventListener('online', handleOnline);
            window.removeEventListener('offline', handleOffline);
        };
    }, [registration.id]);

    useEffect(() => {
        if (!isPolling) return;

        let timer;
        const checkStatus = async () => {
            pollAttempts.current += 1;
            try {
                const res = await fetch(`/my/events/${currentRegistration.id}/status`, {
                    headers: { Accept: 'application/json' },
                });
                if (res.ok) {
                    const data = await res.json();
                    if (data.is_confirmed) {
                        setIsPolling(false);
                        router.reload();
                        return;
                    }
                }
            } catch {
                // Network failure during polling is tolerated; next poll will retry
            }

            if (pollAttempts.current >= MAX_POLL_ATTEMPTS) {
                setIsPolling(false);
                setPollingDelayed(true);
            } else {
                timer = setTimeout(checkStatus, 5000);
            }
        };

        // First status check at 2 seconds
        timer = setTimeout(checkStatus, 2000);

        return () => clearTimeout(timer);
    }, [isPolling, currentRegistration.id]);

    const notice = STATUS_NOTICE[currentRegistration.status];
    const [tab, setTab] = useState('ticket');
    const [agendaIds, setAgendaIds] = useState(currentRegistration.agenda_session_ids ?? []);

    // Nothing on these tabs is usable until the ticket exists, and it does not
    // exist until the address behind a free registration has been confirmed.
    const verified = currentRegistration.email_verified !== false;
    const desktopTabs = verified ? [['ticket', 'My ticket', Ticket]] : [];
    if (verified && event.sessions?.length > 0) desktopTabs.push(['agenda', 'My day', Calendar]);
    if (verified && canRequestHelp) desktopTabs.push(['help', 'Get help', HelpCircle]);
    if (verified && materials.length > 0) desktopTabs.push(['downloads', 'Downloads', Download]);

    const restartPolling = () => {
        pollAttempts.current = 0;
        setPollingDelayed(false);
        setIsPolling(true);
    };

    return (
        <PublicLayout>
            <Head>
                <link rel="manifest" href="/my/manifest.json" />
                <meta name="theme-color" content="#4f46e5" />
                <meta name="apple-mobile-web-app-capable" content="yes" />
                <meta name="apple-mobile-web-app-status-bar-style" content="default" />
            </Head>

            <div className="mx-auto max-w-2xl px-6 py-12 sm:px-10 pb-24 md:pb-16">
                {/* Offline banner */}
                {!isOnline && (
                    <div
                        className="mb-6 flex items-center justify-between gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200"
                        role="alert"
                    >
                        <div className="flex items-center gap-2">
                            <WifiOff className="h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" />
                            <span>
                                You are currently offline. Your saved ticket and personal agenda remain accessible.
                            </span>
                        </div>
                        {offlineSnapshot?.last_confirmed_at && (
                            <span className="shrink-0 text-ink-secondary text-[11px]">
                                Confirmed {new Date(offlineSnapshot.last_confirmed_at).toLocaleDateString()}
                            </span>
                        )}
                    </div>
                )}

                {notice ? (
                    <div className="text-center">
                        {isPolling ? (
                            <Loader2
                                className="mx-auto h-10 w-10 animate-spin text-accent"
                                strokeWidth={1.5}
                            />
                        ) : (
                            <notice.icon
                                className={`mx-auto h-10 w-10 ${notice.color}`}
                                strokeWidth={1.5}
                            />
                        )}
                        <h1 className="mt-5 text-2xl font-normal tracking-tight text-ink">
                            {isPolling
                                ? 'Confirming payment...'
                                : typeof notice.title === 'function'
                                  ? notice.title(currentRegistration)
                                  : notice.title}
                        </h1>
                        <p className="mt-3 text-[13.5px] text-ink-secondary">
                            {isPolling
                                ? `We're confirming your payment with Paystack for ${event.name}. Your ticket will appear here automatically.`
                                : pollingDelayed
                                  ? `Payment confirmation is taking a little longer than usual. You can check again below, or we'll email your ticket to you as soon as it clears.`
                                  : notice.description(event, currentRegistration)}
                        </p>
                        {pollingDelayed && (
                            <button
                                onClick={restartPolling}
                                className="mt-6 inline-flex items-center gap-2 rounded-lg border border-border bg-surface px-5 py-2.5 text-[13.5px] font-medium text-ink hover:border-accent cursor-pointer"
                            >
                                <RefreshCw className="h-4 w-4" />
                                Check again
                            </button>
                        )}
                        {currentRegistration.awaiting_checkout && currentRegistration.checkout_url && (
                            <a
                                href={currentRegistration.checkout_url}
                                className="mt-6 inline-flex items-center rounded-lg bg-accent px-5 py-2.5 text-[13.5px] font-medium text-white hover:opacity-90"
                            >
                                Complete payment
                            </a>
                        )}
                    </div>
                ) : (
                    <>
                        <div className="text-center">
                            <CheckCircle2
                                className="mx-auto h-10 w-10 text-accent"
                                strokeWidth={1.5}
                            />
                            <h1 className="mt-5 text-2xl font-normal tracking-tight text-ink">
                                {verified
                                    ? `You're confirmed, ${registration.full_name}!`
                                    : `Almost there, ${registration.full_name}`}
                            </h1>
                            <p className="mt-3 text-[13.5px] text-ink-secondary">
                                {verified
                                    ? `Your ticket for ${event.name} is ready. We've also emailed it to you.`
                                    : `We've emailed ${registration.email}. Confirm your address there and your ticket is issued straight away.`}
                            </p>
                            {!verified && (
                                <p className="mt-5 border border-border bg-surface px-5 py-4 text-[13px] text-ink-secondary">
                                    Can't find it? Check your spam folder, or register again with
                                    the same address and we'll send the link once more.
                                </p>
                            )}
                        </div>

                        {/* Desktop Navigation (>= 768px) */}
                        <nav
                            aria-label="Event workspace navigation"
                            className={`mt-9 mb-7 hidden md:flex justify-center gap-1 ${desktopTabs.length > 0 ? 'border-b border-border' : ''}`}
                        >
                            <Link
                                href="/my"
                                className="-mb-px flex items-center gap-1.5 border-b border-transparent px-3.5 py-2.5 text-[13px] text-ink-secondary hover:text-ink min-h-[44px]"
                            >
                                <ArrowLeft className="h-3.5 w-3.5" />
                                All events
                            </Link>
                            {desktopTabs.map(([key, label, Icon]) => (
                                <button
                                    key={key}
                                    type="button"
                                    onClick={() => setTab(key)}
                                    className={`-mb-px flex items-center gap-1.5 border-b px-3.5 py-2.5 text-[13px] font-medium min-h-[44px] cursor-pointer ${
                                        tab === key
                                            ? 'border-accent text-accent'
                                            : 'border-transparent text-ink-secondary hover:text-ink'
                                    }`}
                                >
                                    {Icon && <Icon className="h-3.5 w-3.5" />}
                                    <span>{label}</span>
                                </button>
                            ))}
                        </nav>

                        {/* Mobile "More" sub-nav header when help or downloads is active */}
                        {(tab === 'help' || tab === 'downloads') && (
                            <div className="mt-6 mb-6 flex justify-center gap-2 border-b border-border pb-3 md:hidden">
                                {canRequestHelp && (
                                    <button
                                        type="button"
                                        onClick={() => setTab('help')}
                                        className={`rounded-lg px-3.5 py-2 text-xs font-medium min-h-[44px] ${
                                            tab === 'help'
                                                ? 'bg-accent text-white'
                                                : 'bg-surface-subtle text-ink-secondary'
                                        }`}
                                    >
                                        Get help
                                    </button>
                                )}
                                {materials.length > 0 && (
                                    <button
                                        type="button"
                                        onClick={() => setTab('downloads')}
                                        className={`rounded-lg px-3.5 py-2 text-xs font-medium min-h-[44px] ${
                                            tab === 'downloads'
                                                ? 'bg-accent text-white'
                                                : 'bg-surface-subtle text-ink-secondary'
                                        }`}
                                    >
                                        Downloads ({materials.length})
                                    </button>
                                )}
                            </div>
                        )}

                        {/* Panels */}
                        {verified && tab === 'ticket' && (
                            <MyTicketPanel
                                event={event}
                                registration={registration}
                                isOnline={isOnline}
                            />
                        )}
                        {tab === 'agenda' && (
                            <MyDayPanel
                                event={event}
                                registration={registration}
                                agendaIds={agendaIds}
                                setAgendaIds={setAgendaIds}
                            />
                        )}
                        {tab === 'help' && (
                            <GetHelpPanel event={event} registration={registration} />
                        )}
                        {tab === 'downloads' && <DownloadsPanel materials={materials} />}

                        {/* Mobile Safe-Area Bottom Navigation Bar (< 768px) */}
                        {verified && (
                            <nav
                                aria-label="Mobile workspace navigation"
                                className="fixed bottom-0 left-0 right-0 z-40 md:hidden border-t border-border bg-surface/95 backdrop-blur-md pb-[env(safe-area-inset-bottom)] shadow-lg"
                            >
                                <div className="grid grid-cols-4 h-16">
                                    <Link
                                        href="/my"
                                        className="flex flex-col items-center justify-center gap-1 text-[11px] text-ink-secondary hover:text-ink min-h-[44px]"
                                    >
                                        <ArrowLeft className="h-4 w-4" />
                                        <span>Overview</span>
                                    </Link>
                                    <button
                                        type="button"
                                        onClick={() => setTab('ticket')}
                                        className={`flex flex-col items-center justify-center gap-1 text-[11px] min-h-[44px] cursor-pointer ${
                                            tab === 'ticket'
                                                ? 'text-accent font-semibold'
                                                : 'text-ink-secondary hover:text-ink'
                                        }`}
                                    >
                                        <Ticket className="h-4 w-4" />
                                        <span>Ticket</span>
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setTab('agenda')}
                                        className={`flex flex-col items-center justify-center gap-1 text-[11px] min-h-[44px] cursor-pointer ${
                                            tab === 'agenda'
                                                ? 'text-accent font-semibold'
                                                : 'text-ink-secondary hover:text-ink'
                                        }`}
                                    >
                                        <Calendar className="h-4 w-4" />
                                        <span>My day</span>
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setTab(
                                                tab === 'help' || tab === 'downloads'
                                                    ? tab
                                                    : canRequestHelp
                                                      ? 'help'
                                                      : 'downloads'
                                            )
                                        }
                                        className={`flex flex-col items-center justify-center gap-1 text-[11px] min-h-[44px] cursor-pointer ${
                                            tab === 'help' || tab === 'downloads'
                                                ? 'text-accent font-semibold'
                                                : 'text-ink-secondary hover:text-ink'
                                        }`}
                                    >
                                        <MoreHorizontal className="h-4 w-4" />
                                        <span>More</span>
                                    </button>
                                </div>
                            </nav>
                        )}
                    </>
                )}
            </div>
        </PublicLayout>
    );
}
