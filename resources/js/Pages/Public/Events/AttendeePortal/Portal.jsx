import AttendeeTickets from './components/AttendeeTickets';
import {
    reconcileAttendeeIdentity,
    IDENTITY_EVENT,
    isIdentityStorageEvent,
} from '@/lib/attendeeIdentityCache';
import { useEffect, useRef, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import PublicLayout from '@/Layouts/PublicLayout';
import {
    ArrowLeft,
    BarChart2,
    Calendar,
    CheckCircle2,
    Clock,
    Download,
    FileText,
    HelpCircle,
    ListOrdered,
    Loader2,
    MessageSquare,
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
import PollPanel from './panels/PollPanel';
import ForumPanel from './panels/ForumPanel';
import FormsPanel from './panels/FormsPanel';
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
        description: (event) =>
            `The organizers of ${event.name} could not confirm this registration.`,
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
    has_live_poll = false,
    has_forum = false,
    has_forms = false,
    active_service_requests_count = 0,
    certificate = null,
    attendance = [],
    initial_tab = 'ticket',
    offline_identity = { state: 'signed_out' },
}) {
    const [currentRegistration, setCurrentRegistration] = useState(registration);
    const [isPolling, setIsPolling] = useState(
        poll_payment ||
            (registration.status === 'pending_payment' && !registration.awaiting_checkout)
    );
    const [pollingDelayed, setPollingDelayed] = useState(false);
    const [isOnline, setIsOnline] = useState(
        typeof navigator !== 'undefined' ? navigator.onLine : true
    );
    const [offlineSnapshot, setOfflineSnapshot] = useState(null);
    const [identityRevoked, setIdentityRevoked] = useState(false);
    const pollAttempts = useRef(0);
    const MAX_POLL_ATTEMPTS = 24; // 2s initial + ~23 * 5s = ~2 minutes

    useEffect(() => {
        reconcileAttendeeIdentity(offline_identity);
        registerAttendeeServiceWorker();
        purgeExpiredOfflineTickets();
        setOfflineSnapshot(getOfflineTicket(registration.id, offline_identity));

        const handleOnline = () => setIsOnline(true);
        const handleOffline = () => {
            setIsOnline(false);
            setOfflineSnapshot(getOfflineTicket(registration.id, offline_identity));
        };
        const invalidate = () => {
            setOfflineSnapshot(null);
            setIdentityRevoked(true);
        };
        const storage = (event) => {
            if (isIdentityStorageEvent(event)) invalidate();
        };
        window.addEventListener(IDENTITY_EVENT, invalidate);
        window.addEventListener('storage', storage);
        const timer =
            offline_identity.state === 'verified'
                ? setTimeout(
                      invalidate,
                      Math.max(0, offline_identity.proof_expires_at - Date.now())
                  )
                : null;

        window.addEventListener('online', handleOnline);
        window.addEventListener('offline', handleOffline);

        return () => {
            if (timer !== null) clearTimeout(timer);
            window.removeEventListener(IDENTITY_EVENT, invalidate);
            window.removeEventListener('storage', storage);
            window.removeEventListener('online', handleOnline);
            window.removeEventListener('offline', handleOffline);
        };
    }, [
        registration.id,
        offline_identity.state,
        offline_identity.owner_key,
        offline_identity.proof_expires_at,
    ]);

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
    const [selectedTab, setTab] = useState(initial_tab);
    const [agendaIds, setAgendaIds] = useState(currentRegistration.agenda_session_ids ?? []);

    const verified = currentRegistration.email_verified !== false;

    // Contextual Tabs
    const contextualTabs = [];
    if (verified && has_live_poll) contextualTabs.push(['poll', 'Live poll', BarChart2]);
    if (verified && has_forum) contextualTabs.push(['forum', 'Q&A', MessageSquare]);
    if (verified && has_forms) contextualTabs.push(['forms', 'Surveys & feedback', FileText]);
    if (verified && canRequestHelp) contextualTabs.push(['help', 'Get help', HelpCircle]);
    if (verified && materials.length > 0)
        contextualTabs.push(['downloads', `Downloads (${materials.length})`, Download]);

    const desktopTabs = verified ? [['ticket', 'My ticket', Ticket]] : [];
    if (verified && event.sessions?.length > 0) desktopTabs.push(['agenda', 'My day', Calendar]);
    desktopTabs.push(...contextualTabs);
    const tab = desktopTabs.some(([key]) => key === selectedTab) ? selectedTab : 'ticket';
    const isContextualTab = ['poll', 'forum', 'forms', 'help', 'downloads'].includes(tab);
    const activities = contextualTabs.filter(([key]) => ['poll', 'forum', 'forms'].includes(key));

    useEffect(() => {
        if (!verified || !['confirmed', 'checked_in'].includes(registration.status)) return;
        const refresh = () => {
            if (!document.hidden && navigator.onLine)
                router.reload({ only: ['has_live_poll', 'has_forms', 'has_forum'] });
        };
        const interval = setInterval(refresh, 30000);
        document.addEventListener('visibilitychange', refresh);
        return () => {
            clearInterval(interval);
            document.removeEventListener('visibilitychange', refresh);
        };
    }, [verified, registration.status]);

    const restartPolling = () => {
        pollAttempts.current = 0;
        setPollingDelayed(false);
        setIsPolling(true);
    };

    const handleSelectMoreTab = () => {
        if (isContextualTab) return;
        if (contextualTabs.length > 0) {
            setTab(contextualTabs[0][0]);
        }
    };

    if (identityRevoked)
        return (
            <PublicLayout>
                <div className="mx-auto max-w-lg p-8">
                    <h1 className="text-2xl font-medium">Verify your email again</h1>
                    <p className="my-4 text-sm text-ink-secondary">
                        This device’s verified access changed or expired. Reconnect to open your
                        event.
                    </p>
                    <a href="/my" className="min-h-11 text-accent underline">
                        Return to My MiConvener
                    </a>
                </div>
            </PublicLayout>
        );
    if (!isOnline)
        return (
            <PublicLayout>
                <div className="mx-auto max-w-2xl px-5 py-8">
                    <h1 className="text-2xl font-medium">Your saved ticket</h1>
                    <p className="my-4 text-sm text-ink-secondary">
                        Only saved passes are available offline. Reconnect for your programme,
                        activities and current ticket status.
                    </p>
                    <AttendeeTickets
                        offline
                        period="saved"
                        offlineTickets={offlineSnapshot ? [offlineSnapshot] : []}
                        onFilterChange={() => {}}
                        onRecord={() => {}}
                        onOpen={() => {}}
                    />
                </div>
            </PublicLayout>
        );

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
                                You are currently offline. Only explicitly saved ticket passes are
                                available offline. Reconnect for your programme and event
                                activities.
                            </span>
                        </div>
                        {offlineSnapshot?.last_confirmed_at && (
                            <span className="shrink-0 text-ink-secondary text-[11px]">
                                Confirmed{' '}
                                {new Date(offlineSnapshot.last_confirmed_at).toLocaleDateString()}
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
                        {currentRegistration.awaiting_checkout &&
                            currentRegistration.checkout_url && (
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

                        {verified && activities.length > 0 && (
                            <section
                                aria-label="Event activities"
                                className="mt-7 border-l-2 border-accent bg-accent/5 p-5"
                            >
                                <h2 className="text-lg font-medium tracking-tight text-ink">
                                    Be part of the conversation.
                                </h2>
                                <p className="mt-1 text-sm text-ink-secondary">
                                    Answer polls, ask questions and share your feedback.
                                </p>
                                <div className="mt-3 flex flex-wrap gap-2">
                                    {activities.map(([key, label, Icon]) => (
                                        <button
                                            key={key}
                                            type="button"
                                            onClick={() => setTab(key)}
                                            aria-pressed={tab === key}
                                            className={`inline-flex min-h-11 items-center gap-2 border px-3 py-2 text-sm ${tab === key ? 'border-accent bg-accent text-accent-ink' : 'border-border bg-surface text-ink hover:border-accent'}`}
                                        >
                                            <Icon className="h-4 w-4" aria-hidden="true" />
                                            {label}
                                            {key === 'poll' && (
                                                <span className="text-[11px]">· Active</span>
                                            )}
                                        </button>
                                    ))}
                                </div>
                            </section>
                        )}
                        {verified && selectedTab !== tab && (
                            <p role="status" className="mt-5 text-sm text-ink-secondary">
                                This activity is not available right now. Your ticket and other
                                available activities are below.
                            </p>
                        )}

                        {/* Desktop Navigation (>= 768px) */}
                        <nav
                            aria-label="Event workspace navigation"
                            className={`mt-9 mb-7 hidden md:flex flex-wrap justify-center gap-1 ${desktopTabs.length > 0 ? 'border-b border-border' : ''}`}
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
                                    className={`-mb-px flex items-center gap-1.5 border-b px-3.5 py-2.5 text-[13px] font-medium min-h-[44px] cursor-pointer transition-colors ${
                                        tab === key
                                            ? 'border-accent text-accent font-semibold'
                                            : 'border-transparent text-ink-secondary hover:text-ink'
                                    }`}
                                >
                                    {Icon && <Icon className="h-3.5 w-3.5" />}
                                    <span>{label}</span>
                                    {key === 'poll' && (
                                        <span className="h-2 w-2 rounded-full bg-accent animate-pulse" />
                                    )}
                                    {key === 'help' && active_service_requests_count > 0 && (
                                        <span className="rounded-full bg-red-500 px-1.5 py-0.2 text-[10px] text-white">
                                            {active_service_requests_count}
                                        </span>
                                    )}
                                </button>
                            ))}
                        </nav>

                        {/* Mobile "More" contextual sub-nav header (< 768px) */}
                        {isContextualTab && contextualTabs.length > 0 && (
                            <div className="mt-6 mb-6 flex flex-wrap justify-center gap-2 border-b border-border pb-3 md:hidden">
                                {contextualTabs.map(([key, label, Icon]) => (
                                    <button
                                        key={key}
                                        type="button"
                                        onClick={() => setTab(key)}
                                        className={`rounded-lg px-3 py-1.5 text-xs font-medium min-h-[44px] flex items-center gap-1.5 cursor-pointer ${
                                            tab === key
                                                ? 'bg-accent text-white font-semibold'
                                                : 'bg-surface-subtle text-ink-secondary hover:text-ink'
                                        }`}
                                    >
                                        {Icon && <Icon className="h-3.5 w-3.5" />}
                                        <span>{label}</span>
                                        {key === 'help' && active_service_requests_count > 0 && (
                                            <span className="rounded-full bg-red-500 px-1.5 py-0.2 text-[10px] text-white">
                                                {active_service_requests_count}
                                            </span>
                                        )}
                                    </button>
                                ))}
                            </div>
                        )}

                        {/* Panels */}
                        {verified && tab === 'ticket' && (
                            <MyTicketPanel
                                offlineIdentity={offline_identity}
                                event={event}
                                registration={registration}
                                isOnline={isOnline}
                                certificate={certificate}
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
                        {tab === 'poll' && (
                            <PollPanel registration={registration} isOnline={isOnline} />
                        )}
                        {tab === 'forum' && (
                            <ForumPanel registration={registration} isOnline={isOnline} />
                        )}
                        {tab === 'forms' && (
                            <FormsPanel registration={registration} isOnline={isOnline} />
                        )}
                        {tab === 'help' && (
                            <GetHelpPanel
                                event={event}
                                registration={registration}
                                isOnline={isOnline}
                            />
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
                                        onClick={handleSelectMoreTab}
                                        className={`relative flex flex-col items-center justify-center gap-1 text-[11px] min-h-[44px] cursor-pointer ${
                                            isContextualTab
                                                ? 'text-accent font-semibold'
                                                : 'text-ink-secondary hover:text-ink'
                                        }`}
                                    >
                                        <div className="relative">
                                            <MoreHorizontal className="h-4 w-4" />
                                            {(has_live_poll ||
                                                active_service_requests_count > 0) && (
                                                <span className="absolute -top-1 -right-1.5 h-2 w-2 rounded-full bg-accent animate-pulse" />
                                            )}
                                        </div>
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
