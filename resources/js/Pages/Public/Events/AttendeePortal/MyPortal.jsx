import { useCallback, useEffect, useState } from 'react';
import { Head } from '@inertiajs/react';
import PublicLayout from '@/Layouts/PublicLayout';
import csrfFetch from '@/lib/csrfFetch';
import VerifyPrompt from './VerifyPrompt';
import NeedsAttentionSection from './components/NeedsAttentionSection';
import LiveNowSection from './components/LiveNowSection';
import OrganiserEventsSection from './components/OrganiserEventsSection';
import CertificatesSection from './components/CertificatesSection';
import AbstractsSection from './components/AbstractsSection';
import AttendanceSection from './components/AttendanceSection';
import HistorySkeleton from './components/HistorySkeleton';
import { AlertCircle, RefreshCw, UserCheck } from 'lucide-react';
import { clearAllOfflineTickets, purgeExpiredOfflineTickets } from '@/lib/offlineTicketStore';
import { registerAttendeeServiceWorker } from '@/lib/registerServiceWorker';

/**
 * Platform attendee portal shell: proves identity across all organisers
 * and provides the action-first entrance to the attendee's event lifecycle,
 * certificates, abstracts, and verified attendance records.
 */
export default function MyPortal({
    organiser = null,
    verifiedEmail: provenAtLoad = null,
    initialHistory = null,
    initialCertificates = null,
    initialAbstracts = null,
    initialAttendance = null,
}) {
    const [verifiedEmail, setVerifiedEmail] = useState(provenAtLoad);
    const [history, setHistory] = useState(initialHistory);
    const [certificates, setCertificates] = useState(initialCertificates || []);
    const [abstracts, setAbstracts] = useState(initialAbstracts || []);
    const [attendance, setAttendance] = useState(initialAttendance || []);
    const [loading, setLoading] = useState(Boolean(provenAtLoad && !initialHistory));
    const [error, setError] = useState(null);
    const [selectedOrganiser, setSelectedOrganiser] = useState(organiser);
    const [signOutError, setSignOutError] = useState(false);

    const initialTab = typeof window !== 'undefined'
        ? new URLSearchParams(window.location.search).get('tab') || 'events'
        : 'events';
    const [activeTab, setActiveTab] = useState(initialTab);

    useEffect(() => {
        registerAttendeeServiceWorker();
        purgeExpiredOfflineTickets();
    }, []);

    const signOutRoute = window.route ? route('attendee.my.verify.forget') : '/my/verify/forget';
    const eventsRoute = window.route ? route('attendee.my.events') : '/my/events';
    const certificatesRoute = window.route ? route('attendee.my.certificates') : '/my/certificates';
    const abstractsRoute = window.route ? route('attendee.my.abstracts') : '/my/abstracts';
    const attendanceRoute = window.route ? route('attendee.my.attendance') : '/my/attendance';

    const fetchData = useCallback(
        async (orgSlug = null) => {
            setLoading(true);
            setError(null);

            const buildUrl = (baseRoute) => {
                const url = new URL(baseRoute, window.location.origin);
                if (orgSlug) {
                    url.searchParams.set('organiser', orgSlug);
                }
                return url.toString();
            };

            try {
                const [eventsRes, certsRes, abstractsRes, attendanceRes] = await Promise.all([
                    fetch(buildUrl(eventsRoute), { headers: { Accept: 'application/json' } }),
                    fetch(buildUrl(certificatesRoute), { headers: { Accept: 'application/json' } }),
                    fetch(buildUrl(abstractsRoute), { headers: { Accept: 'application/json' } }),
                    fetch(buildUrl(attendanceRoute), { headers: { Accept: 'application/json' } }),
                ]);

                if (
                    eventsRes.status === 401 ||
                    certsRes.status === 401 ||
                    abstractsRes.status === 401 ||
                    attendanceRes.status === 401
                ) {
                    // Session expired or proof invalid
                    setVerifiedEmail(null);
                    setHistory(null);
                    setCertificates([]);
                    setAbstracts([]);
                    setAttendance([]);
                    setActiveTab('events');
                    setLoading(false);
                    return;
                }

                if (!eventsRes.ok) {
                    setError("Couldn't load your events. Please check your connection and try again.");
                    setLoading(false);
                    return;
                }

                const eventsData = await eventsRes.json();
                setHistory(eventsData);

                if (certsRes.ok) {
                    const certsData = await certsRes.json();
                    setCertificates(certsData);
                }

                if (abstractsRes.ok) {
                    const abstractsData = await abstractsRes.json();
                    setAbstracts(abstractsData);
                }

                if (attendanceRes.ok) {
                    const attendanceData = await attendanceRes.json();
                    setAttendance(attendanceData);
                }
            } catch {
                setError("Couldn't connect to the server. Please check your connection.");
            } finally {
                setLoading(false);
            }
        },
        [eventsRoute, certificatesRoute, abstractsRoute, attendanceRoute]
    );

    useEffect(() => {
        if (verifiedEmail && !history && !loading) {
            fetchData(selectedOrganiser?.slug);
        }
    }, [verifiedEmail, history, loading, selectedOrganiser, fetchData]);

    const handleVerified = (maskedEmail) => {
        setVerifiedEmail(maskedEmail);
        fetchData(selectedOrganiser?.slug);
    };

    const handleClearFilter = () => {
        setSelectedOrganiser(null);
        // Clean URL query without full reload
        const url = new URL(window.location.href);
        url.searchParams.delete('organiser');
        window.history.replaceState({}, '', url.pathname);
        fetchData(null);
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
            setCertificates([]);
            setAbstracts([]);
            setAttendance([]);
            setActiveTab('events');
        } catch {
            setSignOutError(true);
        }
    };

    const certifiedHoursCount = (certificates || []).filter(
        (c) => c.cpd_hours && Number(c.cpd_hours) > 0
    ).length;

    // Available tabs based on non-empty record rules (Spec §7.4)
    const availableTabs = [
        { key: 'events', label: 'Events', count: null },
        ...(certificates.length > 0
            ? [{ key: 'certificates', label: 'Certificates', count: certificates.length }]
            : []),
        ...(abstracts.length > 0
            ? [{ key: 'abstracts', label: 'Abstracts', count: abstracts.length }]
            : []),
        ...(attendance.length > 0 || certifiedHoursCount > 0
            ? [{ key: 'attendance', label: 'Attendance', count: attendance.length }]
            : []),
    ];

    // Gracefully fallback if the selected tab is not available
    useEffect(() => {
        if (!availableTabs.some((t) => t.key === activeTab)) {
            setActiveTab('events');
        }
    }, [availableTabs, activeTab]);

    const handleTabChange = (key) => {
        setActiveTab(key);
        const url = new URL(window.location.href);
        if (key === 'events') {
            url.searchParams.delete('tab');
        } else {
            url.searchParams.set('tab', key);
        }
        window.history.replaceState({}, '', url.toString());
    };

    const hasNeedsAttention = (history?.needs_attention?.length ?? 0) > 0;
    const hasLiveNow = (history?.live_now?.length ?? 0) > 0;
    const hasOrganisers = (history?.organisers?.length ?? 0) > 0;
    const hasUrgent = hasNeedsAttention || hasLiveNow;
    const hasAnyRecords = certificates.length > 0 || abstracts.length > 0 || attendance.length > 0;
    const isEmpty =
        history &&
        !hasNeedsAttention &&
        !hasLiveNow &&
        !hasOrganisers &&
        !hasAnyRecords;

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
                        Central workspace for your tickets, materials, agendas, certificates, and attendance across all organisers.
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
                                    onClick={() => fetchData(selectedOrganiser?.slug)}
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
                                    No MiConvener events or records were found for this address yet.
                                </p>
                                <p className="text-xs text-ink-secondary max-w-md mx-auto">
                                    Registrations and credentials made with this email address will appear here automatically. If you registered with a different address, you can sign in with that one instead.
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

                        {/* Conditional Record Tabs Navigation */}
                        {availableTabs.length > 1 && !loading && !isEmpty && (
                            <nav
                                aria-label="Portal sections"
                                role="tablist"
                                className="flex items-center gap-1 border-b border-border overflow-x-auto mb-6 sm:mb-8"
                            >
                                {availableTabs.map((tabItem) => (
                                    <button
                                        key={tabItem.key}
                                        type="button"
                                        role="tab"
                                        aria-selected={activeTab === tabItem.key}
                                        onClick={() => handleTabChange(tabItem.key)}
                                        className={`-mb-px flex items-center gap-1.5 border-b-2 px-3.5 py-2.5 text-xs font-medium cursor-pointer transition-colors whitespace-nowrap min-h-[44px] ${
                                            activeTab === tabItem.key
                                                ? 'border-accent text-accent font-semibold'
                                                : 'border-transparent text-ink-secondary hover:text-ink hover:border-border'
                                        }`}
                                    >
                                        <span>{tabItem.label}</span>
                                        {tabItem.count !== null && (
                                            <span
                                                className={`rounded-full px-1.5 py-0.2 text-[10px] ${
                                                    activeTab === tabItem.key
                                                        ? 'bg-accent/15 text-accent font-semibold'
                                                        : 'bg-surface-subtle text-ink-secondary border border-border'
                                                }`}
                                            >
                                                {tabItem.count}
                                            </span>
                                        )}
                                    </button>
                                ))}
                            </nav>
                        )}

                        {/* Panels */}
                        {!loading && !isEmpty && (
                            <div className="space-y-8">
                                {activeTab === 'events' && history && (
                                    <div className="space-y-8">
                                        <NeedsAttentionSection items={history.needs_attention} />
                                        <LiveNowSection items={history.live_now} />
                                        <OrganiserEventsSection
                                            organisers={history.organisers}
                                            hasUrgentItems={hasUrgent}
                                            selectedOrganiser={selectedOrganiser}
                                            onClearFilter={handleClearFilter}
                                        />
                                        {!hasOrganisers && !hasNeedsAttention && !hasLiveNow && (
                                            <div className="rounded-xl border border-border bg-surface-subtle p-8 text-center space-y-2">
                                                <p className="text-sm font-medium text-ink">
                                                    No registered events found
                                                </p>
                                                <p className="text-xs text-ink-secondary max-w-sm mx-auto">
                                                    You do not have any registered events for this email address. Switch to the tabs above to access your certificates and records.
                                                </p>
                                            </div>
                                        )}
                                    </div>
                                )}

                                {activeTab === 'certificates' && (
                                    <CertificatesSection certificates={certificates} />
                                )}

                                {activeTab === 'abstracts' && (
                                    <AbstractsSection abstracts={abstracts} />
                                )}

                                {activeTab === 'attendance' && (
                                    <AttendanceSection
                                        attendance={attendance}
                                        certificates={certificates}
                                    />
                                )}
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
