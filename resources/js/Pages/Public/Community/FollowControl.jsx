import { useState } from 'react';
import { communityRequest } from '@/lib/community';

export default function FollowControl({
    identity,
    initialFollowing = {},
    followUrl,
    signInUrl = '/my',
    sourceEventId = null,
}) {
    const [channel, setChannel] = useState('email');
    const [following, setFollowing] = useState(initialFollowing);
    const [consent, setConsent] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    if (!identity?.email)
        return (
            <a
                href={signInUrl}
                className="inline-flex rounded-lg bg-accent px-5 py-3 text-sm font-medium text-white"
            >
                Verify email to follow
            </a>
        );
    const active = Boolean(following[channel]);
    async function submit() {
        setBusy(true);
        setError('');
        try {
            const data = await communityRequest(followUrl, active ? 'DELETE' : 'POST', {
                channel,
                resume_marketing: consent,
                ...(sourceEventId ? { source_event_id: sourceEventId } : {}),
            });
            setFollowing({ ...following, [channel]: data.following, ...(channel === 'email' && !data.following ? {sms:false} : {}) });
            setConsent(false);
        } catch (failure) {
            setError(failure.message);
        } finally {
            setBusy(false);
        }
    }
    return (
        <section className="space-y-4" aria-label="Organisation announcements">
            <label className="block text-sm font-medium text-ink">
                Verified contact
                <select
                    className="mt-2 block w-full rounded-md border border-border bg-surface px-3 py-2 text-sm text-ink"
                    value={channel}
                    onChange={(event) => {
                        setChannel(event.target.value);
                        setConsent(false);
                        setError('');
                    }}
                >
                    {identity.email && (
                        <option value="email">Email · {identity.email.masked}</option>
                    )}
                    {identity.phone && following.email && <option value="sms">Phone · {identity.phone.masked} (optional SMS)</option>}
                </select>
            </label>
            {!active && (
                <label className="flex items-start gap-2 text-sm text-ink">
                    <input
                        className="mt-1"
                        type="checkbox"
                        checked={consent}
                        onChange={(event) => setConsent(event.target.checked)}
                    />
                    Send me announcements at this verified address.
                </label>
            )}
            <button
                type="button"
                disabled={busy || (!active && !consent)}
                onClick={submit}
                className="rounded-lg bg-accent px-5 py-2.5 text-sm font-medium text-white disabled:opacity-50"
            >
                {busy ? 'Saving…' : active ? 'Unfollow' : 'Follow organisation'}
            </button>
            <p className="text-xs text-ink-secondary">
                Following keeps you informed. It does not create a membership. You can stop
                announcements at any time; receipts and service notices are separate.
            </p>
            {error && (
                <p role="alert" className="text-sm text-danger-fg">
                    {error}{' '}
                    <a className="underline" href={signInUrl}>
                        Verify again
                    </a>
                </p>
            )}
        </section>
    );
}
