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
    const generation = useRef(0);
    const expire = useCallback(() => {
        generation.current++;
        setEmail(null);
        setPhone(false);
        setHistory(null);
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
        if (!email || !RECORDS[record]) return undefined;
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
    const needsEmail = ['home', 'tickets', 'money'].includes(section) && !email;
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
                    {!loading && Record && ['tickets', 'money'].includes(section) ? (
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
                    {section === 'money' && !Record && (
                        <>
                            <h1 className="text-3xl font-medium">Your money records</h1>
                            <button
                                type="button"
                                onClick={() => openRecord('contributions')}
                                className="mt-5 min-h-11 text-sm underline"
                            >
                                Contributions & receipts
                            </button>
                        </>
                    )}
                    {section === 'me' && (
                        <section className="max-w-xl">
                            <h1 className="text-3xl font-medium">Your details</h1>
                            <p className="mt-5 text-sm text-ink-secondary">
                                Email: {email || 'Not verified'}
                            </p>
                            <p className="mt-2 text-sm text-ink-secondary">
                                Phone: {phone ? 'Independently verified' : 'Not verified'}
                            </p>
                            {!email && (
                                <VerifyPrompt
                                    onVerified={verified}
                                    organiserSlug={organiser?.slug}
                                />
                            )}
                            <button
                                type="button"
                                onClick={signOut}
                                className="mt-5 min-h-11 border border-border px-4 text-sm"
                            >
                                Sign out of this device
                            </button>
                        </section>
                    )}
                </>
            )}
            {credential && (
                <RecoveryCredential credential={credential} onClear={() => setCredential(null)} />
            )}
        </AttendeeAppShell>
    );
}
