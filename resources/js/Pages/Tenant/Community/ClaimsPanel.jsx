import { useEffect, useState } from 'react';
import Modal from '@/Components/Console/Modal';
import Button from '@/Components/Console/Button';
import Input from '@/Components/Console/Input';
import Select from '@/Components/Console/Select';
import { communityRequest } from '@/lib/community';

const QUEUES = {
    claims: 'Access requests',
    joins: 'Membership requests',
    reports: 'Wrong-number reports',
};

function ReviewModal({ review, membershipTypes = [], onClose, onSaved }) {
    const kind = review.kind;
    const needsDistinct =
        review.row.status === 'review_required' ||
        (review.row.channel === 'sms' && !review.row.member_id);
    const initialAction =
        kind === 'report'
            ? 'confirm'
            : kind === 'resolve'
              ? 'resolve'
              : kind === 'join' && needsDistinct && review.row.channel === 'email'
                ? 'reject'
                : 'approve';
    const [form, setForm] = useState({
        action: initialAction,
        reason: '',
        method: '',
        member_id: '',
        create_distinct: false,
        membership_type_id: membershipTypes.some(
            (type) => type.id === review.row.membership_type_id
        )
            ? review.row.membership_type_id
            : '',
    });
    const [search, setSearch] = useState('');
    const [choices, setChoices] = useState([]);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [discard, setDiscard] = useState(false);
    const dirty = Boolean(
        form.reason ||
        form.method ||
        form.member_id ||
        form.create_distinct ||
        form.action !== initialAction
    );
    const set = (key) => (event) => setForm({ ...form, [key]: event.target.value });
    useEffect(() => {
        if (kind !== 'claim' || form.action !== 'approve') return undefined;
        let disposed = false;
        const timer = setTimeout(
            () =>
                communityRequest(
                    `${route('tenant.community.claims.member-options')}?search=${encodeURIComponent(search)}`
                )
                    .then((data) => {
                        if (!disposed) setChoices(data.members.data);
                    })
                    .catch((failure) => {
                        if (!disposed) setError(failure.message);
                    }),
            200
        );
        return () => {
            disposed = true;
            clearTimeout(timer);
        };
    }, [kind, search, form.action]);
    const needsMethod = (kind === 'claim' && form.action === 'approve') || kind === 'resolve';
    const needsMember = kind === 'claim' && form.action === 'approve';
    const distinct = kind === 'join' && needsDistinct && form.action === 'approve';
    const canSave =
        form.reason.trim().length >= 10 &&
        (!needsMethod || form.method) &&
        (!needsMember || form.member_id) &&
        (!distinct || form.create_distinct) &&
        (kind !== 'join' || form.action === 'reject' || form.membership_type_id);
    function close() {
        if (busy) return;
        if (dirty) setDiscard(true);
        else onClose();
    }
    async function submit(event) {
        event.preventDefault();
        setBusy(true);
        setError('');
        try {
            let url;
            const payload = { reason: form.reason };
            if (kind === 'claim') {
                url = route(
                    `tenant.community.claims.${form.action === 'approve' ? 'approve' : 'reject'}`,
                    { claim: review.row.id }
                );
                if (form.action === 'approve') {
                    payload.member_id = form.member_id;
                    payload.method = form.method;
                }
            }
            if (kind === 'join') {
                url = route(
                    `tenant.community.joins.${form.action === 'approve' ? 'approve' : 'reject'}`,
                    { join: review.row.id }
                );
                payload.create_distinct = form.create_distinct;
                if (form.action === 'approve') payload.membership_type_id = form.membership_type_id;
            }
            if (kind === 'report') {
                url = route('tenant.community.reports.review', { report: review.row.id });
                payload.confirmed = form.action === 'confirm';
            }
            if (kind === 'resolve') {
                url = route('tenant.community.reports.resolve', { report: review.row.id });
                payload.method = form.method;
            }
            if (kind === 'credential') {
                url = route('tenant.community.credentials.revoke', { credential: review.row.id });
            }
            if (kind === 'revoke-all') {
                url = route('tenant.community.credentials.revoke-all', { member: review.row.id });
            }
            await communityRequest(url, 'POST', payload);
            onSaved();
        } catch (failure) {
            setError(failure.message);
        } finally {
            setBusy(false);
        }
    }
    return (
        <Modal
            open
            onClose={close}
            title={discard ? 'Discard unsaved review notes?' : review.title}
            className="max-w-lg"
        >
            {discard ? (
                <div className="space-y-4">
                    <p className="text-sm text-ink-secondary">Your review has not been saved.</p>
                    <div className="flex flex-wrap gap-3">
                        <Button type="button" onClick={() => setDiscard(false)}>
                            Continue editing
                        </Button>
                        <Button type="button" onClick={onClose}>
                            Discard notes
                        </Button>
                    </div>
                </div>
            ) : (
                <form onSubmit={submit} className="space-y-4">
                    <p className="text-sm text-ink-secondary">
                        {review.description ??
                            'Check identity independently. Control of a phone and a similar name are not sufficient evidence.'}
                    </p>
                    {['claim', 'join', 'report'].includes(kind) && (
                        <Select
                            label="Review decision"
                            value={form.action}
                            onChange={set('action')}
                        >
                            {kind === 'report' ? (
                                <>
                                    <option value="confirm">
                                        Confirm wrong number and stop affected access
                                    </option>
                                    <option value="dismiss">Dismiss this report</option>
                                </>
                            ) : (
                                <>
                                    <option
                                        value="approve"
                                        disabled={
                                            kind === 'join' &&
                                            needsDistinct &&
                                            review.row.channel === 'email'
                                        }
                                    >
                                        {kind === 'claim'
                                            ? 'Approve scoped membership access'
                                            : 'Approve membership'}
                                    </option>
                                    <option value="reject">Decline this request</option>
                                </>
                            )}
                        </Select>
                    )}
                    {needsMember && (
                        <>
                            <Input
                                label="Find an existing member"
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                placeholder="Search name, email or phone"
                            />
                            <Select
                                label="Existing membership"
                                required
                                value={form.member_id}
                                onChange={set('member_id')}
                            >
                                <option value="">Choose the independently verified member</option>
                                {choices.map((member) => (
                                    <option key={member.id} value={member.id}>
                                        {member.name} · {member.email ?? member.phone}
                                    </option>
                                ))}
                            </Select>
                        </>
                    )}
                    {needsMethod && (
                        <Select
                            label="Independent check"
                            required
                            value={form.method}
                            onChange={set('method')}
                        >
                            <option value="">Choose how identity was confirmed</option>
                            <option value="in_person">Checked in person</option>
                            <option value="known_contact">
                                Confirmed through an independently established contact
                            </option>
                        </Select>
                    )}
                    {kind === 'join' && form.action === 'approve' && (
                        <Select
                            label="Approved membership type"
                            required
                            value={form.membership_type_id}
                            onChange={set('membership_type_id')}
                        >
                            <option value="">Choose an active membership type</option>
                            {membershipTypes.map((type) => (
                                <option key={type.id} value={type.id}>
                                    {type.name}
                                    {type.amount != null
                                        ? ` · ${type.currency} ${(type.amount / 100).toFixed(2)}`
                                        : ' · No dues'}
                                </option>
                            ))}
                        </Select>
                    )}
                    {kind === 'join' && needsDistinct && review.row.channel === 'email' && (
                        <p className="text-sm text-ink-secondary">
                            The email already identifies a membership. Review the existing record or
                            ask the applicant to correct the contact; do not duplicate its history.
                        </p>
                    )}
                    {distinct && (
                        <label className="flex items-start gap-2 text-sm text-ink">
                            <input
                                type="checkbox"
                                className="mt-1"
                                checked={form.create_distinct}
                                onChange={(event) =>
                                    setForm({ ...form, create_distinct: event.target.checked })
                                }
                            />
                            I independently checked this is a distinct new person. This does not
                            grant access to any historical membership.
                        </label>
                    )}
                    <label className="block text-sm font-medium text-ink">
                        Review notes
                        <textarea
                            required
                            minLength={10}
                            maxLength={1000}
                            rows={4}
                            value={form.reason}
                            onChange={set('reason')}
                            className="mt-2 block w-full rounded-md border border-border bg-surface px-3 py-2 text-sm text-ink"
                        />
                    </label>
                    {error && (
                        <p role="alert" className="text-sm text-danger-fg">
                            {error}
                        </p>
                    )}
                    <div className="flex flex-wrap gap-3">
                        <Button type="submit" variant="primary" disabled={busy || !canSave}>
                            {busy ? 'Saving…' : 'Save review'}
                        </Button>
                        <Button type="button" disabled={busy} onClick={close}>
                            Cancel
                        </Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}

export function CredentialPanel({ member }) {
    const [rows, setRows] = useState(null);
    const [review, setReview] = useState(null);
    const [error, setError] = useState('');
    async function load() {
        setError('');
        try {
            const data = await communityRequest(
                route('tenant.community.credentials.index', { member: member.id })
            );
            setRows(data.credentials);
        } catch (failure) {
            setError(failure.message);
        }
    }
    return (
        <section className="space-y-3 rounded-lg border border-border p-4">
            <h3 className="text-sm font-semibold text-ink">Membership phone access</h3>
            <p className="text-xs text-ink-secondary">
                Keys are private to their holders. Staff can review or revoke access but cannot
                retrieve a key. Suspended representatives need a fresh independent access review.
            </p>
            <Button type="button" onClick={load}>
                {rows ? 'Refresh phone access' : 'Review phone access'}
            </Button>
            {rows?.map((row) => (
                <div
                    key={row.id}
                    className="space-y-2 border-t border-border pt-3 text-sm text-ink"
                >
                    <p>
                        {row.role === 'representative' ? 'Representative' : 'Direct member'} ·{' '}
                        {row.phone}
                        <span className="block text-xs text-ink-secondary">
                            {row.revoked_at
                                ? 'Revoked'
                                : row.suspended_at
                                  ? 'Suspended pending a new review'
                                  : 'Active'}
                        </span>
                    </p>
                    {!row.revoked_at && (
                        <Button
                            type="button"
                            onClick={() =>
                                setReview({
                                    kind: 'credential',
                                    row,
                                    title: 'Revoke membership credential',
                                    description:
                                        'This private key will stop working. The membership and all financial history are kept.',
                                })
                            }
                        >
                            Revoke this credential
                        </Button>
                    )}
                </div>
            ))}
            {rows?.length === 0 && (
                <p className="text-sm text-ink-secondary">No phone credentials for this member.</p>
            )}
            {rows?.some((row) => !row.revoked_at) && (
                <Button
                    type="button"
                    onClick={() =>
                        setReview({
                            kind: 'revoke-all',
                            row: member,
                            title: 'Revoke all membership phone access',
                            description:
                                'All existing phone credentials for this member stop working. Their membership and dues history remain intact.',
                        })
                    }
                >
                    Revoke all phone access
                </Button>
            )}
            {error && (
                <p role="alert" className="text-sm text-danger-fg">
                    {error}
                </p>
            )}
            {review && (
                <ReviewModal
                    review={review}
                    onClose={() => setReview(null)}
                    onSaved={() => {
                        setReview(null);
                        load();
                    }}
                />
            )}
        </section>
    );
}

export default function ClaimsPanel({ membershipTypes = [], initialQueue = 'claims' }) {
    const [queue, setQueue] = useState(QUEUES[initialQueue] ? initialQueue : 'claims');
    const [data, setData] = useState(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [review, setReview] = useState(null);
    const paths = {
        claims: 'tenant.community.claims.index',
        joins: 'tenant.community.joins.index',
        reports: 'tenant.community.reports.index',
    };
    async function load(url = route(paths[queue])) {
        setBusy(true);
        setError('');
        try {
            const response = await communityRequest(url);
            setData(response[queue === 'joins' ? 'requests' : queue]);
        } catch (failure) {
            setError(failure.message);
        } finally {
            setBusy(false);
        }
    }
    useEffect(() => {
        let disposed = false;
        setData(null);
        setBusy(true);
        setError('');
        communityRequest(route(paths[queue]))
            .then((response) => {
                if (!disposed) setData(response[queue === 'joins' ? 'requests' : queue]);
            })
            .catch((failure) => {
                if (!disposed) setError(failure.message);
            })
            .finally(() => {
                if (!disposed) setBusy(false);
            });
        return () => {
            disposed = true;
        };
    }, [queue]);
    return (
        <section className="space-y-5 px-4 py-6 sm:px-8">
            <div>
                <h2 className="text-xl font-semibold text-ink">Access and membership reviews</h2>
                <p className="mt-2 max-w-2xl text-sm text-ink-secondary">
                    Review every person separately. Approving membership does not approve historical
                    record access, and a shared phone is not an identity check.
                </p>
            </div>
            <div className="flex flex-wrap gap-2" role="group" aria-label="Review queues">
                {Object.entries(QUEUES).map(([key, label]) => (
                    <button
                        key={key}
                        type="button"

                        aria-pressed={queue === key}
                        onClick={() => setQueue(key)}
                        className={`rounded-md border px-3 py-2 text-sm ${queue === key ? 'border-accent bg-accent-soft text-accent' : 'border-border bg-surface text-ink-secondary'}`}
                    >
                        {label}
                    </button>
                ))}
            </div>
            {busy && (
                <p role="status" className="text-sm text-ink-secondary">
                    Loading reviews…
                </p>
            )}
            {error && (
                <p role="alert" className="text-sm text-danger-fg">
                    {error}
                </p>
            )}
            {data?.data?.length === 0 && (
                <p className="rounded-lg border border-border p-5 text-sm text-ink-secondary">
                    No requests in this queue.
                </p>
            )}
            <div className="space-y-3">
                {data?.data?.map((row) => (
                    <article
                        key={row.id}
                        className="flex flex-wrap items-start justify-between gap-4 rounded-lg border border-border bg-surface p-5"
                    >
                        <div className="space-y-1">
                            <h3 className="font-medium text-ink">
                                {row.claimant_name ?? row.name ?? row.phone}
                            </h3>
                            <p className="text-sm text-ink-secondary">
                                {row.role
                                    ? `${row.role === 'representative' ? 'Representative access' : 'Own membership access'} · `
                                    : ''}
                                {row.address ?? row.phone} ·{' '}
                                {queue === 'claims' &&
                                row.expires_at &&
                                Date.parse(row.expires_at) <= Date.now() &&
                                row.status === 'pending'
                                    ? 'expired'
                                    : row.status}
                            </p>
                            {row.note && (
                                <p className="max-w-xl text-sm text-ink-secondary">{row.note}</p>
                            )}
                            {row.reason && (
                                <p className="max-w-xl text-sm text-ink-secondary">
                                    Review: {row.reason}
                                </p>
                            )}
                        </div>
                        {queue === 'claims' &&
                            row.status === 'pending' &&
                            (!row.expires_at || Date.parse(row.expires_at) > Date.now()) && (
                                <Button
                                    type="button"
                                    onClick={() =>
                                        setReview({
                                            kind: 'claim',
                                            row,
                                            title: 'Review membership access',
                                        })
                                    }
                                >
                                    Review access request
                                </Button>
                            )}
                        {queue === 'joins' &&
                            ['pending', 'review_required'].includes(row.status) && (
                                <Button
                                    type="button"
                                    onClick={() =>
                                        setReview({
                                            kind: 'join',
                                            row,
                                            title: 'Review membership request',
                                        })
                                    }
                                >
                                    Review membership request
                                </Button>
                            )}
                        {queue === 'reports' && row.status === 'pending' && (
                            <Button
                                type="button"
                                onClick={() =>
                                    setReview({
                                        kind: 'report',
                                        row,
                                        title: 'Review wrong-number report',
                                        description:
                                            'A confirmed report stops affected phone access and disputed SMS. An uncertain report can be dismissed without changing membership or financial history.',
                                    })
                                }
                            >
                                Review wrong-number report
                            </Button>
                        )}
                        {queue === 'reports' && row.status === 'confirmed' && (
                            <Button
                                type="button"
                                onClick={() =>
                                    setReview({
                                        kind: 'resolve',
                                        row,
                                        title: 'Independently reconfirm this number',
                                        description:
                                            'Clearing this hold does not revive old private keys. A new access review is still required.',
                                    })
                                }
                            >
                                Reconfirm number
                            </Button>
                        )}
                    </article>
                ))}
            </div>
            {data && (
                <div className="flex flex-wrap justify-end gap-3">
                    <Button
                        type="button"
                        disabled={!data.prev_page_url || busy}
                        onClick={() => load(data.prev_page_url)}
                    >
                        Previous
                    </Button>
                    <Button
                        type="button"
                        disabled={!data.next_page_url || busy}
                        onClick={() => load(data.next_page_url)}
                    >
                        Next
                    </Button>
                </div>
            )}
            {review && (
                <ReviewModal
                    review={review}
                    membershipTypes={membershipTypes}
                    onClose={() => setReview(null)}
                    onSaved={() => {
                        setReview(null);
                        load();
                    }}
                />
            )}
        </section>
    );
}
