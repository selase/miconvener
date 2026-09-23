import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import PublicLayout from '@/Layouts/PublicLayout';
import { CheckCircle2, Clock, ListOrdered, Loader2, RefreshCw, XCircle } from 'lucide-react';
import MyTicketPanel from './panels/MyTicketPanel';
import MyDayPanel from './panels/MyDayPanel';
import GetHelpPanel from './panels/GetHelpPanel';
import DownloadsPanel from './panels/DownloadsPanel';

const STATUS_NOTICE = {
    pending_payment: {
        icon: Clock,
        color: 'text-warning-fg',
        // Two different people land here. Someone who has paid is waiting on
        // the gateway; someone just approved has not paid at all, and telling
        // them their payment is being confirmed leaves them waiting forever.
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
        description: (event, registration) =>
            registration.approval_note ||
            `The organizers of ${event.name} weren't able to confirm this registration.`,
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
 * confirmation email or Paystack return. Each tab is its own file under panels/.
 */
export default function Portal({ event, registration, materials = [], canRequestHelp = false, poll_payment = false }) {
    const [currentRegistration, setCurrentRegistration] = useState(registration);
    const [isPolling, setIsPolling] = useState(
        poll_payment || (registration.status === 'pending_payment' && !registration.awaiting_checkout)
    );
    const [pollingDelayed, setPollingDelayed] = useState(false);
    const pollAttempts = useRef(0);
    const MAX_POLL_ATTEMPTS = 24; // 2s initial + ~23 * 5s = ~2 minutes

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
    const tabs = verified ? [['ticket', 'My ticket']] : [];
    if (verified && event.sessions.length > 0) tabs.push(['agenda', 'My day']);
    // Only offer what can actually be acted on: help while the event is
    // running, downloads once something has been released.
    if (verified && canRequestHelp) tabs.push(['help', 'Get help']);
    if (verified && materials.length > 0) tabs.push(['downloads', 'Downloads']);

    const restartPolling = () => {
        pollAttempts.current = 0;
        setPollingDelayed(false);
        setIsPolling(true);
    };

    return (
        <PublicLayout>
            <div className="mx-auto max-w-2xl px-6 py-16 sm:px-10">
                {notice ? (
                    <div className="text-center">
                        <notice.icon
                            className={`mx-auto h-10 w-10 ${notice.color}`}
                            strokeWidth={1.5}
                        />
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
                                className="mt-6 inline-flex items-center gap-2 rounded-lg border border-border bg-surface px-5 py-2.5 text-[13.5px] font-medium text-ink hover:border-accent"
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

                        <nav
                            className={`mt-9 mb-7 flex justify-center gap-1 ${tabs.length > 0 ? 'border-b border-border' : ''}`}
                        >
                            {tabs.map(([key, label]) => (
                                <button
                                    key={key}
                                    onClick={() => setTab(key)}
                                    className={`-mb-px border-b px-3.5 py-2.5 text-[13px] ${tab === key ? 'border-accent text-accent' : 'border-transparent text-ink-secondary hover:text-ink'}`}
                                >
                                    {label}
                                </button>
                            ))}
                        </nav>

                        {verified && tab === 'ticket' && (
                            <MyTicketPanel event={event} registration={registration} />
                        )}
                        {tab === 'agenda' && (
                            <MyDayPanel
                                event={event}
                                registration={registration}
                                agendaIds={agendaIds}
                                setAgendaIds={setAgendaIds}
                            />
                        )}
                        {tab === 'help' && <GetHelpPanel event={event} registration={registration} />}
                        {tab === 'downloads' && <DownloadsPanel materials={materials} />}
                    </>
                )}
            </div>
        </PublicLayout>
    );
}
