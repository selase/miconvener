import { useEffect, useState } from 'react';
import { communityRequest } from '@/lib/community';

export default function MemberClaim({
    organisationSlug,
    identity,
    claimUrl,
    accessUrl,
    onCredential,
    onUpdated,
    initialClaims = [],
    allowRequests = true,
}) {
    const [mode, setMode] = useState(allowRequests ? 'claim' : 'recover');
    const [name, setName] = useState('');
    const [role, setRole] = useState('self');
    const [recovery, setRecovery] = useState('');
    const [claims, setClaims] = useState(initialClaims);
    const [claim, setClaim] = useState(allowRequests ? (initialClaims[0] ?? null) : null);
    useEffect(() => {
        if (!allowRequests) {
            setMode('recover');
            setClaim(null);
            setRecovery('');
        }
    }, [allowRequests]);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [restored, setRestored] = useState(false);
    const [reportSent, setReportSent] = useState(false);
    async function reportWrongNumber() {
        setBusy(true);
        setError('');
        try {
            await communityRequest(
                `/my/communities/${encodeURIComponent(organisationSlug)}/wrong-number`,
                'POST',
                {
                    note: 'I control this phone number and am receiving messages for someone else. Please independently review the contact.',
                }
            );
            setReportSent(true);
        } catch (failure) {
            setError(failure.message);
        } finally {
            setBusy(false);
        }
    }
    if (!identity?.phone)
        return (
            <p className="text-sm text-ink-secondary">
                To request phone-based membership access, verify your phone for this organisation
                first. Exact email-owned memberships appear after email verification.
            </p>
        );
    const base = `/my/communities/${encodeURIComponent(organisationSlug)}`;
    async function submit(event) {
        event.preventDefault();
        setBusy(true);
        setError('');
        try {
            if (mode === 'recover') {
                const [selector, secret] = recovery.trim().split('.');
                const data = await communityRequest(accessUrl ?? `${base}/access`, 'POST', {
                    selector,
                    secret,
                });
                if (!data.established)
                    throw new Error(
                        data.message ?? 'Check your phone verification and recovery key.'
                    );
                setRecovery('');
                setRestored(true);
                onUpdated?.();
            } else {
                const data = await communityRequest(claimUrl ?? `${base}/claims`, 'POST', {
                    name,
                    role,
                });
                setClaim(data.claim);
                setClaims((current) => [
                    data.claim,
                    ...current.filter((row) => row.id !== data.claim.id),
                ]);
            }
        } catch (failure) {
            setError(failure.message);
        } finally {
            setBusy(false);
        }
    }
    async function refresh() {
        setBusy(true);
        setError('');
        try {
            const data = await communityRequest(`${base}/claims/${claim.id}`);
            setClaim(data.claim);
            setClaims((current) =>
                current.map((row) => (row.id === data.claim.id ? data.claim : row))
            );
        } catch (failure) {
            setError(failure.message);
        } finally {
            setBusy(false);
        }
    }
    async function complete() {
        setBusy(true);
        setError('');
        try {
            const data = await communityRequest(`${base}/claims/${claim.id}/complete`, 'POST');
            onCredential?.(data);
            onUpdated?.();
            setClaim({ ...claim, status: 'completed' });
            setClaims((current) =>
                current.map((row) => (row.id === claim.id ? { ...row, status: 'completed' } : row))
            );
        } catch (failure) {
            setError(failure.message);
        } finally {
            setBusy(false);
        }
    }
    return (
        <section className="space-y-4 rounded-lg border border-border bg-surface p-5">
            <h2 className="text-lg font-semibold text-ink">
                {allowRequests ? 'Verify your membership' : 'Restore existing membership access'}
            </h2>
            <p className="text-sm text-ink-secondary">
                A phone code proves control of a number. Existing memberships need a separate check
                by the organisation, including when you represent a family member.
            </p>
            <div className="flex flex-wrap gap-3">
                {allowRequests && (
                    <button
                        type="button"
                        disabled={busy}
                        onClick={() => {
                            setMode('claim');
                            setClaim(null);
                            setRecovery('');
                            setError('');
                        }}
                        className="text-sm font-medium text-accent underline"
                    >
                        New access request
                    </button>
                )}
                <button
                    type="button"
                    disabled={busy}
                    onClick={() => {
                        setMode('recover');
                        setClaim(null);
                        setError('');
                    }}
                    className="text-sm font-medium text-accent underline"
                >
                    Use my private recovery key
                </button>
            </div>
            {allowRequests && claims.length > 1 && (
                <label className="block text-sm font-medium text-ink">
                    Your access requests
                    <select
                        value={claim?.id ?? ''}
                        onChange={(event) => {
                            setClaim(claims.find((row) => row.id === event.target.value) ?? null);
                            setMode('claim');
                            setRecovery('');
                            setError('');
                        }}
                        className="mt-2 block w-full rounded-md border border-border bg-surface px-3 py-2 text-ink"
                    >
                        <option value="">Choose a request</option>
                        {claims.map((row) => (
                            <option key={row.id} value={row.id}>
                                {row.claimant_name} ·{' '}
                                {row.role === 'representative'
                                    ? 'Representative'
                                    : 'Own membership'}{' '}
                                · {row.status}
                            </option>
                        ))}
                    </select>
                </label>
            )}
            {restored && (
                <p role="status" className="text-sm text-success-fg">
                    Membership access restored.
                </p>
            )}
            {claim ? (
                <div role="status" className="space-y-3 text-sm text-ink">
                    <p>
                        {claim.status === 'pending'
                            ? 'Your request is awaiting independent review by the organisation.'
                            : claim.status === 'approved'
                              ? 'Access was approved. Save your private recovery key to finish.'
                              : claim.status === 'completed'
                                ? 'Your access review is complete.'
                                : `Request ${claim.status}. Contact the organisation if you need help.`}
                    </p>
                    {claim.status === 'approved' && (
                        <button
                            type="button"
                            disabled={busy}
                            onClick={complete}
                            className="rounded-md bg-accent px-4 py-2 text-white"
                        >
                            Save approved access
                        </button>
                    )}
                    <button
                        type="button"
                        onClick={refresh}
                        disabled={busy}
                        className="block text-accent underline"
                    >
                        Check request status
                    </button>
                </div>
            ) : (
                <form onSubmit={submit} className="space-y-4">
                    {mode === 'claim' ? (
                        <>
                            <label className="block text-sm font-medium text-ink">
                                Your name
                                <input
                                    required
                                    maxLength={255}
                                    value={name}
                                    onChange={(event) => setName(event.target.value)}
                                    className="mt-2 block w-full rounded-md border border-border bg-surface px-3 py-2 text-ink"
                                />
                            </label>
                            <label className="block text-sm font-medium text-ink">
                                Access requested
                                <select
                                    value={role}
                                    onChange={(event) => setRole(event.target.value)}
                                    className="mt-2 block w-full rounded-md border border-border bg-surface px-3 py-2 text-ink"
                                >
                                    <option value="self">My own membership</option>
                                    <option value="representative">
                                        Represent a family member or another person
                                    </option>
                                </select>
                            </label>
                        </>
                    ) : (
                        <label className="block text-sm font-medium text-ink">
                            Private recovery key
                            <input
                                type="password"
                                autoComplete="off"
                                maxLength={160}
                                required
                                value={recovery}
                                onChange={(event) => setRecovery(event.target.value)}
                                placeholder="selector.secret"
                                className="mt-2 block w-full rounded-md border border-border bg-surface px-3 py-2 text-ink"
                            />
                        </label>
                    )}
                    <button
                        disabled={busy}
                        className="rounded-md bg-accent px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                        type="submit"
                    >
                        {busy
                            ? 'Working…'
                            : mode === 'claim'
                              ? 'Request access review'
                              : 'Restore membership access'}
                    </button>
                </form>
            )}
            {reportSent ? (
                <p role="status" className="text-sm text-success-fg">
                    Your report was submitted for independent review. No membership or history has
                    been deleted.
                </p>
            ) : (
                <button
                    type="button"
                    disabled={busy}
                    onClick={reportWrongNumber}
                    className="text-sm text-accent underline"
                >
                    Report messages for someone else
                </button>
            )}
            {error && (
                <p role="alert" className="text-sm text-danger-fg">
                    {error}
                </p>
            )}
        </section>
    );
}
