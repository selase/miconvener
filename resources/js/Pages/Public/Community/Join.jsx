import { useState } from 'react';
import { communityRequest } from '@/lib/community';

export default function Join({
    identity,
    joinOptions,
    joinUrl,
    signInUrl = '/my',
    onSubmitted,
    initialRequests = [],
}) {
    const [name, setName] = useState('');
    const [channel, setChannel] = useState(identity?.email ? 'email' : 'sms');
    const [requestKey] = useState(() => crypto.randomUUID());
    const [result, setResult] = useState(initialRequests[0] ?? null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    async function submit(event) {
        event.preventDefault();
        setBusy(true);
        setError('');
        try {
            const data = await communityRequest(joinUrl, 'POST', {
                name,
                channel,
                request_key: requestKey,
            });
            setResult(data.request);
            onSubmitted?.(data.request);
        } catch (failure) {
            setError(failure.message);
        } finally {
            setBusy(false);
        }
    }
    if (result)
        return (
            <div
                role="status"
                className="space-y-2 rounded-lg border border-border bg-surface-hover p-4 text-sm text-ink"
            >
                <p className="font-medium">
                    {result.status === 'approved'
                        ? 'Your membership is approved.'
                        : result.status === 'rejected'
                          ? 'The organisation has declined this request.'
                          : 'Your request is awaiting approval.'}
                </p>
                <p>
                    {result.status === 'approved'
                        ? 'Open your Communities to see this membership and save any available recovery key.'
                        : result.status === 'rejected'
                          ? 'Contact the organisation if you need help with this decision.'
                          : 'No dues are charged while your request is pending. The organisation will review your request.'}
                </p>
                <a href={signInUrl} className="font-medium text-accent underline">
                    Open my Communities
                </a>
            </div>
        );
    if (!joinOptions?.enabled)
        return (
            <p className="text-sm text-ink-secondary">
                {joinOptions?.reason ?? 'The organisation has not opened public joining.'}
            </p>
        );
    if (!identity)
        return (
            <a
                href={signInUrl}
                className="inline-flex rounded-lg border border-border px-5 py-3 text-sm font-medium text-ink"
            >
                Verify to request membership
            </a>
        );
    const type = joinOptions.type;
    return (
        <form onSubmit={submit} className="space-y-4">
            {type && (
                <p className="text-sm text-ink-secondary">
                    {type.name}
                    {type.amount != null
                        ? ` · ${type.currency} ${(type.amount / 100).toFixed(2)}${type.period ? ` / ${type.period}` : ''}`
                        : ' · No dues'}
                </p>
            )}
            <label className="block text-sm font-medium text-ink">
                Your name
                <input
                    value={name}
                    onChange={(event) => setName(event.target.value)}
                    required
                    maxLength={160}
                    className="mt-2 block w-full rounded-md border border-border bg-surface px-3 py-2 text-ink"
                />
            </label>
            {identity.email && identity.phone && (
                <label className="block text-sm font-medium text-ink">
                    Verified contact
                    <select
                        className="mt-2 block w-full rounded-md border border-border bg-surface px-3 py-2 text-ink"
                        value={channel}
                        onChange={(event) => setChannel(event.target.value)}
                    >
                        <option value="email">Email · {identity.email.masked}</option>
                        <option value="sms">Phone · {identity.phone.masked}</option>
                    </select>
                </label>
            )}
            <p className="text-xs text-ink-secondary">
                {channel === 'sms'
                    ? 'Phone applications always await review to protect people with shared or reassigned numbers.'
                    : joinOptions.mode === 'immediate'
                      ? 'New memberships can be approved immediately; some requests need a separate review.'
                      : 'The organisation must approve your membership before dues or member benefits begin.'}{' '}
                Joining does not subscribe you to announcements.
            </p>
            <button
                disabled={busy}
                type="submit"
                className="rounded-lg bg-accent px-5 py-2.5 text-sm font-medium text-white disabled:opacity-50"
            >
                {busy ? 'Submitting…' : 'Request membership'}
            </button>
            {error && (
                <p role="alert" className="text-sm text-danger-fg">
                    {error}
                </p>
            )}
        </form>
    );
}
