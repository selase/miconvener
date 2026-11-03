import { useEffect, useState } from 'react';
import csrfFetch from '@/lib/csrfFetch';
export default function PublicProfilesPanel({ eventId }) {
    const [data, setData] = useState(null);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [reasons, setReasons] = useState({});
    const endpoint = `/events/${eventId}/public-profiles`;
    const refresh = async () => {
        const response = await csrfFetch(endpoint, { cache: 'no-store' });
        if (response.ok) setData(await response.json());
    };
    useEffect(() => {
        let active = true;
        csrfFetch(endpoint, { cache: 'no-store' })
            .then(async (response) => {
                if (response.ok && active) setData(await response.json());
            })
            .catch(() => {});
        return () => {
            active = false;
        };
    }, [endpoint]);
    const change = async (url, method, body) => {
        setBusy(true);
        setError('');
        try {
            const response = await csrfFetch(url, {
                method,
                body: JSON.stringify(body),
                cache: 'no-store',
            });
            const result = await response.json();
            if (!response.ok)
                throw new Error(
                    Object.values(result.errors || {})[0]?.[0] ||
                        result.message ||
                        'Could not save.'
                );
            await refresh();
        } catch (failure) {
            setError(failure.message);
        } finally {
            setBusy(false);
        }
    };
    if (!data) return null;
    return (
        <section className="border border-border bg-surface p-5 text-ink">
            <h2 className="text-lg font-semibold">Public attendee profiles</h2>
            <p className="mt-2 text-sm text-ink-secondary">
                Attendees choose whether to publish themselves. Staff can hide a profile, never
                publish one. Turning this off revokes existing consent; turning it back on requires
                attendees to consent again.
            </p>
            <label className="mt-4 flex items-start gap-3">
                <input
                    type="checkbox"
                    checked={data.enabled}
                    disabled={busy}
                    onChange={(event) =>
                        change(endpoint, 'PATCH', { enabled: event.target.checked })
                    }
                />
                Offer optional public profiles for this event
            </label>
            {data.profiles.map((profile) => (
                <div key={profile.id} className="mt-4 border-t border-border pt-4">
                    <p>
                        {profile.display_name || 'Hidden attendee profile'} · {profile.state}
                    </p>
                    {profile.state !== 'hidden' && (
                        <form
                            className="mt-2 flex flex-wrap gap-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                change(`${endpoint}/${profile.id}/hide`, 'POST', {
                                    reason: reasons[profile.id] || '',
                                });
                            }}
                        >
                            <label className="grid flex-1 gap-1 text-sm">
                                Reason for hiding
                                <input
                                    required
                                    minLength={3}
                                    maxLength={240}
                                    className="min-h-control border border-border bg-surface px-3 text-ink"
                                    value={reasons[profile.id] || ''}
                                    onChange={(event) =>
                                        setReasons((previous) => ({
                                            ...previous,
                                            [profile.id]: event.target.value,
                                        }))
                                    }
                                />
                            </label>
                            <button
                                disabled={busy}
                                className="min-h-control self-end border border-border px-4 py-2"
                                type="submit"
                            >
                                Hide profile
                            </button>
                        </form>
                    )}
                </div>
            ))}
            {error && (
                <p role="alert" className="mt-3 text-danger">
                    {error}
                </p>
            )}
        </section>
    );
}
