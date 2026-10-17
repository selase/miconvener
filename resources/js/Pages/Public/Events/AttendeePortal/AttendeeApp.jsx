import AttendeeMoney from './components/AttendeeMoney';
import AttendeeMe from './components/AttendeeMe';
import PhoneVerify from '@/Pages/Public/Community/PhoneVerify';
import {
    listOfflineTickets,
    removeOfflineTicket,
    getOfflineTicket,
    saveOfflineTicket,
} from '@/lib/offlineTicketStore';
import {
    reconcileAttendeeIdentity,
    clearAttendeeIdentity,
    IDENTITY_EVENT,
    isIdentityStorageEvent,
} from '@/lib/attendeeIdentityCache';
import { registerAttendeeServiceWorker } from '@/lib/registerServiceWorker';
import Modal from '@/Components/Console/Modal';
import MyTicketPanel from './panels/MyTicketPanel';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import csrfFetch from '@/lib/csrfFetch';
import AttendeeAppShell, { sectionFromLocation } from './components/AttendeeAppShell';
import AttendeeHome from './components/AttendeeHome';
import AttendeeTickets from './components/AttendeeTickets';
import VerifyPrompt from './VerifyPrompt';
import CommunitiesPanel from '@/Pages/Public/Community/CommunitiesPanel';
import InboxList from '@/Pages/Public/Community/InboxList';
import RecoveryCredential from '@/Pages/Public/Community/RecoveryCredential';
import CertificatesSection from './components/CertificatesSection';
import AbstractsSection from './components/AbstractsSection';
import AttendanceSection from './components/AttendanceSection';
import ContributionsSection from './components/ContributionsSection';
import HistorySkeleton from './components/HistorySkeleton';

const RECORDS = {
    certificates: CertificatesSection,
    abstracts: AbstractsSection,
    attendance: AttendanceSection,
    contributions: ContributionsSection,
};

