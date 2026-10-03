import { useState, useRef } from 'react';
import PublicLayout from '@/Layouts/PublicLayout';
import {
    FileText,
    Check,
    Calendar,
    MapPin,
    Upload,
    Save,
    Clock,
    AlertCircle,
    Loader2,
} from 'lucide-react';
import csrfFetch, { csrfFetchFormData } from '@/lib/csrfFetch';

function formatSessionTime(startsAt, endsAt) {
    const start = new Date(startsAt);
    const end = endsAt ? new Date(endsAt) : null;
    const day = start.toLocaleDateString(undefined, {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
    });
    const startTime = start.toLocaleTimeString(undefined, {
        hour: 'numeric',
        minute: '2-digit',
    });
    const endTime = end
        ? end.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })
        : null;

    return endTime ? `${day} • ${startTime} – ${endTime}` : `${day} • ${startTime}`;
}

function formatBytes(bytes) {
    if (!bytes || bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return `${parseFloat((bytes / Math.pow(k, i)).toFixed(1))} ${sizes[i]}`;
}

export default function SpeakerPortal({ event, token, speaker, sessions, slides }) {
    const [confirmed, setConfirmed] = useState(speaker.is_confirmed);
    const [confirming, setConfirming] = useState(false);

    // Profile state
    const [profile, setProfile] = useState({
        name: speaker.name || '',
        title: speaker.title || '',
        organization: speaker.organization || '',
        bio: speaker.bio || '',
        website_url: speaker.website_url || '',
        linkedin_url: speaker.linkedin_url || '',
        twitter_url: speaker.twitter_url || '',
    });
    const [photoUrl, setPhotoUrl] = useState(speaker.photo_url || null);
    const [savingProfile, setSavingProfile] = useState(false);
    const [profileSavedMessage, setProfileSavedMessage] = useState(null);

    // Photo upload state
    const [uploadingPhoto, setUploadingPhoto] = useState(false);
    const photoInputRef = useRef(null);

    // Slides state
    const [slideFile, setSlideFile] = useState(null);
    const [uploadingSlides, setUploadingSlides] = useState(false);
    const [currentSlides, setCurrentSlides] = useState(slides);
    const [slidesMessage, setSlidesMessage] = useState(null);
    const slideInputRef = useRef(null);

    const respondAttendance = async (attending) => {
        setConfirming(true);
        try {
            const res = await csrfFetch(
                route('public.events.speaker-portal.confirm', { event: event.slug, token }),
                {
                    method: 'POST',
                    body: JSON.stringify({ attending }),
                }
            );
            if (res.ok) {
                setConfirmed(attending);
            }
        } finally {
            setConfirming(false);
        }
    };

    const handlePhotoUpload = async (e) => {
        const file = e.target.files?.[0];
        if (!file) return;

        setUploadingPhoto(true);
        try {
            const body = new FormData();
            body.append('photo', file);
            const res = await csrfFetchFormData(
                route('public.events.speaker-portal.photo', { event: event.slug, token }),
                body
            );
            const data = await res.json();
            if (res.ok && data.photo_url) {
                setPhotoUrl(data.photo_url);
            }
        } finally {
            setUploadingPhoto(false);
        }
    };

    const handleSaveProfile = async (e) => {
        e.preventDefault();
        setSavingProfile(true);
        setProfileSavedMessage(null);

        try {
            const res = await csrfFetch(
                route('public.events.speaker-portal.profile', { event: event.slug, token }),
                {
                    method: 'POST',
                    body: JSON.stringify(profile),
                }
            );
            const data = await res.json();
            if (res.ok) {
                setProfileSavedMessage('Profile updated successfully.');
                setTimeout(() => setProfileSavedMessage(null), 4000);
            }
        } finally {
            setSavingProfile(false);
        }
    };

    const handleUploadSlides = async (e) => {
        e.preventDefault();
        if (!slideFile) return;

        setUploadingSlides(true);
        setSlidesMessage(null);

        try {
            const body = new FormData();
            body.append('slides', slideFile);
            const res = await csrfFetchFormData(
                route('public.events.speaker-portal.slides', { event: event.slug, token }),
                body
            );
            const data = await res.json();
            if (res.ok) {
                setCurrentSlides(data.slides);
                setSlideFile(null);
                if (slideInputRef.current) slideInputRef.current.value = '';
                setSlidesMessage('Slides uploaded successfully.');
                setTimeout(() => setSlidesMessage(null), 4000);
            }
        } finally {
            setUploadingSlides(false);
        }
    };

    return (
        <PublicLayout>
            <div className="mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8">
                {/* Header */}
                <div className="mb-6 border-b border-border pb-5">
                    <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <span className="text-xs font-semibold uppercase tracking-wider text-ink-secondary">
                                Speaker Portal
                            </span>
                            <h1 className="text-2xl font-bold text-ink">{event.name}</h1>
                        </div>
                        {event.starts_at && (
                            <div className="flex items-center gap-1.5 text-xs text-ink-secondary">
                                <Calendar className="h-3.5 w-3.5" />
                                <span>{formatSessionTime(event.starts_at, event.ends_at)}</span>
                            </div>
                        )}
                    </div>
                </div>

                {/* Attendance Status Banner */}
                {confirmed === null && (
                    <div className="mb-8 flex flex-col gap-4 rounded-xl border border-warning-fg/40 bg-warning-bg/40 p-5 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 className="text-sm font-semibold text-ink">
                                Are you able to speak, {profile.name || speaker.name}?
                            </h2>
                            <p className="mt-0.5 text-xs text-ink-secondary">
                                Confirming early helps the organizers finalize the event programme.
                            </p>
                        </div>
                        <div className="flex shrink-0 items-center gap-2.5">
                            <button
                                type="button"
                                onClick={() => respondAttendance(false)}
                                disabled={confirming}
                                className="inline-flex h-9 items-center rounded-lg border border-border bg-surface px-4 text-xs font-medium text-ink hover:border-danger-fg/40 hover:text-danger-fg disabled:opacity-50"
                            >
                                Can't make it
                            </button>
                            <button
                                type="button"
                                onClick={() => respondAttendance(true)}
                                disabled={confirming}
                                className="inline-flex h-9 items-center rounded-lg bg-accent px-4 text-xs font-medium text-accent-ink hover:opacity-95 disabled:opacity-50"
                            >
                                {confirming ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : "I'll be there"}
                            </button>
                        </div>
                    </div>
                )}

                {confirmed === true && (
                    <div className="mb-8 flex items-center justify-between rounded-xl border border-accent/40 bg-accent-soft p-4">
                        <div className="flex items-center gap-3">
                            <div className="grid h-8 w-8 place-items-center rounded-full bg-accent text-accent-ink">
                                <Check className="h-4 w-4" strokeWidth={2.5} />
                            </div>
                            <div>
                                <h2 className="text-sm font-semibold text-ink">Thank you, your participation is confirmed!</h2>
                                <p className="text-xs text-ink-secondary">
                                    Your profile appears in the event schedule and directory.
                                </p>
                            </div>
                        </div>
                        <button
                            type="button"
                            onClick={() => respondAttendance(false)}
                            className="text-xs text-ink-secondary underline hover:text-danger-fg"
                        >
                            Change to not attending
                        </button>
                    </div>
                )}

                {confirmed === false && (
                    <div className="mb-8 flex items-center justify-between rounded-xl border border-danger-fg/30 bg-danger-bg/40 p-4">
                        <div className="flex items-center gap-3">
                            <div className="grid h-8 w-8 place-items-center rounded-full bg-danger-bg text-danger-fg border border-danger-fg/30">
                                <AlertCircle className="h-4 w-4" />
                            </div>
                            <div>
                                <h2 className="text-sm font-semibold text-ink">Marked as not attending</h2>
                                <p className="text-xs text-ink-secondary">
                                    If your availability changes, please update your status below.
                                </p>
                            </div>
                        </div>
                        <button
                            type="button"
                            onClick={() => respondAttendance(true)}
                            className="text-xs font-medium text-accent underline hover:opacity-80"
                        >
                            I can make it after all
                        </button>
                    </div>
                )}

                <div className="grid gap-8 lg:grid-cols-3">
                    {/* Left Column: Speaker Profile & Headshot */}
                    <div className="space-y-6 lg:col-span-2">
                        {/* Profile Edit Card */}
                        <div className="rounded-xl border border-border bg-surface p-5 sm:p-6 shadow-xs">
                            <div className="mb-4">
                                <h2 className="text-base font-semibold text-ink">Speaker Profile & Headshot</h2>
                                <p className="text-xs text-ink-secondary">
                                    This information appears on public event pages and marketing materials.
                                </p>
                            </div>

                            {/* Headshot Upload Section */}
                            <div className="mb-6 flex items-center gap-4 border-b border-border pb-6">
                                <div className="relative">
                                    {photoUrl ? (
                                        <img
                                            src={photoUrl}
                                            alt={profile.name}
                                            className="h-20 w-20 rounded-full border border-border object-cover shadow-xs"
                                        />
                                    ) : (
                                        <div className="grid h-20 w-20 place-items-center rounded-full border border-dashed border-border bg-surface-alt text-base font-semibold text-ink-secondary">
                                            {profile.name
                                                .split(' ')
                                                .map((w) => w[0])
                                                .slice(0, 2)
                                                .join('')
                                                .toUpperCase() || 'SP'}
                                        </div>
                                    )}
                                    {uploadingPhoto && (
                                        <div className="absolute inset-0 grid place-items-center rounded-full bg-black/40">
                                            <Loader2 className="h-5 w-5 animate-spin text-white" />
                                        </div>
                                    )}
                                </div>

                                <div>
                                    <label className="block text-xs font-medium text-ink">Headshot Photograph</label>
                                    <input
                                        ref={photoInputRef}
                                        type="file"
                                        accept="image/jpeg,image/png,image/webp"
                                        onChange={handlePhotoUpload}
                                        disabled={uploadingPhoto}
                                        className="mt-1 block text-xs text-ink-secondary file:mr-2.5 file:rounded-md file:border-0 file:bg-surface-alt file:px-3 file:py-1.5 file:text-xs file:font-medium file:text-ink hover:file:bg-surface-hover"
                                    />
                                    <p className="mt-1 text-[11px] text-ink-tertiary">
                                        JPEG, PNG or WebP. Square aspect ratio recommended (max 5MB).
                                    </p>
                                </div>
                            </div>

                            {/* Profile Details Form */}
                            <form onSubmit={handleSaveProfile} className="space-y-4">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <label className="mb-1 block text-xs font-medium text-ink">Full Name *</label>
                                        <input
                                            type="text"
                                            value={profile.name}
                                            onChange={(e) => setProfile({ ...profile, name: e.target.value })}
                                            required
                                            className="w-full rounded-lg border border-border px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                                        />
                                    </div>
                                    <div>
                                        <label className="mb-1 block text-xs font-medium text-ink">Title / Designation</label>
                                        <input
                                            type="text"
                                            value={profile.title}
                                            onChange={(e) => setProfile({ ...profile, title: e.target.value })}
                                            placeholder="e.g. Chief Executive Officer"
                                            className="w-full rounded-lg border border-border px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                                        />
                                    </div>
                                </div>

                                <div>
                                    <label className="mb-1 block text-xs font-medium text-ink">Organization / Affiliation</label>
                                    <input
                                        type="text"
                                        value={profile.organization}
                                        onChange={(e) => setProfile({ ...profile, organization: e.target.value })}
                                        placeholder="e.g. Google DeepMind"
                                        className="w-full rounded-lg border border-border px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                                    />
                                </div>

                                <div>
                                    <label className="mb-1 block text-xs font-medium text-ink">Speaker Biography</label>
                                    <textarea
                                        value={profile.bio}
                                        onChange={(e) => setProfile({ ...profile, bio: e.target.value })}
                                        rows={4}
                                        placeholder="Write a brief overview of your background, experience, and keynote focus..."
                                        className="w-full rounded-lg border border-border px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                                    />
                                </div>

                                <div className="grid gap-3 sm:grid-cols-3">
                                    <div>
                                        <label className="mb-1 block text-xs font-medium text-ink">Website</label>
                                        <input
                                            type="url"
                                            value={profile.website_url}
                                            onChange={(e) => setProfile({ ...profile, website_url: e.target.value })}
                                            placeholder="https://..."
                                            className="w-full rounded-lg border border-border px-3 py-1.5 text-xs text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                                        />
                                    </div>
                                    <div>
                                        <label className="mb-1 block text-xs font-medium text-ink">LinkedIn</label>
                                        <input
                                            type="url"
                                            value={profile.linkedin_url}
                                            onChange={(e) => setProfile({ ...profile, linkedin_url: e.target.value })}
                                            placeholder="https://linkedin.com/in/..."
                                            className="w-full rounded-lg border border-border px-3 py-1.5 text-xs text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                                        />
                                    </div>
                                    <div>
                                        <label className="mb-1 block text-xs font-medium text-ink">Twitter / X</label>
                                        <input
                                            type="url"
                                            value={profile.twitter_url}
                                            onChange={(e) => setProfile({ ...profile, twitter_url: e.target.value })}
                                            placeholder="https://x.com/..."
                                            className="w-full rounded-lg border border-border px-3 py-1.5 text-xs text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                                        />
                                    </div>
                                </div>

                                <div className="flex items-center justify-between pt-2">
                                    {profileSavedMessage ? (
                                        <span className="flex items-center gap-1.5 text-xs font-medium text-accent">
                                            <Check className="h-3.5 w-3.5" />
                                            {profileSavedMessage}
                                        </span>
                                    ) : <div />}

                                    <button
                                        type="submit"
                                        disabled={savingProfile}
                                        className="inline-flex h-9 items-center gap-2 rounded-lg bg-accent px-4 text-xs font-medium text-accent-ink hover:opacity-95 disabled:opacity-50"
                                    >
                                        {savingProfile ? (
                                            <Loader2 className="h-3.5 w-3.5 animate-spin" />
                                        ) : (
                                            <Save className="h-3.5 w-3.5" />
                                        )}
                                        <span>Save Profile</span>
                                    </button>
                                </div>
                            </form>
                        </div>

                        {/* Presentation Slides Card */}
                        <div className="rounded-xl border border-border bg-surface p-5 sm:p-6 shadow-xs">
                            <div className="mb-4">
                                <h2 className="text-base font-semibold text-ink">Presentation Slides & Materials</h2>
                                <p className="text-xs text-ink-secondary">
                                    Upload your slide deck (PDF, PowerPoint, Keynote) to make it available to the production AV team and attendees.
                                </p>
                            </div>

                            {currentSlides ? (
                                <div className="mb-4 flex items-center justify-between rounded-lg border border-border bg-surface-alt/50 p-3.5">
                                    <div className="flex items-center gap-3">
                                        <div className="grid h-9 w-9 place-items-center rounded-lg bg-accent-soft text-accent">
                                            <FileText className="h-5 w-5" />
                                        </div>
                                        <div>
                                            <div className="text-sm font-medium text-ink">{currentSlides.title}</div>
                                            <div className="text-xs text-ink-tertiary">
                                                {formatBytes(currentSlides.file_size)}
                                            </div>
                                        </div>
                                    </div>
                                    <span className="rounded bg-accent-soft px-2 py-0.5 text-[11px] font-medium text-accent">
                                        Active
                                    </span>
                                </div>
                            ) : (
                                <div className="mb-4 rounded-lg border border-dashed border-border p-4 text-center text-xs text-ink-secondary">
                                    No presentation slides uploaded yet.
                                </div>
                            )}

                            <form onSubmit={handleUploadSlides} className="space-y-3">
                                <div>
                                    <input
                                        ref={slideInputRef}
                                        type="file"
                                        accept=".pdf,.ppt,.pptx,.key,.zip"
                                        onChange={(e) => setSlideFile(e.target.files?.[0] ?? null)}
                                        className="block w-full text-xs text-ink-secondary file:mr-2.5 file:rounded-md file:border-0 file:bg-surface-alt file:px-3 file:py-1.5 file:text-xs file:font-medium file:text-ink hover:file:bg-surface-hover"
                                    />
                                    <p className="mt-1 text-[11px] text-ink-tertiary">
                                        Max file size: 20MB. Supported formats: PDF, PPTX, Keynote, ZIP.
                                    </p>
                                </div>

                                <div className="flex items-center justify-between pt-1">
                                    {slidesMessage ? (
                                        <span className="flex items-center gap-1.5 text-xs font-medium text-accent">
                                            <Check className="h-3.5 w-3.5" />
                                            {slidesMessage}
                                        </span>
                                    ) : <div />}

                                    <button
                                        type="submit"
                                        disabled={!slideFile || uploadingSlides}
                                        className="inline-flex h-9 items-center gap-2 rounded-lg bg-accent px-4 text-xs font-medium text-accent-ink hover:opacity-95 disabled:opacity-50"
                                    >
                                        {uploadingSlides ? (
                                            <Loader2 className="h-3.5 w-3.5 animate-spin" />
                                        ) : (
                                            <Upload className="h-3.5 w-3.5" />
                                        )}
                                        <span>{currentSlides ? 'Replace Slides' : 'Upload Slides'}</span>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    {/* Right Column: Assigned Sessions */}
                    <div className="space-y-6">
                        <div className="rounded-xl border border-border bg-surface p-5 sm:p-6 shadow-xs">
                            <h2 className="mb-3 text-base font-semibold text-ink">Assigned Sessions ({sessions.length})</h2>
                            <p className="mb-4 text-xs text-ink-secondary">
                                Your scheduled speaking sessions for this event.
                            </p>

                            {sessions.length > 0 ? (
                                <div className="space-y-4">
                                    {sessions.map((s) => (
                                        <div
                                            key={s.id}
                                            className="rounded-lg border border-border bg-surface-alt/30 p-3.5"
                                        >
                                            <div className="flex items-center gap-1.5 text-xs font-medium text-accent">
                                                <Clock className="h-3.5 w-3.5" />
                                                <span>{formatSessionTime(s.starts_at, s.ends_at)}</span>
                                            </div>

                                            <h3 className="mt-1.5 text-sm font-semibold text-ink">{s.title}</h3>

                                            {s.location && (
                                                <div className="mt-1 flex items-center gap-1.5 text-xs text-ink-secondary">
                                                    <MapPin className="h-3.5 w-3.5 shrink-0" />
                                                    <span>{s.location}</span>
                                                </div>
                                            )}

                                            {s.description && (
                                                <p className="mt-2 text-xs text-ink-secondary line-clamp-3">
                                                    {s.description}
                                                </p>
                                            )}

                                            {/* Co-speakers */}
                                            {s.co_speakers && s.co_speakers.length > 0 && (
                                                <div className="mt-3 border-t border-border pt-2.5">
                                                    <span className="text-[11px] font-medium text-ink-tertiary">
                                                        Co-speakers:
                                                    </span>
                                                    <div className="mt-1 flex flex-wrap gap-2">
                                                        {s.co_speakers.map((cs) => (
                                                            <div
                                                                key={cs.id}
                                                                className="flex items-center gap-1.5 rounded-full bg-surface px-2 py-0.5 border border-border"
                                                            >
                                                                {cs.photo_url ? (
                                                                    <img
                                                                        src={cs.photo_url}
                                                                        alt={cs.name}
                                                                        className="h-4 w-4 rounded-full object-cover"
                                                                    />
                                                                ) : (
                                                                    <div className="grid h-4 w-4 place-items-center rounded-full bg-surface-alt text-[9px] font-bold text-ink-secondary">
                                                                        {cs.name[0]}
                                                                    </div>
                                                                )}
                                                                <span className="text-[11px] text-ink">{cs.name}</span>
                                                            </div>
                                                        ))}
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <p className="text-xs text-ink-secondary">
                                    No speaking sessions have been assigned to you yet. Check back soon or contact the organizers.
                                </p>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </PublicLayout>
    );
}
