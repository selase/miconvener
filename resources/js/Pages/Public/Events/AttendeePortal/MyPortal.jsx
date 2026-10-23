import AttendeeApp from './AttendeeApp';
import { useCallback, useEffect, useState } from 'react';
import { Head } from '@inertiajs/react';
import PublicLayout from '@/Layouts/PublicLayout';
import csrfFetch from '@/lib/csrfFetch';
import { clearAttendeeIdentity } from '@/lib/attendeeIdentityCache';
import VerifyPrompt from './VerifyPrompt';
import PhoneVerify from '@/Pages/Public/Community/PhoneVerify';
import CommunitiesPanel from '@/Pages/Public/Community/CommunitiesPanel';
import RecoveryCredential from '@/Pages/Public/Community/RecoveryCredential';
import NeedsAttentionSection from './components/NeedsAttentionSection';
import LiveNowSection from './components/LiveNowSection';
import OrganiserEventsSection from './components/OrganiserEventsSection';
import CertificatesSection from './components/CertificatesSection';
import AbstractsSection from './components/AbstractsSection';
import AttendanceSection from './components/AttendanceSection';
import ContributionsSection from './components/ContributionsSection';
import HistorySkeleton from './components/HistorySkeleton';
import { AlertCircle, RefreshCw, UserCheck } from 'lucide-react';
import { clearAllOfflineTickets, purgeExpiredOfflineTickets } from '@/lib/offlineTicketStore';
import { registerAttendeeServiceWorker } from '@/lib/registerServiceWorker';

/**
 * Platform attendee portal shell: proves identity across all organisers
 * and provides the action-first entrance to the attendee's event lifecycle,
 * certificates, abstracts, and verified attendance records.
 */
