import { useState } from 'react';
import { router } from '@inertiajs/react';
import PublicLayout from '@/Layouts/PublicLayout';
import { FileText, Check } from 'lucide-react';
import csrfFetch, { csrfFetchFormData } from '@/lib/csrfFetch';

function formatSessionTime(startsAt, endsAt) {
    const start = new Date(startsAt);
    const day = start.toLocaleDateString(undefined, { weekday: 'short' });
    const time = start.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
    return `${day}, ${time}`;
}

export default function SpeakerPortal({ event, token, speaker, sessions, slides }) {
    const [confirmed, setConfirmed] = useState(speaker.is_confirmed);
    const [file, setFile] = useState(null);
    const [uploading, setUploading] = useState(false);

    const respond = async (attending) => {
        await csrfFetch(route('public.events.speaker-portal.confirm', { event: event.slug, token }), {
            method: 'POST',
            body: JSON.stringify({ attending }),
        });
        setConfirmed(attending);
    };

    const uploadSlides = async (e) => {
        e.preventDefault();
        if (!file) return;
        setUploading(true);
        const body = new FormData();
        body.append('slides', file);
        await csrfFetchFormData(route('public.events.speaker-portal.slides', { event: event.slug, token }), body);
        setUploading(false);
        setFile(null);
        router.reload();
    };

    return (
        <PublicLayout>
            <div className="mx-auto max-w-2xl px-6 py-8 sm:px-10">
                <div className="mb-5">
                    <div className="text-xs uppercase tracking-wide text-ink-secondary">{event.name}</div>
                    <h1 className="mt-1 text-xl font-medium text-ink">Speaker portal</h1>
                </div>

                {confirmed === null && (
                    <div className="mb-6 flex items-center justify-between gap-4 border border-warning-fg/40 bg-warning-bg px-4 py-3.5">
                        <div>
                            <b className="text-[13.5px] text-ink">Are you still able to speak, {speaker.name}?</b>
                            <p className="mt-0.5 text-xs text-ink-secondary">Let us know so we can print the programme.</p>
                        </div>
                        <div className="flex shrink-0 gap-2">
                            <button onClick={() => respond(false)} className="inline-flex h-control items-center border border-border px-4 text-sm text-ink hover:border-accent">Can't make it</button>
                            <button onClick={() => respond(true)} className="inline-flex h-control items-center bg-accent px-4 text-sm text-accent-ink">I'll be there</button>
                        </div>
                    </div>
                )}

                {confirmed === true && (
                    <div className="mb-6 flex items-center gap-2.5 border border-accent bg-accent-soft px-4 py-3.5">
                        <Check className="h-4 w-4 text-accent" strokeWidth={2} />
                        <div>
                            <b className="text-[13.5px] text-ink">Thank you, you're confirmed</b>
                            <p className="text-xs text-ink-secondary">You now appear on the public programme.</p>
                        </div>
                        <button onClick={() => respond(false)} className="ml-auto shrink-0 text-xs text-ink-secondary underline">Change my answer</button>
                    </div>
                )}

                {confirmed === false && (
                    <div className="mb-6 flex items-center gap-2.5 border border-danger-fg/40 bg-danger-bg px-4 py-3.5">
                        <div>
                            <b className="text-[13.5px] text-ink">Marked as not attending</b>
                            <p className="text-xs text-ink-secondary">Let the organizers know if that changes.</p>
                        </div>
                        <button onClick={() => respond(true)} className="ml-auto shrink-0 text-xs text-ink-secondary underline">I can make it after all</button>
                    </div>
                )}

                <div className="grid gap-5 sm:grid-cols-2">
                    <div className="border border-border p-4">
                        <b className="text-sm font-medium text-ink">Your sessions</b>
                        {sessions.length > 0 ? (
                            <ul className="mt-3 space-y-3">
                                {sessions.map((s) => (
                                    <li key={s.id} className="border-b border-border pb-3 last:border-0 last:pb-0">
                                        <span className="font-mono text-[11px] text-ink-secondary">{formatSessionTime(s.starts_at, s.ends_at)}</span>
                                        <div className="mt-0.5 text-[13.5px] text-ink">{s.title}</div>
                                        {s.location && <div className="text-xs text-ink-secondary">{s.location}</div>}
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="mt-3 text-[13px] text-ink-secondary">No sessions assigned yet.</p>
                        )}
                    </div>

                    <div className="border border-border p-4">
                        <div className="flex items-center justify-between">
                            <b className="text-sm font-medium text-ink">Your slides</b>
                        </div>
                        <div className="mt-3 flex items-center gap-2.5 text-[13px] text-ink-secondary">
                            <FileText className="h-4 w-4 shrink-0" strokeWidth={1.5} />
                            {slides ? slides.title : 'No file uploaded yet'}
                        </div>
                        <form onSubmit={uploadSlides} className="mt-3 flex flex-col gap-2">
                            <input type="file" onChange={(e) => setFile(e.target.files[0] ?? null)} className="text-xs text-ink-secondary" />
                            <button type="submit" disabled={!file || uploading} className="inline-flex h-control w-fit items-center border border-border px-4 text-sm text-ink hover:border-accent disabled:opacity-60">
                                {uploading ? 'Uploading…' : slides ? 'Replace slides' : 'Upload slides'}
                            </button>
                        </form>
                        <p className="mt-2 text-xs text-ink-tertiary">Uploaded slides become downloadable by attendees automatically.</p>
                    </div>
                </div>
            </div>
        </PublicLayout>
    );
}