export default function AttendeeApp({
    community_inbox_enabled = false,
    verifiedEmail,
    communityPhoneVerified,
    organiser,
    communityReturnUrl,
    offline_identity,
}) {
    const [inbox, setInbox] = useState(null);
    const [inboxEnabled, setInboxEnabled] = useState(community_inbox_enabled);
    const [inboxOwner, setInboxOwner] = useState(null);
    const [inboxStatus, setInboxStatus] = useState('idle');
    const [inboxLocation, setInboxLocation] = useState(() => {
        const params = new URLSearchParams(window.location.search);
        return params.get('inbox')
            ? {
                  slug: params.get('inbox'),
                  item: params.get('item'),
                  context: params.get('context') || 'self',
              }
            : null;
    });
    const [online, setOnline] = useState(
        navigator.onLine && offline_identity?.state !== 'unknown_offline'
    );
    const [savedPasses, setSavedPasses] = useState([]);
    const [ticketDetail, setTicketDetail] = useState(null);
    const [cacheContext, setCacheContext] = useState(offline_identity);
    const [section, setSection] = useState(() => sectionFromLocation(window.location));
    const [period, setPeriod] = useState('upcoming');
    const [page, setPage] = useState(1);
    const [history, setHistory] = useState(null);
    const [record, setRecord] = useState(() =>
        new URLSearchParams(window.location.search).get('tab')
    );
    const [records, setRecords] = useState(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [revision, setRevision] = useState(0);
    const [credential, setCredential] = useState(null);
    const [email, setEmail] = useState(verifiedEmail);
    const [phone, setPhone] = useState(communityPhoneVerified);
    const [money, setMoney] = useState(null);
    const [moneyStatus, setMoneyStatus] = useState('idle');
    const [preferencesStatus, setPreferencesStatus] = useState('idle');
    const [moneyPage, setMoneyPage] = useState(1);
    const [contributionsPage, setContributionsPage] = useState(1);
    const [identity, setIdentity] = useState(null);
    const [preferences, setPreferences] = useState(null);
    const [preferencesPage, setPreferencesPage] = useState(1);
    const [verifyChannel, setVerifyChannel] = useState(null);
    const [busy, setBusy] = useState(false);
    const generation = useRef(0);
    const previousOwner = useRef(offline_identity?.owner_key);
    const inboxOwnerRef = useRef(null);
    const inboxProof =
        offline_identity?.owner_key ||
        cacheContext?.owner_key ||
        `${verifiedEmail || ''}:${Boolean(communityPhoneVerified)}`;
    const visibleInbox = inboxOwner === inboxProof ? inbox : null;
    const expire = useCallback(() => {
        generation.current++;
        clearAttendeeIdentity();

        setIdentity(null);
        setPreferences(null);
        setEmail(null);
        setPhone(false);
        setHistory(null);
        setMoney(null);
        setInbox(null);
        setInboxStatus('idle');
        setInboxLocation(null);
        setTicketDetail(null);
        setSavedPasses([]);
        setCredential(null);
        setRecords(null);
    }, []);
    useEffect(() => {
        const back = () => {
            setSection(sectionFromLocation(window.location));
            const params = new URLSearchParams(window.location.search);
            setRecord(params.get('tab'));
            setInboxLocation(
                params.get('inbox')
                    ? {
                          slug: params.get('inbox'),
                          item: params.get('item'),
                          context: params.get('context') || 'self',
                      }
                    : null
            );
        };
        window.addEventListener('popstate', back);
        return () => window.removeEventListener('popstate', back);
    }, []);
    useEffect(() => {
        if (previousOwner.current !== offline_identity?.owner_key) {
            generation.current++;
            setIdentity(null);
            setPreferences(null);
            setMoneyStatus('idle');
            setPreferencesStatus('idle');
            setHistory(null);
            setRecords(null);
            setMoney(null);
            setInbox(null);
            setInboxStatus('idle');
            setInboxLocation(null);
            setTicketDetail(null);
            setCredential(null);
        }
        previousOwner.current = offline_identity?.owner_key;
        setInboxEnabled(community_inbox_enabled);
        setEmail(verifiedEmail);
        setPhone(communityPhoneVerified);
        setCacheContext(offline_identity);
        const context = reconcileAttendeeIdentity(offline_identity || { state: 'signed_out' });
        if (offline_identity?.state === 'unknown_offline')
            setCacheContext(context || offline_identity);
        setSavedPasses(
            navigator.onLine && offline_identity?.state === 'unknown_offline'
                ? []
                : listOfflineTickets(context || { state: 'signed_out' })
        );
        if (!navigator.onLine) {
            setSection('tickets');
            setPeriod('saved');
        }
        registerAttendeeServiceWorker();
        const invalidate = () => {
            generation.current++;
            setIdentity(null);
            setPreferences(null);
            setMoneyStatus('idle');
            setPreferencesStatus('idle');
            setHistory(null);
            setRecords(null);
            setMoney(null);
            setInbox(null);
            setInboxStatus('idle');
            setInboxLocation(null);
            setTicketDetail(null);
            setSavedPasses([]);
            setCredential(null);
            setEmail(null);
            setPhone(false);
            setCacheContext({ state: 'signed_out' });
        };
        const storage = (event) => {
            if (isIdentityStorageEvent(event)) invalidate();
        };
        const probeController = new AbortController();
        const onOnline = async () => {
            const current = generation.current;
            try {
                const response = await csrfFetch('/my/identity', {
                    signal: probeController.signal,
                });
                if (probeController.signal.aborted || current !== generation.current) return;
                if (response.status === 401) {
                    clearAttendeeIdentity();
                    setOnline(true);
                    return;
                }
                if (!response.ok) throw new Error('Identity check unavailable');
                const proof = await response.json();
                if (probeController.signal.aborted || current !== generation.current) return;
                const authoritative = proof.offline_identity;
                if (authoritative?.state !== 'verified')
                    throw new Error('Identity check unavailable');
                generation.current++;
                setIdentity(null);
                setPreferences(null);
                setHistory(null);
                setRecords(null);
                setMoney(null);
                setInbox(null);
                setInboxStatus('idle');
                setInboxLocation(null);
                setTicketDetail(null);
                setCredential(null);
                reconcileAttendeeIdentity(authoritative);
                setCacheContext(authoritative);
                setEmail(proof.email?.masked ?? null);
                setPhone(Boolean(proof.phone));
                setInboxEnabled(Boolean(proof.community_inbox_enabled));
                setOnline(true);
                setSavedPasses(listOfflineTickets(authoritative));
            } catch {
                if (!probeController.signal.aborted) {
                    setOnline(false);
                    setSection('tickets');
                    setPeriod('saved');
                    setSavedPasses(listOfflineTickets({ state: 'unknown_offline' }));
                }
            }
        };
        if (navigator.onLine && offline_identity?.state === 'unknown_offline') onOnline();
        const onOffline = () => {
            setOnline(false);
            setSection('tickets');
            setPeriod('saved');
            setRecord(null);
            setTicketDetail(null);
            setSavedPasses(listOfflineTickets({ state: 'unknown_offline' }));
        };
        window.addEventListener('storage', storage);
        window.addEventListener(IDENTITY_EVENT, invalidate);
        window.addEventListener('online', onOnline);
        window.addEventListener('offline', onOffline);
        return () => {
            probeController.abort();
            window.removeEventListener('storage', storage);
            window.removeEventListener(IDENTITY_EVENT, invalidate);
            window.removeEventListener('online', onOnline);
            window.removeEventListener('offline', onOffline);
        };
    }, [
        offline_identity?.state,
        offline_identity?.owner_key,
        offline_identity?.proof_expires_at,
        community_inbox_enabled,
        verifiedEmail,
        communityPhoneVerified,
    ]);
    useEffect(() => {
        if (cacheContext?.state !== 'verified') return undefined;
        const timer = window.setTimeout(
            () => clearAttendeeIdentity(),
            Math.max(0, cacheContext.proof_expires_at - Date.now())
        );
        return () => window.clearTimeout(timer);
    }, [cacheContext?.state, cacheContext?.owner_key, cacheContext?.proof_expires_at]);
    const openTicket = async (entry) => {
        const current = generation.current;
        setError('');
        try {
            const response = await csrfFetch(`/my/app/tickets/${entry.registration_id}`);
            if (response.status === 401) {
                expire();
                throw new Error('Verify your email again to open this ticket.');
            }
            if (!response.ok) {
                removeOfflineTicket(entry.registration_id);
                throw new Error(
                    'This pass is not available. Open the event workspace to check its current status.'
                );
            }
            const data = await response.json();
            if (current !== generation.current) return;
            setTicketDetail(data);
        } catch (failure) {
            if (current === generation.current) setError(failure.message);
        }
    };
    useEffect(() => {
        if (!online || !email || !cacheContext || cacheContext.state !== 'verified')
            return undefined;
        let disposed = false;
        const current = generation.current;
        Promise.all(
            listOfflineTickets(cacheContext).map(async (pass) => {
                try {
                    const response = await csrfFetch(`/my/app/tickets/${pass.registration_id}`);
                    if (disposed || current !== generation.current) return;
                    if ([401, 403, 404].includes(response.status)) {
                        removeOfflineTicket(pass.registration_id);
                        return;
                    }
                    if (response.ok) {
                        const data = await response.json();
                        if (!disposed && current === generation.current)
                            saveOfflineTicket(data.registration, data.event, cacheContext);
                    }
                } catch {
                    /* A disconnected refresh cannot confirm current revocation. */
                }
            })
        ).then(() => {
            if (!disposed && current === generation.current)
                setSavedPasses(listOfflineTickets(cacheContext));
        });
        return () => {
            disposed = true;
        };
    }, [online, email, cacheContext]);
    const navigate = (key) => {
        setSection(key);
        setRecord(null);
        setError('');
        const url = new URL(window.location.href);
        url.searchParams.set('section', key);
        url.searchParams.delete('tab');
        for (const key of ['inbox', 'item', 'context']) url.searchParams.delete(key);
        setInboxLocation(null);
        url.hash = '';
        window.history.pushState({}, '', url);
    };
    const openInbox = (organisation, item = null) => {
        setSection('communities');
        setRecord(null);
        const location = {
            slug: organisation.slug,
            item: item?.id || null,
            context: item?.context || 'self',
        };
        setInboxLocation(location);
        const url = new URL(window.location.href);
        url.searchParams.set('section', 'communities');
        url.searchParams.set('inbox', organisation.slug);
        url.searchParams.delete('tab');
        for (const key of ['item', 'context']) url.searchParams.delete(key);
        if (item) {
            url.searchParams.set('item', item.id);
            url.searchParams.set('context', item.context);
        }
        window.history.pushState({}, '', url);
    };
    const closeInbox = () => {
        setInboxLocation(null);
        const url = new URL(window.location.href);
        for (const key of ['inbox', 'item', 'context']) url.searchParams.delete(key);
        window.history.pushState({}, '', url);
        window.requestAnimationFrame(() => document.getElementById('communities')?.focus());
    };
    useEffect(() => {
        if (!inboxEnabled || !online || (!email && !phone)) return undefined;
        const abort = new AbortController();
        const current = generation.current;
        let disposed = false;
        if (inboxOwnerRef.current !== inboxProof) {
            setInbox(null);
            setInboxOwner(inboxProof);
            inboxOwnerRef.current = inboxProof;
        }
        setInboxStatus('loading');
        csrfFetch('/my/inbox', { cache: 'no-store', signal: abort.signal })
            .then(async (response) => {
                if (response.status === 401) {
                    if (current === generation.current && !disposed) expire();
                    return;
                }
                if (!response.ok) throw new Error('Messages could not be loaded.');
                const data = await response.json();
                if (!disposed && current === generation.current) {
                    setInbox(data.organisations || []);
                    setInboxStatus('success');
                }
            })
            .catch((failure) => {
                if (!disposed && current === generation.current && failure.name !== 'AbortError') {
                    setInbox(null);
                    setInboxStatus('error');
                }
            });
        return () => {
            disposed = true;
            abort.abort();
        };
    }, [inboxEnabled, online, email, phone, inboxProof, revision, expire]);
    useEffect(() => {
        if (
            !online ||
            !email ||
            !['home', 'tickets'].includes(section) ||
            (section === 'tickets' && period === 'saved')
        )
            return undefined;
        const controller = new AbortController();
        const current = generation.current;
        setLoading(true);
        setError('');
        setHistory(null);
        const params = new URLSearchParams({
            period: section === 'home' ? 'upcoming' : period,
            page: String(section === 'home' ? 1 : page),
        });
        if (organiser?.slug) params.set('organiser', organiser.slug);
        csrfFetch(`/my/app/events?${params}`, { signal: controller.signal })
            .then(async (response) => {
                if (current !== generation.current) return;
                if (response.status === 401 || response.status === 403) {
                    expire();
                    throw new Error('Please verify your email again.');
                }
                if (!response.ok) throw new Error('Could not load your tickets. Try again.');
                const data = await response.json();
                if (current === generation.current) setHistory(data);
            })
            .catch((failure) => {
                if (failure.name !== 'AbortError' && current === generation.current)
                    setError(failure.message);
            })
            .finally(() => {
                if (current === generation.current) setLoading(false);
            });
        return () => controller.abort();
    }, [email, section, period, page, organiser?.slug, revision, expire]);
    useEffect(() => {
        if (!online || !email || !RECORDS[record] || record === 'contributions') return undefined;
        const controller = new AbortController();
        const current = generation.current;
        setLoading(true);
        setRecords(null);
        setError('');
        const params = new URLSearchParams();
        if (organiser?.slug) params.set('organiser', organiser.slug);
        csrfFetch(`/my/${record}?${params}`, { signal: controller.signal })
            .then(async (response) => {
                if (response.status === 401 || response.status === 403) {
                    expire();
                    throw new Error('Please verify your email again.');
                }
                if (!response.ok) throw new Error('Could not load your records. Try again.');
                const data = await response.json();
                if (current === generation.current) setRecords(data[record] ?? data);
            })
            .catch((failure) => {
                if (failure.name !== 'AbortError' && current === generation.current)
                    setError(failure.message);
            })
            .finally(() => {
                if (current === generation.current) setLoading(false);
            });
        return () => controller.abort();
    }, [email, record, organiser?.slug, revision, expire]);
    const openRecord = (key) => {
        setRecord(key);
        const url = new URL(window.location.href);
        url.searchParams.set('section', section);
        url.searchParams.set('tab', key);
        window.history.pushState({}, '', url);
    };
    const verified = () => {
        const requested = new URLSearchParams(window.location.search).get('return_to');
        if (communityReturnUrl) window.location.assign(communityReturnUrl);
        else if (requested && /^\/my(\/|$)/.test(requested) && !requested.startsWith('//'))
            window.location.assign(requested);
        else router.reload();
    };
    useEffect(() => {
        if (!online || section !== 'me' || (!email && !phone)) return undefined;
        setPreferencesStatus('loading');
        const controller = new AbortController();
        const current = generation.current;
        Promise.all(
            ['/my/identity', `/my/preferences?page=${preferencesPage}`].map(async (url) => {
                const response = await csrfFetch(url, { signal: controller.signal });
                if (response.status === 401) {
                    expire();
                    throw new Error('Please verify your contact again.');
                }
                if (!response.ok) throw new Error('Could not load your preferences. Try again.');
                return response.json();
            })
        )
            .then(([proof, data]) => {
                if (current === generation.current) {
                    setIdentity(proof);
                    setPreferences(data.preferences);
                    setPreferencesStatus('success');
                }
            })
            .catch((failure) => {
                if (failure.name !== 'AbortError' && current === generation.current) {
                    setError(failure.message);
                    setPreferencesStatus('error');
                }
            });
        return () => controller.abort();
    }, [online, section, email, phone, revision, preferencesPage, expire]);
    useEffect(() => {
        if (!online || !['home', 'money'].includes(section) || (!email && !phone)) return undefined;
        setMoneyStatus('loading');
        const controller = new AbortController();
        const current = generation.current;
        const params = new URLSearchParams({
            page: String(moneyPage),
            contributions_page: String(contributionsPage),
        });
        if (organiser?.slug) params.set('tenant_slug', organiser.slug);
        csrfFetch(`/my/money?${params}`, { signal: controller.signal })
            .then(async (response) => {
                if (response.status === 401) {
                    expire();
                    throw new Error('Verify your contact again.');
                }
                if (!response.ok) throw new Error('Could not load money records. Try again.');
                const data = await response.json();
                if (current === generation.current) {
                    setMoney(data);
                    setMoneyStatus('success');
                }
            })
            .catch((failure) => {
                if (failure.name !== 'AbortError' && current === generation.current) {
                    setError(failure.message);
                    setMoneyStatus('error');
                }
            });
        return () => controller.abort();
    }, [
        online,
        section,
        email,
        phone,
        moneyPage,
        contributionsPage,
        organiser?.slug,
        revision,
        expire,
    ]);
    const changePreference = async (slug, channel, enabled) => {
        setBusy(true);
        setError('');
        try {
            const response = await csrfFetch(`/my/preferences/${encodeURIComponent(slug)}`, {
                method: 'PATCH',
                body: JSON.stringify({
                    channel,
                    marketing_enabled: enabled,
                    resume_marketing: enabled,
                }),
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || 'Could not update preferences.');
            setRevision((value) => value + 1);
        } catch (failure) {
            setError(failure.message);
        } finally {
            setBusy(false);
        }
    };
    const removeIdentity = async (channel) => {
        setBusy(true);
        setError('');
        try {
            const response = await csrfFetch(
                channel === 'email' ? '/my/identity/email' : '/my/verify/phone/forget',
                { method: channel === 'email' ? 'DELETE' : 'POST' }
            );
            if (!response.ok) throw new Error('Could not remove this contact. Try again.');
            clearAttendeeIdentity();
            expire();
            router.reload();
        } catch (failure) {
            setError(failure.message);
        } finally {
            setBusy(false);
        }
    };
    const signOut = async () => {
        setError('');
        try {
            const response = await csrfFetch('/my/verify/forget', { method: 'POST' });
            if (!response.ok) throw new Error('Sign-out failed. Please try again.');
            clearAttendeeIdentity();
            expire();
            router.reload();
        } catch (failure) {
            setError(failure.message);
        }
    };
    const identityGeneration = generation.current;
    const Record = RECORDS[record];
    const needsEmail =
        online &&
        ((section === 'tickets' && !email) ||
            (['home', 'money'].includes(section) && !email && !phone));
    return (
        <AttendeeAppShell section={section} onSectionChange={navigate} offline={!online}>
            <Head title="My MiConvener">
                <link rel="manifest" href="/my/manifest.json" />
                <meta name="theme-color" content="#007367" />
            </Head>
            {organiser && (
                <p className="mb-5 flex flex-wrap items-center gap-3 text-sm text-ink-secondary">
                    Showing {organiser.name}
                    <a href="/my" className="min-h-11 content-center text-accent underline">
                        All organisations
                    </a>
                </p>
            )}
            {error && (
                <div role="alert" className="mb-5 border border-danger/30 bg-danger/5 p-4 text-sm">
                    {error}
                    <button
                        type="button"
                        onClick={() => setRevision((value) => value + 1)}
                        className="ml-3 min-h-11 underline"
                    >
                        Retry
                    </button>
                </div>
            )}
            {!online ? (
                section === 'tickets' ? (
                    <AttendeeTickets
                        history={null}
                        period="saved"
                        offline
                        offlineTickets={savedPasses}
                        onFilterChange={() => {}}
                        onRecord={() => {}}
                        onOpen={() => {}}
                    />
                ) : (
                    <div
                        role="status"
                        className="border border-border p-6 text-sm text-ink-secondary"
                    >
                        Reconnect to open {section}. Only saved ticket passes are available offline.
                        <button
                            type="button"
                            onClick={() => navigate('tickets')}
                            className="mt-3 block min-h-11 text-accent underline"
                        >
                            Open saved tickets
                        </button>
                    </div>
                )
            ) : needsEmail ? (
                <section className="mx-auto max-w-lg">
                    <h1 className="mb-3 text-2xl font-medium">Keep your experiences together.</h1>
                    <p className="mb-6 text-sm text-ink-secondary">
                        Verify your email to see event tickets, certificates and giving history.
                        {phone
                            ? ' Your phone remains independently verified for Community access.'
                            : ''}
                    </p>
                    <VerifyPrompt onVerified={verified} organiserSlug={organiser?.slug} />
                </section>
            ) : (
                <>
                    {loading && section !== 'home' && <HistorySkeleton />}
                    {!loading && Record && section === 'tickets' ? (
                        <>
                            <button
                                type="button"
                                className="mb-5 min-h-11 text-sm underline"
                                onClick={() => setRecord(null)}
                            >
                                Back to {section}
                            </button>
                            <Record
                                {...{ [record]: records || [] }}
                                onContributionUpdated={() => setRevision((value) => value + 1)}
                            />
                        </>
                    ) : section === 'home' ? (
                        <AttendeeHome
                            history={history}
                            eventLoading={loading}
                            inbox={
                                organiser?.slug
                                    ? visibleInbox?.filter((row) => row.slug === organiser.slug)
                                    : visibleInbox
                            }
                            inboxStatus={inboxStatus}
                            onOpenInbox={openInbox}
                            onRetryInbox={() => setRevision((value) => value + 1)}
                            duesSummary={moneyStatus === 'success' ? money : null}
                            eventAccess={Boolean(email)}
                            onVerifyEmail={() => {
                                navigate('me');
                                setVerifyChannel('email');
                            }}
                            onSectionChange={navigate}
                            onOpen={openTicket}
                        />
                    ) : !loading && section === 'tickets' ? (
                        <AttendeeTickets
                            history={history}
                            offlineTickets={savedPasses}
                            period={period}
                            onFilterChange={(key, next) => {
                                setPeriod(key);
                                setPage(next);
                                if (key === 'saved')
                                    setSavedPasses(listOfflineTickets(cacheContext));
                            }}
                            onRecord={openRecord}
                            onOpen={openTicket}
                        />
                    ) : null}
                    {section === 'communities' &&
                        (email || phone ? (
                            <CommunitiesPanel
                                organiser={organiser}
                                inboxOverview={visibleInbox}
                                onOpenInbox={openInbox}
                                initiallyOpen
                                onCredential={(value) => {
                                    if (
                                        identityGeneration === generation.current &&
                                        (email || phone)
                                    )
                                        setCredential(value);
                                }}
                                onExpired={() => {
                                    if (identityGeneration === generation.current) expire();
                                }}
                                onPhoneRemoved={() => {
                                    router.reload();
                                }}
                            />
                        ) : (
                            <VerifyPrompt onVerified={verified} organiserSlug={organiser?.slug} />
                        ))}
                    {section === 'communities' &&
                        inboxEnabled &&
                        inboxLocation &&
                        (email || phone) &&
                        online && (
                            <InboxList
                                key={`${offline_identity?.owner_key || email || phone}:${inboxLocation.slug}`}
                                tenantSlug={inboxLocation.slug}
                                name={
                                    visibleInbox?.find((row) => row.slug === inboxLocation.slug)
                                        ?.name || inboxLocation.slug
                                }
                                itemId={inboxLocation.item}
                                context={inboxLocation.context}
                                onOpen={(entry) => openInbox({ slug: inboxLocation.slug }, entry)}
                                onBack={() => openInbox({ slug: inboxLocation.slug })}
                                logoUrl={
                                    visibleInbox?.find((row) => row.slug === inboxLocation.slug)
                                        ?.logo_url
                                }
                                onClose={closeInbox}
                                onChanged={() => setRevision((value) => value + 1)}
                                onExpired={expire}
                            />
                        )}
                    {section === 'money' && (
                        <AttendeeMoney
                            data={money}
                            status={moneyStatus}
                            initialTab={record === 'contributions' ? 'contributions' : 'dues'}
                            onPage={(kind, next) =>
                                kind === 'dues' ? setMoneyPage(next) : setContributionsPage(next)
                            }
                            onRefresh={() => setRevision((value) => value + 1)}
                        />
                    )}
                    {section === 'me' && (
                        <>
                            <AttendeeMe
                                identity={
                                    identity || {
                                        email: email ? { masked: email } : null,
                                        phone: phone ? { masked: 'Verified phone' } : null,
                                    }
                                }
                                preferences={preferences}
                                status={preferencesStatus}
                                onPreference={changePreference}
                                onRemove={removeIdentity}
                                onSignOut={signOut}
                                onVerify={setVerifyChannel}
                                onPage={setPreferencesPage}
                                busy={busy}
                            />
                            {verifyChannel === 'email' && (
                                <VerifyPrompt
                                    onVerified={verified}
                                    organiserSlug={organiser?.slug}
                                    allowPhone={false}
                                />
                            )}{' '}
                            {verifyChannel === 'phone' && (
                                <PhoneVerify
                                    organiserSlug={organiser?.slug}
                                    onVerified={verified}
                                />
                            )}
                        </>
                    )}
                </>
            )}
            <Modal
                open={Boolean(ticketDetail) && online}
                title="Your ticket pass"
                onClose={() => {
                    setTicketDetail(null);
                    setSavedPasses(listOfflineTickets(cacheContext));
                }}
                className="max-w-xl"
                fullScreenOnMobile
            >
                {ticketDetail && (
                    <>
                        <MyTicketPanel
                            event={ticketDetail.event}
                            registration={ticketDetail.registration}
                            offlineIdentity={ticketDetail.offline_identity}
                        />
                        <a
                            href={`/my/events/${ticketDetail.registration.id}`}
                            className="mt-4 inline-flex min-h-11 text-sm text-accent underline"
                        >
                            Open full event workspace: programme, activities & downloads
                        </a>
                    </>
                )}
            </Modal>
            {credential && (
                <RecoveryCredential credential={credential} onClear={() => setCredential(null)} />
            )}
        </AttendeeAppShell>
    );
}