function LegacyMyPortal({
    organiser = null,
    communityPhoneVerified: phoneAtLoad = false,
    communityReturnUrl = null,
    verifiedEmail: provenAtLoad = null,
    initialHistory = null,
    initialCertificates = null,
    initialAbstracts = null,
    initialAttendance = null,
    initialContributions = null,
}) {
    const [phoneVerified, setPhoneVerified] = useState(phoneAtLoad);
    const [phoneEntry, setPhoneEntry] = useState(false);
    const [credential, setCredential] = useState(null);
    const [communityVersion, setCommunityVersion] = useState(0);
    const [verifiedEmail, setVerifiedEmail] = useState(provenAtLoad);
    const [history, setHistory] = useState(initialHistory);
    const [certificates, setCertificates] = useState(initialCertificates || []);
    const [abstracts, setAbstracts] = useState(initialAbstracts || []);
    const [attendance, setAttendance] = useState(initialAttendance || []);
    const [contributions, setContributions] = useState(initialContributions || []);
    const [loading, setLoading] = useState(Boolean(provenAtLoad && !initialHistory));
    const [error, setError] = useState(null);
    const [selectedOrganiser, setSelectedOrganiser] = useState(organiser);
    const [signOutError, setSignOutError] = useState(false);

    const initialTab =
        typeof window !== 'undefined'
            ? new URLSearchParams(window.location.search).get('tab') || 'events'
            : 'events';
    const [activeTab, setActiveTab] = useState(initialTab);
    const joiningEvent =
        typeof window !== 'undefined' &&
        /^\/my\/join\//.test(new URLSearchParams(window.location.search).get('return_to') || '');

    useEffect(() => {
        registerAttendeeServiceWorker();
        purgeExpiredOfflineTickets();
    }, []);

    const signOutRoute = window.route ? route('attendee.my.verify.forget') : '/my/verify/forget';
    const eventsRoute = window.route ? route('attendee.my.events') : '/my/events';
    const certificatesRoute = window.route ? route('attendee.my.certificates') : '/my/certificates';
    const abstractsRoute = window.route ? route('attendee.my.abstracts') : '/my/abstracts';
    const attendanceRoute = window.route ? route('attendee.my.attendance') : '/my/attendance';
    const contributionsRoute = window.route
        ? route('attendee.my.contributions')
        : '/my/contributions';

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
                const [eventsRes, certsRes, abstractsRes, attendanceRes, contribsRes] =
                    await Promise.all([
                        fetch(buildUrl(eventsRoute), { headers: { Accept: 'application/json' } }),
                        fetch(buildUrl(certificatesRoute), {
                            headers: { Accept: 'application/json' },
                        }),
                        fetch(buildUrl(abstractsRoute), {
                            headers: { Accept: 'application/json' },
                        }),
                        fetch(buildUrl(attendanceRoute), {
                            headers: { Accept: 'application/json' },
                        }),
                        fetch(buildUrl(contributionsRoute), {
                            headers: { Accept: 'application/json' },
                        }),
                    ]);

                if (
                    eventsRes.status === 401 ||
                    certsRes.status === 401 ||
                    abstractsRes.status === 401 ||
                    attendanceRes.status === 401 ||
                    contribsRes.status === 401
                ) {
                    // Session expired or proof invalid
                    setVerifiedEmail(null);
                    setHistory(null);
                    setCertificates([]);
                    setAbstracts([]);
                    setAttendance([]);
                    setContributions([]);
                    setActiveTab('events');
                    setLoading(false);
                    return;
                }

                if (!eventsRes.ok) {
                    setError(
                        "Couldn't load your events. Please check your connection and try again."
                    );
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

                if (contribsRes.ok) {
                    const contribsData = await contribsRes.json();
                    setContributions(contribsData);
                }
            } catch {
                setError("Couldn't connect to the server. Please check your connection.");
            } finally {
                setLoading(false);
            }
        },
        [eventsRoute, certificatesRoute, abstractsRoute, attendanceRoute, contributionsRoute]
    );

    useEffect(() => {
        if (verifiedEmail && !history && !loading) {
            fetchData(selectedOrganiser?.slug);
        }
    }, [verifiedEmail, history, loading, selectedOrganiser, fetchData]);

    const handleVerified = (maskedEmail, channel = 'email', phoneOrganiser = null) => {
        clearAttendeeIdentity();
        clearAllOfflineTickets();
        if (communityReturnUrl) {
            window.location.assign(communityReturnUrl);
            return;
        }
        if (channel === 'phone') {
            setPhoneVerified(true);
            setPhoneEntry(false);
            if (phoneOrganiser && !selectedOrganiser) {
                setSelectedOrganiser({ slug: phoneOrganiser, name: phoneOrganiser });
                const url = new URL(window.location.href);
                url.searchParams.set('organiser', phoneOrganiser);
                url.hash = 'communities';
                window.history.replaceState({}, '', url);
            }
            setCommunityVersion((value) => value + 1);
            return;
        }
        // Someone sent here from a workspace they could not open yet is put
        // back where they were going. The target is only ever followed when it
        // is a path on this portal, so a crafted ?return_to cannot bounce a
        // just-verified visitor off to somewhere else.
        const requested = new URLSearchParams(window.location.search).get('return_to');
        if (requested && /^\/my(\/|$)/.test(requested) && !requested.startsWith('//')) {
            window.location.assign(requested);
            return;
        }

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
            clearAttendeeIdentity();
            clearAllOfflineTickets();
            setSignOutError(false);
            setPhoneVerified(false);
            setCredential(null);
            setCommunityVersion((value) => value + 1);
            setVerifiedEmail(null);
            setHistory(null);
            setCertificates([]);
            setAbstracts([]);
            setAttendance([]);
            setContributions([]);
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
        ...(contributions.length > 0
            ? [{ key: 'contributions', label: 'Giving & Tributes', count: contributions.length }]
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

    const handleContributionUpdated = (updated) => {
        setContributions((prev) =>
            prev.map((c) => (c.id === updated.id ? { ...c, ...updated } : c))
        );
    };

    const hasNeedsAttention = (history?.needs_attention?.length ?? 0) > 0;
    const hasLiveNow = (history?.live_now?.length ?? 0) > 0;
    const hasOrganisers = (history?.organisers?.length ?? 0) > 0;
    const hasUrgent = hasNeedsAttention || hasLiveNow;
    const hasAnyRecords =
        certificates.length > 0 ||
        abstracts.length > 0 ||
        attendance.length > 0 ||
        contributions.length > 0;
    const isEmpty =
        history && !hasNeedsAttention && !hasLiveNow && !hasOrganisers && !hasAnyRecords;

    const clearCredential = () => {
        setCredential(null);
        setCommunityVersion((value) => value + 1);
    };
    const expireCommunity = () => {
        setPhoneVerified(false);
        setVerifiedEmail(null);
        setHistory(null);
        setCredential(null);
        clearAttendeeIdentity();
        clearAllOfflineTickets();
    };
    if (credential)
        return (
            <PublicLayout headerActions={<a href="/my/store-orders" className="text-sm text-accent underline">Store orders</a>}>
                <Head title="Save your private recovery key" />
                <RecoveryCredential credential={credential} onClear={clearCredential} />
            </PublicLayout>
        );

    return (
        <PublicLayout headerActions={<a href="/my/store-orders" className="text-sm text-accent underline">Store orders</a>}>
            <Head title="My MiConvener">
                <link rel="manifest" href="/my/manifest.json" />
                <meta name="theme-color" content="#4f46e5" />
                <meta name="apple-mobile-web-app-capable" content="yes" />
                <meta name="apple-mobile-web-app-status-bar-style" content="default" />
            </Head>

            <div className="mx-auto max-w-2xl px-6 py-16 sm:px-10">
                {(verifiedEmail || phoneVerified) && (
                    <a
                        href={route('attendee.my.giving.index')}
                        className="mb-6 inline-block min-h-11 py-2 text-sm text-accent underline"
                    >
                        My contributions, receipts and statements →
                    </a>
                )}
                <div className="space-y-1 mb-8">
                    <h1 className="text-2xl font-normal tracking-tight text-ink">
                        {joiningEvent && !verifiedEmail
                            ? 'Verify your registration email'
                            : selectedOrganiser?.name
                              ? `Your events with ${selectedOrganiser.name}`
                              : phoneVerified && !verifiedEmail
                                ? 'Your MiConvener Communities'
                                : 'Your MiConvener events'}
                    </h1>
                    <p className="text-[13.5px] text-ink-secondary">
                        {joiningEvent && !verifiedEmail
                            ? 'Use the email you registered with to open the programme, polls, Q&A and surveys. Phone verification does not unlock event tickets.'
                            : phoneVerified && !verifiedEmail
                              ? 'Follow organisations, track requests and restore independently approved memberships. Verify email separately for tickets and event history.'
                              : 'Central workspace for your tickets, materials, agendas, certificates, and attendance across all organisers.'}
                    </p>
                </div>

                {new URLSearchParams(window.location.search).get('event_access') ===
                    'not_found' && (
                    <p
                        role="status"
                        className="mb-6 border-l-2 border-accent bg-accent/5 p-4 text-sm text-ink-secondary"
                    >
                        No available registration for this event matches your verified email. If you
                        registered with another address, sign out and verify that address, or return
                        to the event page to register.
                    </p>
                )}
                {communityReturnUrl && (verifiedEmail || phoneVerified) && (
                    <a
                        href={communityReturnUrl}
                        className="mb-6 inline-block rounded-md bg-accent px-4 py-2 text-sm font-medium text-white"
                    >
                        Continue to organisation
                    </a>
                )}
                {verifiedEmail && !phoneVerified && (
                    <section className="mb-6 space-y-4 rounded-lg border border-border bg-surface p-4">
                        {phoneEntry ? (
                            <PhoneVerify
                                organiserSlug={selectedOrganiser?.slug}
                                onVerified={handleVerified}
                                onUseEmail={() => setPhoneEntry(false)}
                            />
                        ) : (
                            <button
                                type="button"
                                onClick={() => setPhoneEntry(true)}
                                className="text-sm font-medium text-accent underline"
                            >
                                Verify a phone number
                            </button>
                        )}
                        <p className="text-xs text-ink-secondary">
                            Keep your email sign-in and add independent phone verification for
                            membership recovery. This does not link other people or event records.
                        </p>
                    </section>
                )}
                {verifiedEmail ? (
                    <div className="space-y-8">
                        {/* Attendee identity banner */}
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-4">
                            <div className="flex items-center gap-2 text-[13.5px] text-ink-secondary">
                                <UserCheck className="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
                                <span>
                                    Signed in as{' '}
                                    <strong className="font-medium text-ink">
                                        {verifiedEmail}
                                    </strong>
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
                                    Registrations and credentials made with this email address will
                                    appear here automatically. If you registered with a different
                                    address, you can sign in with that one instead.
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
                                                    You do not have any registered events for this
                                                    email address. Switch to the tabs above to
                                                    access your certificates and records.
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

                                {activeTab === 'contributions' && (
                                    <ContributionsSection
                                        contributions={contributions}
                                        onContributionUpdated={handleContributionUpdated}
                                    />
                                )}
                            </div>
                        )}
                    </div>
                ) : phoneVerified ? (
                    <div className="space-y-4">
                        <div className="flex items-center justify-between gap-3">
                            <p className="text-sm text-ink">
                                Your phone is verified for Community actions.
                            </p>
                            <button
                                type="button"
                                onClick={signOut}
                                className="text-sm text-accent underline"
                            >
                                Sign out
                            </button>
                        </div>
                        <details
                            open={joiningEvent}
                            className="rounded-lg border border-border p-4"
                        >
                            <summary className="cursor-pointer text-sm text-ink">
                                Verify email for tickets and event history
                            </summary>
                            <div className="mt-4">
                                <VerifyPrompt
                                    onVerified={handleVerified}
                                    organiserSlug={selectedOrganiser?.slug}
                                    allowPhone={false}
                                />
                            </div>
                        </details>
                    </div>
                ) : (
                    <div className="mt-8">
                        <VerifyPrompt
                            onVerified={handleVerified}
                            organiserSlug={selectedOrganiser?.slug}
                            allowPhone={!joiningEvent}
                        />
                    </div>
                )}
                {(verifiedEmail || phoneVerified) && (
                    <CommunitiesPanel
                        key={communityVersion}
                        organiser={selectedOrganiser}
                        initiallyOpen={phoneVerified || window.location.hash === '#communities'}
                        onCredential={setCredential}
                        onExpired={expireCommunity}
                        onPhoneRemoved={() => {
                            setPhoneVerified(false);
                            setCommunityVersion((value) => value + 1);
                        }}
                    />
                )}
            </div>
        </PublicLayout>
    );
}

export default function MyPortal(props) {
    return props.app_shell_enabled ? <AttendeeApp {...props} /> : <LegacyMyPortal {...props} />;
}
