import { useCallback, useEffect, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import PublicLayout from '@/Layouts/PublicLayout';
import csrfFetch from '@/lib/csrfFetch';
import VerifyPrompt from './VerifyPrompt';
import NeedsAttentionSection from './components/NeedsAttentionSection';
import LiveNowSection from './components/LiveNowSection';
import OrganiserEventsSection from './components/OrganiserEventsSection';
import HistorySkeleton from './components/HistorySkeleton';
import { AlertCircle, RefreshCw, UserCheck } from 'lucide-react';
import { clearAllOfflineTickets, purgeExpiredOfflineTickets } from '@/lib/offlineTicketStore';
import { registerAttendeeServiceWorker } from '@/lib/registerServiceWorker';

/**
 * Platform attendee portal shell: proves identity across all organisers
 * and provides the action-first entrance to the attendee's event lifecycle.
 */
export default function MyPortal({
    organiser = null,
    verifiedEmail: provenAtLoad = null,
    initialHistory = null,
}) {
    const [verifiedEmail, setVerifiedEmail] = useState(provenAtLoad);
    const [history, setHistory] = useState(initialHistory);
    const [loading, setLoading] = useState(Boolean(provenAtLoad && !initialHistory));
    const [error, setError] = useState(null);
    const [selectedOrganiser, setSelectedOrganiser] = useState(organiser);
    const [signOutError, setSignOutError] = useState(false);

    useEffect(() => {
        registerAttendeeServiceWorker();
        purgeExpiredOfflineTickets();
    }, []);

    const signOutRoute = window.route ? route('attendee.my.verify.forget') : '/my/verify/forget';
    const eventsRoute = window.route ? route('attendee.my.events') : '/my/events';

    const fetchHistory = useCallback(
        async (orgSlug = null) => {
            setLoading(true);
            setError(null);

            const url = new URL(eventsRoute, window.location.origin);
            if (orgSlug) {
                url.searchParams.set('organiser', orgSlug);
            }

            try {
                const response = await fetch(url.toString(), {
                    headers: {
                        Accept: 'application/json',
                    },
                });

                if (response.status === 401) {
                    // Session expired or proof invalid
                    setVerifiedEmail(null);
                    setHistory(null);
                    setLoading(false);
                    return;
                }

                if (!response.ok) {
                    setError("Couldn't load your events. Please check your connection and try again.");
                    setLoading(false);
                    return;
                }

                const data = await response.json();
                setHistory(data);
            } catch {
                setError("Couldn't connect to the server. Please check your connection.");
            } finally {
                setLoading(false);
            }
        },
        [eventsRoute]
    );

    useEffect(() => {
        if (verifiedEmail && !history && !loading) {
            fetchHistory(selectedOrganiser?.slug);
        }
    }, [verifiedEmail, history, loading, selectedOrganiser, fetchHistory]);

    const handleVerified = (maskedEmail) => {
        setVerifiedEmail(maskedEmail);
        fetchHistory(selectedOrganiser?.slug);
    };

    const handleClearFilter = () => {
        setSelectedOrganiser(null);
        // Clean URL query without full reload
        const url = new URL(window.location.href);
        url.searchParams.delete('organiser');
        window.history.replaceState({}, '', url.pathname);
        fetchHistory(null);
    };

    const signOut = async () => {
        try {
            const response = await csrfFetch(signOutRoute, { method: 'POST' });
            if (!response.ok) {
                setSignOutError(true);
                return;
            }
            clearAllOfflineTickets();
            setSignOutError(false);
            setVerifiedEmail(null);
            setHistory(null);
        } catch {
            setSignOutError(true);
        }
    };

    const hasNeedsAttention = (history?.needs_attention?.length ?? 0) > 0;
    const hasLiveNow = (history?.live_now?.length ?? 0) > 0;
    const hasUrgent = hasNeedsAttention || hasLiveNow;
    const isEmpty =
        history &&
        !hasNeedsAttention &&
        !hasLiveNow &&
        (history.organisers?.length ?? 0) === 0;

    return (
        <PublicLayout>
            <Head>
                <link rel="manifest" href="/my/manifest.json" />
                <meta name="theme-color" content="#4f46e5" />
                <meta name="apple-mobile-web-app-capable" content="yes" />
                <meta name="apple-mobile-web-app-status-bar-style" content="default" />
            </Head>

            <div className="mx-auto max-w-2xl px-6 py-16 sm:px-10">
                <div className="space-y-1 mb-8">
                    <h1 className="text-2xl font-normal tracking-tight text-ink">
                        {selectedOrganiser?.name
                            ? `Your events with ${selectedOrganiser.name}`
                            : 'Your MiConvener events'}
                    </h1>
                    <p className="text-[13.5px] text-ink-secondary">
                        Central workspace for your tickets, materials, and agendas across all organisers.
                    </p>
                </div>

                {verifiedEmail ? (
                    <div className="space-y-8">
                        {/* Attendee identity banner */}
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-4">
                            <div className="flex items-center gap-2 text-[13.5px] text-ink-secondary">
                                <UserCheck className="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
                                <span>
                                    Signed in as <strong className="font-medium text-ink">{verifiedEmail}</strong>
                                </span>
                            </div>

                            <button
                                type="button"
                                onClick={signOut}
                                className="text-[13px] text-accent underline hover:opacity-80 cursor-pointer"
                            >
                                Sign out
                            </button>
                        </div>

                        {signOutError && (
                            <div className="rounded-lg border border-red-200 bg-red-50 p-3 text-xs text-red-700 dark:border-red-900/50 dark:bg-red-950/20 dark:text-red-400">
                                Couldn't sign you out. Please try again.
                            </div>
                        )}

                        {/* Loading State */}
                        {loading && <HistorySkeleton />}

                        {/* Error with Retry */}
                        {error && !loading && (
                            <div className="rounded-xl border border-red-200 bg-red-50/50 p-5 dark:border-red-900/50 dark:bg-red-950/20 space-y-3">
                                <div className="flex items-start gap-2.5">
                                    <AlertCircle className="h-4 w-4 text-red-600 dark:text-red-400 mt-0.5 shrink-0" />
                                    <p className="text-xs text-red-700 dark:text-red-300">
                                        {error}
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => fetchHistory(selectedOrganiser?.slug)}
                                    className="inline-flex items-center gap-1.5 rounded-lg border border-red-300 bg-white px-3 py-1.5 text-xs font-medium text-red-800 shadow-xs hover:bg-red-50 dark:border-red-800 dark:bg-red-950 dark:text-red-200 cursor-pointer"
                                >
                                    <RefreshCw className="h-3 w-3" />
                                    Try again
                                </button>
                            </div>
                        )}

                        {/* Honest Empty State */}
                        {isEmpty && !loading && !error && (
                            <div className="rounded-xl border border-border bg-surface-subtle p-8 text-center space-y-3">
                                <p className="text-sm font-medium text-ink">
                                    No MiConvener events were found for this address yet.
                                </p>
                                <p className="text-xs text-ink-secondary max-w-md mx-auto">
                                    Registrations made with this email address will appear here automatically. If you registered with a different address, you can sign in with that one instead.
                                </p>
                                <div className="pt-2">
                                    <button
                                        type="button"
                                        onClick={signOut}
                                        className="text-xs text-accent underline hover:opacity-80 cursor-pointer"
                                    >
                                        Use a different email address
                                    </button>
                                </div>
                            </div>
                        )}

                        {/* Lifecycle Dashboard Hierarchy */}
                        {history && !loading && !isEmpty && (
                            <div className="space-y-8">
                                <NeedsAttentionSection items={history.needs_attention} />
                                <LiveNowSection items={history.live_now} />
                                <OrganiserEventsSection
                                    organisers={history.organisers}
                                    hasUrgentItems={hasUrgent}
                                    selectedOrganiser={selectedOrganiser}
                                    onClearFilter={handleClearFilter}
                                />
                            </div>
                        )}
                    </div>
                ) : (
                    <div className="mt-8">
                        <VerifyPrompt onVerified={handleVerified} />
                    </div>
                )}
            </div>
        </PublicLayout>
    );
}
