import AttendeeMoney from './components/AttendeeMoney';
import AttendeeMe from './components/AttendeeMe';
import PhoneVerify from '@/Pages/Public/Community/PhoneVerify';
import { clearAllOfflineTickets } from '@/lib/offlineTicketStore';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import csrfFetch from '@/lib/csrfFetch';
import AttendeeAppShell, { sectionFromLocation } from './components/AttendeeAppShell';
import AttendeeHome from './components/AttendeeHome';
import AttendeeTickets from './components/AttendeeTickets';
import VerifyPrompt from './VerifyPrompt';
import CommunitiesPanel from '@/Pages/Public/Community/CommunitiesPanel';
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
    verifiedEmail,
    communityPhoneVerified,
    organiser,
    communityReturnUrl,
}) {
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
    const [moneyPage, setMoneyPage] = useState(1);
    const [contributionsPage, setContributionsPage] = useState(1);
    const [identity, setIdentity] = useState(null);
    const [preferences, setPreferences] = useState(null);
    const [preferencesPage, setPreferencesPage] = useState(1);
    const [verifyChannel, setVerifyChannel] = useState(null);
    const [busy, setBusy] = useState(false);
    const generation = useRef(0);
    const expire = useCallback(() => {
        generation.current++;
        clearAllOfflineTickets();
        setIdentity(null);
        setPreferences(null);
        setEmail(null);
        setPhone(false);
        setHistory(null);
        setMoney(null);
        setRecords(null);
    }, []);
    useEffect(() => {
        const back = () => {
            setSection(sectionFromLocation(window.location));
            setRecord(new URLSearchParams(window.location.search).get('tab'));
        };
        window.addEventListener('popstate', back);
        return () => window.removeEventListener('popstate', back);
    }, []);
    const navigate = (key) => {
        setSection(key);
        setRecord(null);
        setError('');
        const url = new URL(window.location.href);
        url.searchParams.set('section', key);
        url.searchParams.delete('tab');
        url.hash = '';
        window.history.pushState({}, '', url);
    };
    useEffect(() => {
        if (!email || !['home', 'tickets'].includes(section) || period === 'saved')
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
        if (!email || !RECORDS[record] || record === 'contributions') return undefined;
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
        if (section !== 'me' || (!email && !phone)) return undefined;
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
                }
            })
            .catch((failure) => {
                if (failure.name !== 'AbortError' && current === generation.current)
                    setError(failure.message);
            });
        return () => controller.abort();
    }, [section, email, phone, revision, preferencesPage, expire]);
    useEffect(() => {
        if (!['home', 'money'].includes(section) || (!email && !phone)) return undefined;
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
                if (current === generation.current) setMoney(data);
            })
            .catch((failure) => {
                if (failure.name !== 'AbortError' && current === generation.current)
                    setError(failure.message);
            });
        return () => controller.abort();
    }, [section, email, phone, moneyPage, contributionsPage, organiser?.slug, revision, expire]);
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
            expire();
            router.reload();
        } catch (failure) {
            setError(failure.message);
        }
    };
    const Record = RECORDS[record];
    const needsEmail = ['home', 'tickets'].includes(section) && !email;
    return (
        <AttendeeAppShell section={section} onSectionChange={navigate}>
            <Head title="My MiConvener" />
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
            {needsEmail ? (
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
                    {loading && <HistorySkeleton />}
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
                    ) : !loading && section === 'home' ? (
                        <AttendeeHome
                            history={history}
                            duesSummary={money}
                            onSectionChange={navigate}
                            onOpen={(entry) => window.location.assign(entry.event_workspace_url)}
                        />
                    ) : !loading && section === 'tickets' ? (
                        <AttendeeTickets
                            history={history}
                            period={period}
                            onFilterChange={(key, next) => {
                                setPeriod(key);
                                setPage(next);
                            }}
                            onRecord={openRecord}
                            onOpen={(entry) => window.location.assign(entry.event_workspace_url)}
                        />
                    ) : null}
                    {section === 'communities' &&
                        (email || phone ? (
                            <CommunitiesPanel
                                organiser={organiser}
                                initiallyOpen
                                onCredential={setCredential}
                                onExpired={expire}
                                onPhoneRemoved={() => {
                                    setPhone(false);
                                    setRevision((value) => value + 1);
                                }}
                            />
                        ) : (
                            <VerifyPrompt onVerified={verified} organiserSlug={organiser?.slug} />
                        ))}
                    {section === 'money' && (
                        <AttendeeMoney
                            data={money}
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
            {credential && (
                <RecoveryCredential credential={credential} onClear={() => setCredential(null)} />
            )}
        </AttendeeAppShell>
    );
}
