import { useCallback, useEffect, useRef, useState } from 'react';
import { communityRequest } from '@/lib/community';
import FollowControl from './FollowControl';
import Join from './Join';
import MemberClaim from './MemberClaim';
import { clearAllOfflineTickets } from '@/lib/offlineTicketStore';

export default function CommunitiesPanel({
    organiser,
    inboxOverview,
    onOpenInbox,
    initiallyOpen = false,
    onCredential,
    onExpired,
    onPhoneRemoved,
}) {
    const [revision, setRevision] = useState(0);
    const [confirmPhoneRemoval, setConfirmPhoneRemoval] = useState(false);
    const [open, setOpen] = useState(initiallyOpen);
    const [identity, setIdentity] = useState(null);
    const [communities, setCommunities] = useState(null);
    const [selected, setSelected] = useState(organiser?.slug ?? null);
    const [detail, setDetail] = useState(null);
    const [options, setOptions] = useState(null);
    const [member, setMember] = useState(null);
    const [charges, setCharges] = useState(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const section = useRef(null);
    const load = useCallback(
        async (url = '/my/communities') => {
            setBusy(true);
            setError('');
            try {
                const [proof, data] = await Promise.all([
                    communityRequest('/my/identity'),
                    communityRequest(url),
                ]);
                setIdentity(proof);
                setCommunities(data.communities);
            } catch (failure) {
                setError(failure.message);
                if (failure.status === 401) {
                    setDetail(null);
                    setOptions(null);
                    setCommunities(null);
                    onExpired?.();
                }
            } finally {
                setBusy(false);
            }
        },
        [onExpired]
    );
    useEffect(() => {
        if (open && !communities && !busy && !error) load();
    }, [open, communities, busy, error, load]);
    useEffect(() => {
        if (!open || !selected) return undefined;
        let disposed = false;
        setDetail(null);
        setOptions(null);
        setMember(null);
        setCharges(null);
        setError('');
        Promise.all([
            communityRequest(`/my/communities/${encodeURIComponent(selected)}/options`),
            communityRequest(`/my/communities/${encodeURIComponent(selected)}`).catch((failure) => {
                if (failure.status === 404) return null;
                throw failure;
            }),
        ])
            .then(([settings, data]) => {
                if (!disposed) {
                    setOptions(settings);
                    setDetail(data);
                }
            })
            .catch((failure) => {
                if (!disposed) {
                    setError(failure.message);
                    if (failure.status === 401) onExpired?.();
                }
            });
        return () => {
            disposed = true;
        };
    }, [open, selected, onExpired, revision]);
    useEffect(() => {
        if (open && window.location.hash === '#communities') section.current?.scrollIntoView?.();
    }, [open]);
    async function memberDetails(row) {
        setBusy(true);
        setError('');
        setMember(null);
        setCharges(null);
        try {
            const data = await communityRequest(
                `/my/communities/${encodeURIComponent(selected)}/members/${row.id}/charges`
            );
            setMember(row);
            setCharges(data.charges);
        } catch (failure) {
            setError(failure.message);
            if (failure.status === 401) onExpired?.();
            if (failure.status === 404) {
                setDetail(null);
                await load();
            }
        } finally {
            setBusy(false);
        }
    }
    async function saveJoinKey(row) {
        setBusy(true);
        setError('');
        try {
            const key = await communityRequest(
                `/my/communities/${encodeURIComponent(selected)}/joins/${row.id}/credential`,
                'POST'
            );
            onCredential?.(key);
        } catch (failure) {
            setError(failure.message);
        } finally {
            setBusy(false);
        }
    }
    async function enrol(row) {
        setBusy(true);
        setError('');
        try {
            const key = await communityRequest(
                `/my/communities/${encodeURIComponent(selected)}/members/${row.id}/phone-access`,
                'POST'
            );
            onCredential?.(key);
        } catch (failure) {
            setError(failure.message);
        } finally {
            setBusy(false);
        }
    }
    async function forgetPhone() {
        setBusy(true);
        setError('');
        try {
            await communityRequest('/my/verify/phone/forget', 'POST');
            clearAllOfflineTickets();
            setDetail(null);
            setOptions(null);
            setCommunities(null);
            setIdentity(null);
            onPhoneRemoved?.();
            await load();
        } catch (failure) {
            setError(failure.message);
        } finally {
            setBusy(false);
        }
    }
    async function wrongNumber(row) {
        setBusy(true);
        setError('');
        try {
            await communityRequest(
                `/my/communities/${encodeURIComponent(selected)}/wrong-number`,
                'POST',
                {
                    member_id: row.id,
                    note: 'The recorded number is not mine. Please independently review this contact.',
                }
            );
            setNotice(
                'Your wrong-number report was submitted for staff review. No membership or history has been deleted.'
            );
        } catch (failure) {
            setError(failure.message);
        } finally {
            setBusy(false);
        }
    }
    async function pageMembers(url) {
        setBusy(true);
        setError('');
        try {
            const data = await communityRequest(url);
            setDetail(data);
        } catch (failure) {
            setError(failure.message);
        } finally {
            setBusy(false);
        }
    }
    return (
        <section
            ref={section}
            tabIndex={-1}
            id="communities"
            className="mt-10 space-y-5 border-t border-border pt-8"
            aria-label="My Communities"
        >
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h2 className="text-xl font-semibold text-ink">My Communities</h2>
                <button
                    type="button"
                    onClick={() => setOpen(!open)}
                    aria-expanded={open}
                    className="text-sm font-medium text-accent underline"
                >
                    {open ? 'Hide Communities' : 'Open Communities'}
                </button>
            </div>
            <p className="text-sm text-ink-secondary">
                Memberships you can access and organisations you explicitly follow. A verified phone
                alone does not reveal existing memberships.
            </p>
            {open && (
                <div className="space-y-5">
                    <div className="flex flex-wrap gap-3 text-sm">
                        <button
                            type="button"
                            disabled={busy}
                            onClick={() => {
                                load();
                                setRevision((value) => value + 1);
                            }}
                            className="text-accent underline"
                        >
                            Refresh my Communities
                        </button>
                        {identity?.phone && (
                            <button
                                type="button"
                                onClick={() => setConfirmPhoneRemoval(true)}
                                disabled={busy}
                                className="text-ink-secondary underline"
                            >
                                Remove phone verification
                            </button>
                        )}
                    </div>
                    {confirmPhoneRemoval && (
                        <div
                            role="alert"
                            className="space-y-3 rounded-lg border border-border p-4 text-sm text-ink"
                        >
                            <p>
                                Removing phone verification clears this device’s phone access and
                                request links. Save your private recovery keys first.
                            </p>
                            <div className="flex gap-3">
                                <button
                                    type="button"
                                    onClick={() => setConfirmPhoneRemoval(false)}
                                    className="text-accent underline"
                                >
                                    Keep phone verification
                                </button>
                                <button
                                    type="button"
                                    disabled={busy}
                                    onClick={() => {
                                        setConfirmPhoneRemoval(false);
                                        forgetPhone();
                                    }}
                                    className="text-danger-fg underline"
                                >
                                    Remove phone verification now
                                </button>
                            </div>
                        </div>
                    )}
                    {communities?.data?.length > 0 ? (
                        <div className="flex flex-wrap gap-2">
                            {communities.data.map((community) => (
                                <button
                                    key={community.id}
                                    type="button"
                                    onClick={() => setSelected(community.slug)}
                                    aria-pressed={selected === community.slug}
                                    className="rounded-lg border border-border bg-surface px-4 py-3 text-left text-sm font-medium text-ink hover:bg-surface-hover"
                                >
                                    {community.name}
                                    {inboxOverview?.find((row) => row.slug === community.slug)
                                        ?.unread > 0 && (
                                        <span className="ml-2 text-xs text-accent">
                                            {inboxOverview.find(
                                                (row) => row.slug === community.slug
                                            ).unread > 99
                                                ? '99+'
                                                : inboxOverview.find(
                                                      (row) => row.slug === community.slug
                                                  ).unread}{' '}
                                            unread
                                        </span>
                                    )}
                                </button>
                            ))}
                        </div>
                    ) : (
                        communities && (
                            <p className="rounded-lg border border-border p-4 text-sm text-ink-secondary">
                                No accessible memberships or followed organisations yet. Use an
                                organisation’s public page to follow or join, or request an
                                independent membership review below.
                            </p>
                        )
                    )}
                    {inboxOverview?.length > 0 && (
                        <section aria-label="Organisation inboxes" className="flex flex-wrap gap-3">
                            {inboxOverview.map((organisation) => (
                                <button
                                    key={organisation.slug}
                                    type="button"
                                    onClick={() => onOpenInbox?.(organisation)}
                                    className="min-h-11 border border-border bg-surface px-4 py-3 text-left text-sm text-accent hover:bg-surface-hover"
                                >
                                    Messages · {organisation.name}{' '}
                                    <span className="ml-1 text-xs">
                                        {organisation.unread > 99 ? '99+' : organisation.unread}{' '}
                                        unread
                                    </span>
                                </button>
                            ))}
                        </section>
                    )}
                    {communities && (
                        <div className="flex gap-3 text-sm">
                            {communities.prev_page_url && (
                                <button
                                    type="button"
                                    onClick={() => load(communities.prev_page_url)}
                                    className="text-accent underline"
                                >
                                    Previous organisations
                                </button>
                            )}
                            {communities.next_page_url && (
                                <button
                                    type="button"
                                    onClick={() => load(communities.next_page_url)}
                                    className="text-accent underline"
                                >
                                    More organisations
                                </button>
                            )}
                        </div>
                    )}
                    {options && (
                        <div className="space-y-6 rounded-xl border border-border bg-surface p-5">
                            <div className="space-y-2">
                                <h3 className="text-lg font-semibold text-ink">
                                    {options.organisation.name}
                                </h3>
                                {(options.communityEnabled || options.followingEnabled) && (
                                    <a
                                        href={options.organisation.about_url}
                                        className="text-sm text-accent underline"
                                    >
                                        Public organisation page
                                    </a>
                                )}
                            </div>
                            {detail?.members?.data?.map((row) => (
                                <article
                                    key={row.id}
                                    className="space-y-3 rounded-lg border border-border p-4"
                                >
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <h4 className="font-semibold text-ink">{row.name}</h4>
                                        <span className="text-xs text-ink-secondary">
                                            {row.access_role === 'representative'
                                                ? 'Representative access'
                                                : 'Your membership'}{' '}
                                            · {row.status}
                                        </span>
                                    </div>
                                    <p className="text-sm text-ink-secondary">
                                        {row.membership_type.name}
                                    </p>
                                    <div className="flex flex-wrap gap-3 text-sm">
                                        <button
                                            type="button"
                                            disabled={busy}
                                            onClick={() => memberDetails(row)}
                                            className="text-accent underline"
                                        >
                                            Charges and receipts
                                        </button>
                                        <a
                                            href={row.pay_url}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="text-accent underline"
                                        >
                                            Open payment page
                                        </a>
                                        {identity?.email &&
                                            identity?.phone &&
                                            row.access_role === 'self' &&
                                            options.communityEnabled && (
                                                <button
                                                    type="button"
                                                    disabled={busy}
                                                    onClick={() => enrol(row)}
                                                    className="text-accent underline"
                                                >
                                                    Set up phone recovery
                                                </button>
                                            )}
                                        <button
                                            type="button"
                                            disabled={busy}
                                            onClick={() => wrongNumber(row)}
                                            className="text-ink-secondary underline"
                                        >
                                            Report a wrong recorded number
                                        </button>
                                    </div>
                                </article>
                            ))}
                            {detail?.members && (
                                <div className="flex gap-3 text-sm">
                                    {detail.members.prev_page_url && (
                                        <button
                                            type="button"
                                            onClick={() =>
                                                pageMembers(detail.members.prev_page_url)
                                            }
                                            className="text-accent underline"
                                        >
                                            Previous memberships
                                        </button>
                                    )}
                                    {detail.members.next_page_url && (
                                        <button
                                            type="button"
                                            onClick={() =>
                                                pageMembers(detail.members.next_page_url)
                                            }
                                            className="text-accent underline"
                                        >
                                            More memberships
                                        </button>
                                    )}
                                </div>
                            )}
                            {member && charges && (
                                <section
                                    className="space-y-3"
                                    aria-label={`Charges for ${member.name}`}
                                >
                                    <h4 className="font-semibold text-ink">
                                        {member.name} · Charges and receipts
                                    </h4>
                                    {charges.data.length === 0 ? (
                                        <p className="text-sm text-ink-secondary">
                                            No charges yet.
                                        </p>
                                    ) : (
                                        charges.data.map((charge) => (
                                            <div
                                                key={charge.id}
                                                className="flex flex-wrap justify-between gap-3 border-b border-border py-3 text-sm text-ink"
                                            >
                                                <span>
                                                    {charge.period_start} – {charge.period_end}
                                                    <span className="block text-xs text-ink-secondary">
                                                        {charge.status} · {charge.currency}{' '}
                                                        {(charge.amount / 100).toFixed(2)}
                                                    </span>
                                                </span>
                                                {charge.receipt_url && (
                                                    <a
                                                        href={charge.receipt_url}
                                                        className="text-accent underline"
                                                    >
                                                        Download receipt
                                                    </a>
                                                )}
                                            </div>
                                        ))
                                    )}
                                    <div className="flex gap-3 text-sm">
                                        {[charges.prev_page_url, charges.next_page_url]
                                            .filter(Boolean)
                                            .map((url, index) => (
                                                <button
                                                    key={url}
                                                    type="button"
                                                    className="text-accent underline"
                                                    onClick={async () => {
                                                        try {
                                                            const data =
                                                                await communityRequest(url);
                                                            setCharges(data.charges);
                                                        } catch (failure) {
                                                            setMember(null);
                                                            setCharges(null);
                                                            setError(failure.message);
                                                        }
                                                    }}
                                                >
                                                    {index === 0 && charges.prev_page_url
                                                        ? 'Previous charges'
                                                        : 'More charges'}
                                                </button>
                                            ))}
                                    </div>
                                </section>
                            )}
                            {options.requests?.joins?.map((row) => (
                                <div
                                    key={row.id}
                                    className="space-y-2 rounded-lg bg-surface-hover p-4 text-sm text-ink"
                                >
                                    <p>
                                        {row.name} · Membership request {row.status}
                                    </p>
                                    {row.can_save_recovery && (
                                        <button
                                            type="button"
                                            disabled={busy}
                                            onClick={() => saveJoinKey(row)}
                                            className="text-accent underline"
                                        >
                                            Save membership recovery key
                                        </button>
                                    )}
                                </div>
                            ))}
                            {identity?.phone && (
                                <MemberClaim
                                    key={selected}
                                    allowRequests={options.communityEnabled}
                                    organisationSlug={selected}
                                    identity={identity}
                                    initialClaims={options.requests?.claims}
                                    onCredential={onCredential}
                                    onUpdated={() => {
                                        load();
                                        setRevision((value) => value + 1);
                                    }}
                                />
                            )}
                            {options.followingEnabled && (
                                <FollowControl
                                    identity={identity}
                                    initialFollowing={options.following}
                                    followUrl={options.followUrl}
                                    signInUrl={options.signInUrl}
                                />
                            )}
                            {options.communityEnabled && (
                                <details className="rounded-lg border border-border p-4">
                                    <summary className="cursor-pointer text-sm font-medium text-ink">
                                        Request a new membership
                                    </summary>
                                    <div className="mt-4">
                                        <Join
                                            identity={identity}
                                            joinOptions={options.joinOptions}
                                            joinUrl={options.joinUrl}
                                            signInUrl={options.portalUrl}
                                            onSubmitted={() => {
                                                load();
                                                setRevision((value) => value + 1);
                                            }}
                                        />
                                    </div>
                                </details>
                            )}
                        </div>
                    )}
                    {busy && (
                        <p role="status" className="text-sm text-ink-secondary">
                            Loading…
                        </p>
                    )}
                    {notice && (
                        <p role="status" className="text-sm text-success-fg">
                            {notice}
                        </p>
                    )}
                    {error && (
                        <p role="alert" className="text-sm text-danger-fg">
                            {error}
                        </p>
                    )}
                </div>
            )}
        </section>
    );
}
