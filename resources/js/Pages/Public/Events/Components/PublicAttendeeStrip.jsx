import { useEffect, useState } from 'react';

export default function PublicAttendeeStrip({ eventId, url }) {
    const endpoint = url || `/attendee-profiles/events/${eventId}`;
    const [profiles, setProfiles] = useState([]);
    const [next, setNext] = useState(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    useEffect(() => {
        setProfiles([]);
        setNext(null);
        setError('');
        const controller = new AbortController();
        fetch(endpoint, {
            cache: 'no-store',
            signal: controller.signal,
            headers: { Accept: 'application/json' },
        })
            .then(async (response) => {
                if (!response.ok) return;
                const data = await response.json();
                if (!controller.signal.aborted) {
                    setProfiles(data.profiles);
                    setNext(data.next);
                }
            })
            .catch(() => {});
        return () => controller.abort();
    }, [endpoint]);
    const more = async () => {
        setBusy(true);
        setError('');
        try {
            const response = await fetch(
                `${endpoint}?expanded=1&after=${encodeURIComponent(next)}`,
                { cache: 'no-store', headers: { Accept: 'application/json' } }
            );
            if (!response.ok) throw new Error();
            const data = await response.json();
            setProfiles((previous) => [...previous, ...data.profiles]);
            setNext(data.next);
        } catch {
            setError('Could not load more attendees. Try again.');
        } finally {
            setBusy(false);
        }
    };
    if (!profiles.length) return null;
    return (
        <section className="mb-8 border-b border-border pb-5" aria-label="Public attendees">
            <h3 className="text-sm font-medium text-ink">Public attendees</h3>
            <p className="mt-1 text-xs text-ink-secondary">
                These attendees chose to share their profiles. Others remain private.
            </p>
            <ul className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
                {profiles.map((profile) => (
                    <li key={profile.id} className="flex min-w-0 items-center gap-2">
                        <span className="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden bg-accent/10 text-xs text-accent">
                            {profile.photo_url ? (
                                <img
                                    src={profile.photo_url}
                                    alt=""
                                    className="h-full w-full object-cover"
                                    loading="lazy"
                                />
                            ) : (
                                profile.initials
                            )}
                        </span>
                        <span className="min-w-0 break-words text-sm text-ink">
                            {profile.display_name}
                        </span>
                    </li>
                ))}
            </ul>
            {next && (
                <button
                    type="button"
                    onClick={more}
                    disabled={busy}
                    className="mt-4 min-h-control py-2 text-sm text-accent underline"
                >
                    {busy ? 'Loading…' : 'See more public attendees'}
                </button>
            )}
            {error && (
                <p role="status" className="text-sm text-ink-secondary">
                    {error}
                </p>
            )}
        </section>
    );
}
