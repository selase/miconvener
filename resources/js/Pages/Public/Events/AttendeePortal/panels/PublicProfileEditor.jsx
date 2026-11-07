import { useEffect, useId, useRef, useState } from 'react';
import csrfFetch, { csrfFetchFormData } from '@/lib/csrfFetch';

export default function PublicProfileEditor({ registrationId, isOnline = true }) {
    const id = useId();
    const [profile, setProfile] = useState(null);
    const [name, setName] = useState('');
    const [imageId, setImageId] = useState(null);
    const [preview, setPreview] = useState(null);
    useEffect(
        () => () => {
            if (preview) URL.revokeObjectURL(preview);
        },
        [preview]
    );
    const [adult, setAdult] = useState(false);
    const [consent, setConsent] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const operation = useRef(null);
    const endpoint = `/my/events/${registrationId}/public-profile`;
    useEffect(() => {
        if (!isOnline) return;
        const controller = new AbortController();
        csrfFetch(endpoint, { signal: controller.signal, cache: 'no-store' })
            .then(async (response) => {
                if (response.status === 401) {
                    setError('Verify your email again to choose a public profile.');
                    return;
                }
                if (!response.ok) return;
                const data = await response.json();
                if (!controller.signal.aborted) {
                    setProfile(data.profile);
                    setName(data.profile.display_name || '');
                    setImageId(data.profile.image?.id || null);
                }
            })
            .catch(() => {});
        return () => controller.abort();
    }, [endpoint, isOnline]);
    const changed = () => {
        setAdult(false);
        setConsent(false);
        operation.current = null;
    };
    const handlePhoto = async (file) => {
        if (!file) return;
        setBusy(true);
        setError('');
        const form = new FormData();
        form.append('photo', file);
        try {
            const response = await csrfFetchFormData(`${endpoint}/photo`, form, {
                cache: 'no-store',
            });
            const data = await response.json();
            if (!response.ok)
                throw new Error(data.errors?.photo?.[0] || data.message || 'Photo upload failed.');
            setPreview(URL.createObjectURL(file));
            setImageId(data.image.id);
            changed();
        } catch (failure) {
            setError(failure.message || 'Your photo could not be uploaded.');
        } finally {
            setBusy(false);
        }
    };
    const save = async (event) => {
        event.preventDefault();
        if (!adult || !consent || !name.trim() || busy) return;
        operation.current ||= crypto.randomUUID();
        setBusy(true);
        setError('');
        try {
            const response = await csrfFetch(endpoint, {
                method: 'POST',
                cache: 'no-store',
                body: JSON.stringify({
                    display_name: name.trim(),
                    image_id: imageId,
                    adult,
                    consent,
                    revision: profile.revision,
                    operation_key: operation.current,
                }),
            });
            const data = await response.json();
            if (!response.ok)
                throw new Error(
                    Object.values(data.errors || {})[0]?.[0] ||
                        data.message ||
                        'Could not publish. Reload and try again.'
                );
            setProfile(data.profile);
            setAdult(false);
            setConsent(false);
            operation.current = null;
        } catch (failure) {
            setError(failure.message || 'Could not connect. You can retry this choice.');
        } finally {
            setBusy(false);
        }
    };
    const remove = async () => {
        setBusy(true);
        setError('');
        try {
            const response = await csrfFetch(endpoint, { method: 'DELETE', cache: 'no-store' });
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || 'Could not remove your profile.');
            setProfile(data.profile);
            setName('');
            setPreview(null);
            setImageId(null);
            changed();
        } catch (failure) {
            setError(failure.message);
        } finally {
            setBusy(false);
        }
    };
    if (!isOnline) return null;
    if (error && !profile)
        return (
            <p className="border border-border p-4 text-sm text-ink-secondary" role="status">
                {error}{' '}
                <a
                    className="underline"
                    href={`/my?return_to=${encodeURIComponent(`/my/events/${registrationId}`)}`}
                >
                    Verify email
                </a>
            </p>
        );
    if (!profile?.eligible || profile.state === 'hidden') return null;
    return (
        <section
            className="border border-border bg-surface p-5 text-ink"
            aria-labelledby={`${id}-title`}
        >
            <h2 id={`${id}-title`} className="text-lg font-semibold">
                Your public attendee profile
            </h2>
            <p className="mt-2 text-sm text-ink-secondary">
                Optional. Your ticket works without a public profile. Only the name you choose and
                an optional photo appear in this event’s Going section.
            </p>
            {profile.state === 'published' && (
                <p className="mt-3 text-sm" role="status">
                    Published
                    {profile.expires_at
                        ? ` until ${new Date(profile.expires_at).toLocaleDateString()}`
                        : ''}
                    . You can remove it at any time.
                </p>
            )}
            <form onSubmit={save} className="mt-4 grid gap-4">
                <label className="grid gap-2" htmlFor={`${id}-name`}>
                    Public display name
                    <input
                        id={`${id}-name`}
                        value={name}
                        maxLength={80}
                        onChange={(event) => {
                            setName(event.target.value);
                            changed();
                        }}
                        className="min-h-control w-full border border-border bg-surface px-3 py-2 text-ink"
                    />
                </label>
                <label className="grid gap-2" htmlFor={`${id}-photo`}>
                    Optional photo
                    <input
                        id={`${id}-photo`}
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        disabled={busy}
                        onChange={(event) => handlePhoto(event.target.files?.[0])}
                        className="w-full text-sm"
                    />
                    <span className="text-xs text-ink-secondary">
                        Static JPEG, PNG or WebP, up to 2 MiB. Photos are cropped square.
                    </span>
                </label>
                <div
                    className="flex min-w-0 items-center gap-3 border border-border p-3"
                    aria-label="Profile preview"
                >
                    <span
                        className="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden bg-accent/10 text-accent"
                        aria-hidden="true"
                    >
                        {imageId && (preview || profile.image?.url) ? (
                            <img
                                className="h-full w-full object-cover"
                                src={preview || profile.image.url}
                                alt=""
                            />
                        ) : (
                            name
                                .trim()
                                .split(/\s+/)
                                .slice(0, 2)
                                .map((word) => word[0])
                                .join('')
                        )}
                    </span>
                    <span className="break-words">
                        {name || 'Your chosen name'}
                        {imageId && (
                            <small className="block text-ink-secondary">Photo selected</small>
                        )}
                    </span>
                </div>
                {imageId && (
                    <button
                        type="button"
                        className="justify-self-start py-2 text-sm underline"
                        onClick={() => {
                            setImageId(null);
                            changed();
                        }}
                    >
                        Use initials instead
                    </button>
                )}
                <label className="flex items-start gap-3">
                    <input
                        type="checkbox"
                        checked={adult}
                        onChange={(event) => setAdult(event.target.checked)}
                        className="mt-1"
                    />
                    I am 18 or older and I am publishing only my own profile.
                </label>
                <label className="flex items-start gap-3">
                    <input
                        type="checkbox"
                        checked={consent}
                        onChange={(event) => setConsent(event.target.checked)}
                        className="mt-1"
                    />
                    I agree to publish this name and optional photo publicly for this event, until
                    30 days after it ends or 90 days from my consent, whichever comes first.
                </label>
                <p className="text-xs text-ink-secondary">
                    Removal hides your profile immediately. Images are queued for deletion within 24
                    hours; minimal consent evidence is retained for 90 days after removal.
                </p>
                <p className="text-xs text-ink-secondary">
                    Anyone can view or copy your public name and photo. Removing your profile cannot
                    recall copies that others have saved.
                </p>
                {error && (
                    <p role="alert" className="text-sm text-danger">
                        {error}
                    </p>
                )}
                <div className="flex flex-wrap gap-3">
                    <button
                        disabled={busy || !name.trim() || !adult || !consent}
                        className="min-h-control bg-accent px-4 py-2 font-medium text-accent-ink disabled:opacity-50"
                        type="submit"
                    >
                        {busy ? 'Saving…' : 'Publish my profile'}
                    </button>
                    {profile.state === 'published' && (
                        <button
                            type="button"
                            disabled={busy}
                            onClick={remove}
                            className="min-h-control border border-border px-4 py-2"
                        >
                            Remove my public profile
                        </button>
                    )}
                </div>
            </form>
        </section>
    );
}
