import FollowControl from '@/Pages/Public/Community/FollowControl';
import { useEffect, useId, useMemo, useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import {
    Lock,
    User,
    Download,
    Trophy,
    ChevronDown,
    ChevronUp,
    Ticket,
    Heart,
    Repeat,
    BookOpen,
    ExternalLink,
    X,
    Copy,
    Check,
    Globe,
    Share2,
    CalendarDays,
    MapPin,
    Users,
} from 'lucide-react';
import PublicLayout from '@/Layouts/PublicLayout';
import CoverBars from '@/Components/Console/CoverBars';
import Input from '@/Components/Console/Input';
import Button from '@/Components/Console/Button';
import Modal from '@/Components/Console/Modal';
import ContributionWidget from '@/Components/Events/ContributionWidget';
import csrfFetch, { csrfFetchFormData } from '@/lib/csrfFetch';
import respondentToken from '@/lib/respondentToken';

function formatDateRange(startsAt, endsAt, timezone) {
    const sameDay =
        new Date(startsAt).toLocaleDateString('en-CA', { timeZone: timezone }) ===
        new Date(endsAt).toLocaleDateString('en-CA', { timeZone: timezone });
    const dayOpts = { timeZone: timezone, day: 'numeric', month: 'short', year: 'numeric' };
    const start = new Date(startsAt).toLocaleDateString(undefined, dayOpts);
    const end = new Date(endsAt).toLocaleDateString(undefined, dayOpts);
    return sameDay ? start : `${start} – ${end}`;
}

function formatSessionTime(startsAt, endsAt, timezone) {
    const dateOpts = { timeZone: timezone, weekday: 'short', month: 'short', day: 'numeric' };
    const timeOpts = { timeZone: timezone, hour: 'numeric', minute: '2-digit' };
    const date = new Date(startsAt).toLocaleDateString(undefined, dateOpts);
    const start = new Date(startsAt).toLocaleTimeString(undefined, timeOpts);
    const end = new Date(endsAt).toLocaleTimeString(undefined, timeOpts);
    return `${date}, ${start} – ${end}`;
}

function formatMoney(amount, currency) {
    return amount > 0 ? `${currency} ${(amount / 100).toFixed(2)}` : 'Free';
}

function lowestPrice(event) {
    if (event.ticket_types.length > 0) {
        return Math.min(...event.ticket_types.map((t) => t.price));
    }
    return event.ticket_price;
}

function SpeakerCard({ speaker }) {
    const [open, setOpen] = useState(false);
    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                aria-label={`View profile: ${speaker.name}`}
                className="group min-w-0 text-left focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-accent"
            >
                {speaker.photo_url ? (
                    <img
                        src={speaker.photo_url}
                        alt={speaker.name}
                        loading="lazy"
                        className="mb-3 aspect-[4/4.3] w-full bg-surface-sunken object-cover object-[center_23%] transition-opacity group-hover:opacity-90"
                    />
                ) : (
                    <div className="mb-3 flex aspect-[4/4.3] w-full items-center justify-center bg-surface-sunken text-ink-tertiary">
                        <User className="h-12 w-12" strokeWidth={1} />
                    </div>
                )}
                <span className="block text-sm font-semibold leading-snug text-ink group-hover:text-accent">
                    {speaker.name}
                </span>
                {speaker.title && (
                    <span className="mt-1 block text-xs leading-relaxed text-ink-secondary">
                        {speaker.title}
                    </span>
                )}
                {speaker.organization && (
                    <span className="mt-1 block text-[11px] leading-relaxed text-ink-tertiary">
                        {speaker.organization}
                    </span>
                )}
                <span className="mt-2 inline-flex items-center gap-1 text-[11px] text-accent">
                    View profile <ExternalLink className="h-3 w-3" />
                </span>
            </button>
            <Modal
                open={open}
                onClose={() => setOpen(false)}
                title={speaker.name}
                className="max-w-xl"
            >
                <div className="flex items-start gap-4">
                    {speaker.photo_url && (
                        <img
                            src={speaker.photo_url}
                            alt=""
                            className="h-24 w-24 shrink-0 object-cover object-[center_23%]"
                        />
                    )}
                    <div className="min-w-0 text-sm text-ink-secondary">
                        {speaker.title && <p className="font-medium text-ink">{speaker.title}</p>}
                        {speaker.organization && <p className="mt-1">{speaker.organization}</p>}
                    </div>
                </div>
                <p className="mt-5 whitespace-pre-line text-sm leading-7 text-ink-secondary">
                    {speaker.bio || 'A biography will be shared soon.'}
                </p>
            </Modal>
        </>
    );
}

function SpeakersSection({ speakers }) {
    if (!speakers || speakers.length === 0) {
        return (
            <p className="text-sm text-ink-secondary">
                No speakers have been announced for this event yet.
            </p>
        );
    }

    return (
        <div className="grid grid-cols-2 gap-x-4 gap-y-7 sm:grid-cols-3 sm:gap-x-5">
            {speakers.map((speaker) => (
                <SpeakerCard key={speaker.id} speaker={speaker} />
            ))}
        </div>
    );
}

function NotesModal({ session, lexicon, onClose }) {
    const [copied, setCopied] = useState(false);
    const [copyError, setCopyError] = useState(false);
    if (!session) return null;

    const handleCopy = async () => {
        try {
            await navigator.clipboard.writeText(session.notes);
            setCopied(true);
            setCopyError(false);
        } catch {
            setCopyError(true);
        }
    };

    return (
        <Modal open onClose={onClose} title={session.title} className="max-w-xl">
            <div className="flex items-start justify-between gap-4 border-b border-border/80 pb-4">
                <div>
                    <span className="inline-block  bg-accent/10 px-2 py-0.5 text-[11px] font-semibold text-accent uppercase tracking-wider">
                        {lexicon?.notes_action_label ||
                            (session.type === 'service'
                                ? 'Sermon Notes'
                                : session.type === 'lecture'
                                  ? 'Lecture Outline'
                                  : 'Session Notes')}
                    </span>
                    {session.speaker_names?.length > 0 && (
                        <p className="mt-0.5 text-xs text-ink-secondary">
                            {session.speaker_names.join(', ')}
                        </p>
                    )}
                </div>
            </div>

            <div className="my-5 max-h-[60vh] overflow-y-auto bg-surface-sunken p-4 border border-border/60">
                <div className="text-[13.5px] leading-relaxed text-ink whitespace-pre-wrap font-sans">
                    {session.notes}
                </div>
            </div>

            <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border/80 pt-4">
                {session.presentation_url ? (
                    <a
                        href={session.presentation_url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="inline-flex items-center gap-1.5 border border-accent/30 bg-accent/10 px-3 py-1.5 text-xs font-medium text-accent hover:bg-accent/20 transition-colors"
                    >
                        <ExternalLink className="h-3.5 w-3.5" />
                        Open Presentation Slides
                    </a>
                ) : (
                    <span />
                )}

                <div className="flex items-center gap-2">
                    <button
                        type="button"
                        onClick={handleCopy}
                        className="inline-flex items-center gap-1.5 border border-border px-3 py-1.5 text-xs font-medium text-ink hover:bg-surface-sunken transition-colors cursor-pointer"
                    >
                        {copied ? (
                            <Check className="h-3.5 w-3.5 text-success-fg" />
                        ) : (
                            <Copy className="h-3.5 w-3.5" />
                        )}
                        {copied ? 'Copied' : 'Copy Notes'}
                    </button>
                    <button
                        type="button"
                        onClick={onClose}
                        className="bg-accent px-4 py-1.5 text-xs font-semibold text-white hover:bg-accent/90 transition-colors cursor-pointer"
                    >
                        Done
                    </button>
                </div>
            </div>
            {copyError && (
                <p role="status" className="mt-3 text-xs text-ink-secondary">
                    Select and copy the notes above; clipboard access is unavailable.
                </p>
            )}
        </Modal>
    );
}

function ScheduleSection({ sessions, timezone, icsUrl, recurrenceSummary, lexicon }) {
    const [viewingNotesSession, setViewingNotesSession] = useState(null);

    return (
        <div>
            {recurrenceSummary && (
                <div className="mb-6 flex items-start gap-3 border border-accent/25 bg-accent/5 p-4 text-xs sm:text-sm text-ink">
                    <Repeat className="h-5 w-5 text-accent shrink-0 mt-0.5" />
                    <div>
                        <span className="font-semibold text-accent">
                            Recurring Series Schedule:{' '}
                        </span>
                        <span>{recurrenceSummary}</span>
                        <p className="mt-1 text-[11.5px] text-ink-secondary">
                            Attendance is tracked per occurrence.{' '}
                            {lexicon?.notes_label
                                ? `${lexicon.notes_label} and materials are shared below.`
                                : 'Notes and presentation decks are shared below.'}
                        </p>
                    </div>
                </div>
            )}

            {icsUrl && (
                <a
                    href={icsUrl}
                    className="mb-4 inline-flex h-control items-center gap-1.5 border border-border px-3 text-[12.5px] text-ink-secondary hover:border-accent hover:text-accent "
                >
                    <Download className="h-3.5 w-3.5" strokeWidth={1.75} />
                    Add to calendar
                </a>
            )}

            {sessions.length === 0 ? (
                <p className="text-sm text-ink-secondary py-4">
                    No scheduled sessions or occurrences announced yet.
                </p>
            ) : (
                <ul className="flex flex-col divide-y divide-border/80">
                    {sessions.map((session) => (
                        <li
                            key={session.id}
                            className="flex flex-col sm:flex-row sm:items-start justify-between gap-3 py-4 first:pt-0"
                        >
                            <div className="flex items-start gap-3 min-w-0 flex-1">
                                <div className="w-28 shrink-0 font-mono text-[12px] text-ink-secondary">
                                    <div>
                                        {formatSessionTime(
                                            session.starts_at,
                                            session.ends_at,
                                            timezone
                                        )}
                                    </div>
                                    {session.is_occurrence && session.occurrence_status && (
                                        <span
                                            className={`mt-1 inline-block text-[10.5px] uppercase font-bold tracking-wider px-1.5 py-0.5  ${
                                                session.occurrence_status === 'completed'
                                                    ? 'bg-success-bg text-success-fg border border-success-fg/30'
                                                    : session.occurrence_status === 'cancelled'
                                                      ? 'bg-danger-bg text-danger-fg border border-danger-fg/30'
                                                      : 'bg-surface-sunken text-ink-secondary'
                                            }`}
                                        >
                                            {session.occurrence_status}
                                        </span>
                                    )}
                                </div>

                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <h4 className="text-[14px] font-semibold text-ink leading-snug">
                                            {session.title}
                                        </h4>
                                        {session.type && session.type !== 'session' && (
                                            <span className=" bg-surface-sunken px-2 py-0.5 text-[11px] font-medium text-ink-secondary border border-border/60 capitalize">
                                                {session.type.replace('_', ' ')}
                                            </span>
                                        )}
                                    </div>

                                    {session.description && (
                                        <p className="mt-1 text-[12.5px] text-ink-secondary leading-relaxed">
                                            {session.description}
                                        </p>
                                    )}

                                    {session.speaker_names?.length > 0 && (
                                        <div className="mt-1.5 text-[12px] text-ink-secondary flex items-center gap-1.5">
                                            <User className="h-3.5 w-3.5 text-accent shrink-0" />
                                            <span>{session.speaker_names.join(', ')}</span>
                                        </div>
                                    )}

                                    {session.location && (
                                        <div className="mt-1 text-[11.5px] text-ink-tertiary">
                                            📍 {session.location}
                                        </div>
                                    )}
                                </div>
                            </div>

                            {/* Occurrence Actions: Notes & Deck */}
                            <div className="flex sm:flex-col items-center sm:items-end gap-2 shrink-0 pt-2 sm:pt-0">
                                {session.notes && (
                                    <button
                                        type="button"
                                        onClick={() => setViewingNotesSession(session)}
                                        className="inline-flex items-center gap-1.5 border border-accent/30 bg-accent/10 px-3 py-1.5 text-xs font-medium text-accent hover:bg-accent/20 transition-colors cursor-pointer"
                                    >
                                        <BookOpen className="h-3.5 w-3.5" />
                                        <span>
                                            {lexicon?.notes_action_label ||
                                                (session.type === 'service'
                                                    ? 'Sermon Notes'
                                                    : session.type === 'lecture'
                                                      ? 'Lecture Outline'
                                                      : 'Notes')}
                                        </span>
                                    </button>
                                )}

                                {session.presentation_url && (
                                    <a
                                        href={session.presentation_url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="inline-flex items-center gap-1.5 border border-border bg-surface px-3 py-1.5 text-xs font-medium text-ink hover:bg-surface-sunken transition-colors"
                                    >
                                        <ExternalLink className="h-3.5 w-3.5 text-ink-secondary" />
                                        <span>Slide Deck</span>
                                    </a>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            {viewingNotesSession && (
                <NotesModal
                    session={viewingNotesSession}
                    lexicon={lexicon}
                    onClose={() => setViewingNotesSession(null)}
                />
            )}
        </div>
    );
}

function ForumSection({ event }) {
    const [threads, setThreads] = useState([]);
    const [form, setForm] = useState({
        title: '',
        body: '',
        author_name: '',
        author_email: '',
        is_anonymous: false,
    });
    const [attachment, setAttachment] = useState(null);
    const [submitted, setSubmitted] = useState(false);
    const [reported, setReported] = useState([]);

    const load = () => {
        const url = `${route('public.events.forum.index', { event: event.slug })}?respondent_token=${encodeURIComponent(respondentToken())}`;
        csrfFetch(url)
            .then((r) => r.json())
            .then(setThreads);
    };

    useEffect(load, [event.slug]);

    const submit = async (e) => {
        e.preventDefault();
        const formData = new FormData();
        Object.entries(form).forEach(([key, value]) =>
            formData.append(key, key === 'is_anonymous' ? (value ? '1' : '0') : value)
        );
        if (attachment) formData.append('attachment', attachment);

        const response = await csrfFetchFormData(
            route('public.events.forum.store', { event: event.slug }),
            formData
        );
        if (response.ok) {
            setSubmitted(true);
            setForm({
                title: '',
                body: '',
                author_name: '',
                author_email: '',
                is_anonymous: false,
            });
            setAttachment(null);
            load();
        }
    };

    const toggleVote = async (thread) => {
        const url = route(
            thread.voted_by_me ? 'public.events.forum.unvote' : 'public.events.forum.vote',
            { event: event.slug, thread: thread.id }
        );
        const response = await csrfFetch(url, {
            method: thread.voted_by_me ? 'DELETE' : 'POST',
            body: JSON.stringify({ respondent_token: respondentToken() }),
        });
        if (response.ok) load();
    };

    const report = async (thread) => {
        await csrfFetch(
            route('public.events.forum.report', { event: event.slug, thread: thread.id }),
            {
                method: 'POST',
                body: JSON.stringify({ reporter_token: respondentToken() }),
            }
        );
        setReported((prev) => [...prev, thread.id]);
    };

    return (
        <div>
            {threads.length > 0 && (
                <ul className="mb-8 space-y-4">
                    {threads.map((t) => (
                        <li key={t.id} className="border-b border-border pb-4 last:border-0">
                            <div className="flex items-start justify-between gap-3">
                                <b className="text-[14px] text-ink">{t.title}</b>
                                <button
                                    onClick={() => toggleVote(t)}
                                    className={`flex shrink-0 items-center gap-1 border px-2 py-1 text-xs ${t.voted_by_me ? 'border-accent text-accent' : 'border-border text-ink-secondary'}`}
                                >
                                    ▲ {t.votes_count}
                                </button>
                            </div>
                            <p className="mt-1 text-[13px] text-ink-secondary">{t.body}</p>
                            {t.attachment_url && (
                                <a
                                    href={t.attachment_url}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="mt-1 inline-block text-xs text-accent hover:underline"
                                >
                                    📎 {t.attachment_name}
                                </a>
                            )}
                            <div className="mt-1 flex items-center gap-3 text-xs text-ink-tertiary">
                                <span>{t.author_name}</span>
                                {reported.includes(t.id) ? (
                                    <span>Reported</span>
                                ) : (
                                    <button
                                        onClick={() => report(t)}
                                        className="underline hover:text-ink"
                                    >
                                        Report
                                    </button>
                                )}
                            </div>
                            {t.replies.map((r, i) => (
                                <div key={i} className="mt-2 border-l-2 border-accent pl-3">
                                    <div className="text-xs font-medium text-accent">
                                        {r.author_name} · organiser
                                    </div>
                                    <p className="text-[13px] text-ink">{r.body}</p>
                                </div>
                            ))}
                        </li>
                    ))}
                </ul>
            )}

            <div className="border border-border p-4">
                <b className="text-sm font-medium text-ink">Ask a question</b>
                {submitted ? (
                    <p className="mt-2 text-[13px] text-ink-secondary">
                        Thanks — your question has been posted.
                    </p>
                ) : (
                    <form onSubmit={submit} className="mt-3 space-y-3">
                        <Input
                            label="Your name"
                            value={form.author_name}
                            onChange={(e) => setForm({ ...form, author_name: e.target.value })}
                            required
                        />
                        <Input
                            label="Question title"
                            value={form.title}
                            onChange={(e) => setForm({ ...form, title: e.target.value })}
                            required
                        />
                        <div>
                            <label className="mb-1.5 block text-sm font-medium text-ink">
                                Details
                            </label>
                            <textarea
                                value={form.body}
                                onChange={(e) => setForm({ ...form, body: e.target.value })}
                                rows={3}
                                required
                                className="w-full border border-border px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none"
                            />
                        </div>
                        <div>
                            <label className="mb-1.5 block text-sm font-medium text-ink">
                                Attach a file (optional)
                            </label>
                            <input
                                type="file"
                                onChange={(e) => setAttachment(e.target.files[0] ?? null)}
                                className="text-sm text-ink-secondary"
                            />
                        </div>
                        <label className="flex items-center gap-2 text-sm text-ink">
                            <input
                                type="checkbox"
                                checked={form.is_anonymous}
                                onChange={(e) =>
                                    setForm({ ...form, is_anonymous: e.target.checked })
                                }
                            />
                            Ask without my name showing
                        </label>
                        <Button type="submit" variant="primary">
                            Post question
                        </Button>
                    </form>
                )}
            </div>
        </div>
    );
}

function secondsRemaining(poll) {
    if (!poll.timer_seconds || !poll.went_live_at) return null;
    const deadline = new Date(poll.went_live_at).getTime() + poll.timer_seconds * 1000;
    return Math.max(0, Math.round((deadline - Date.now()) / 1000));
}

function QuizLeaderboard({ event }) {
    const [rows, setRows] = useState([]);

    useEffect(() => {
        const load = () =>
            csrfFetch(route('public.events.quiz.leaderboard', { event: event.slug }))
                .then((r) => r.json())
                .then(setRows);
        load();
        const interval = setInterval(load, 6000);
        return () => clearInterval(interval);
        // Dependencies deliberately limited to the ids above (re-run only when they change).
    }, [event.slug]);

    if (rows.length === 0) return null;

    return (
        <div className="mt-8 max-w-md border border-border p-4">
            <div className="flex items-center gap-2">
                <Trophy className="h-4 w-4 text-accent" strokeWidth={1.75} />
                <b className="text-[13.5px] text-ink">Leaderboard</b>
            </div>
            <ol className="mt-3 space-y-1.5">
                {rows.map((r, i) => (
                    <li key={i} className="flex items-center justify-between text-[13px]">
                        <span className="text-ink">
                            {i + 1}. {r.name}
                        </span>
                        <span className="font-mono text-ink-secondary">{r.points} pts</span>
                    </li>
                ))}
            </ol>
        </div>
    );
}

function LivePollSection({ event }) {
    const [poll, setPoll] = useState(undefined);
    const [secondsLeft, setSecondsLeft] = useState(null);

    useEffect(() => {
        const load = () =>
            csrfFetch(route('public.events.poll.show', { event: event.slug }))
                .then((r) => r.json())
                .then((data) => setPoll(data.poll));
        load();
        const interval = setInterval(load, 4000);
        return () => clearInterval(interval);
    }, [event.slug]);

    useEffect(() => {
        if (!poll || poll.type !== 'quiz') {
            setSecondsLeft(null);
            return;
        }
        setSecondsLeft(secondsRemaining(poll));
        const interval = setInterval(() => setSecondsLeft(secondsRemaining(poll)), 1000);
        return () => clearInterval(interval);
    }, [poll]);

    if (poll === undefined) return null;
    if (poll === null)
        return (
            <p className="text-sm text-ink-secondary">
                No poll is live right now — check back during a session.
            </p>
        );

    const timeUp = secondsLeft !== null && secondsLeft <= 0;

    return (
        <div>
            <div className="max-w-md space-y-3">
                <div className="flex items-baseline justify-between gap-3">
                    <b className="block text-[15px] text-ink">{poll.question}</b>
                    {secondsLeft !== null && (
                        <span
                            className={`shrink-0 font-mono text-sm ${secondsLeft <= 5 ? 'text-danger-fg' : 'text-ink-secondary'}`}
                        >
                            {timeUp ? "Time's up" : `${secondsLeft}s`}
                        </span>
                    )}
                </div>

                <div className="border border-border bg-surface-muted px-3.5 py-3">
                    <p className="text-[13.5px] text-ink">
                        Answering is for people who are here. Open your ticket in your MiConvener
                        portal and check in — your answer counts towards attendance.
                    </p>
                    <a
                        href="/my"
                        className="mt-2 inline-block text-[13.5px] font-medium text-accent underline"
                    >
                        Open my ticket
                    </a>
                </div>
            </div>
            {poll.type === 'quiz' && <QuizLeaderboard event={event} />}
        </div>
    );
}

function isConditionSatisfied(field, answers) {
    if (!field.conditional_logic || !field.conditional_logic.depends_on_field) {
        return true;
    }
    const parentVal = answers[field.conditional_logic.depends_on_field];
    const expectedVal = field.conditional_logic.value;
    const op = field.conditional_logic.operator || 'equals';

    if (op === 'not_equals') {
        return String(parentVal ?? '') !== String(expectedVal ?? '');
    }
    if (op === 'in') {
        const list = Array.isArray(expectedVal) ? expectedVal : [expectedVal];
        return list.map(String).includes(String(parentVal ?? ''));
    }
    return String(parentVal ?? '') === String(expectedVal ?? '');
}

function RegistrationPanel({ event }) {
    const titleId = useId();
    const [showRegistration, setShowRegistration] = useState(false);
    const { flash } = usePage().props;
    const settings = event.registration_settings || {
        phone: 'optional',
        dietary_requirements: 'optional',
        accessibility_needs: 'optional',
    };
    const formFields = event.form_fields || [];

    const [unlockedTicketTypes, setUnlockedTicketTypes] = useState([]);
    const [accessCodeInput, setAccessCodeInput] = useState('');
    const [accessCodeError, setAccessCodeError] = useState('');
    const [unlocking, setUnlocking] = useState(false);
    const [showAccessCodeInput, setShowAccessCodeInput] = useState(false);

    const [promoCodeInput, setPromoCodeInput] = useState('');
    const [promoError, setPromoError] = useState('');
    const [validatingPromo, setValidatingPromo] = useState(false);
    const [appliedPromo, setAppliedPromo] = useState(null);
    const [showPromoInput, setShowPromoInput] = useState(false);

    // Merge standard public tickets with unlocked invite-only tickets
    const availableTicketTypes = useMemo(() => {
        const publicTickets = (event.ticket_types || []).filter((t) => !t.is_invite_only);
        const combined = [...publicTickets];
        unlockedTicketTypes.forEach((unlocked) => {
            if (!combined.some((t) => t.id === unlocked.id)) {
                combined.push(unlocked);
            }
        });
        return combined;
    }, [event.ticket_types, unlockedTicketTypes]);

    const hasTicketTypes = availableTicketTypes.length > 0;

    const { data, setData, post, processing, errors } = useForm({
        title: '',
        first_name: '',
        last_name: '',
        email: '',
        phone: '',
        dietary_requirements: '',
        accessibility_needs: '',
        ticket_type_id: hasTicketTypes ? availableTicketTypes[0].id : '',
        form_answers: {},
        promo_code: '',
        access_code: '',
    });

    const [showExtras, setShowExtras] = useState(false);
    const [fieldsExpanded, setFieldsExpanded] = useState(false);

    const totalExtraCount = useMemo(() => {
        let count = formFields.length;
        if (settings.phone !== 'hidden') count += 1;
        if (
            settings.dietary_requirements !== 'hidden' ||
            settings.accessibility_needs !== 'hidden'
        ) {
            count += 1;
        }
        return count;
    }, [formFields, settings]);

    // Automatically expand fields when validation errors exist on any collapsible fields
    useEffect(() => {
        if (Object.keys(errors).length > 0) {
            setShowRegistration(true);
        }
        const hasCollapsibleError = Object.keys(errors).some(
            (key) =>
                key.startsWith('form_answers') ||
                key === 'phone' ||
                key === 'dietary_requirements' ||
                key === 'accessibility_needs'
        );
        if (hasCollapsibleError) {
            setFieldsExpanded(true);
        }
    }, [errors]);

    const selectedTicketType = hasTicketTypes
        ? availableTicketTypes.find((t) => t.id === data.ticket_type_id) || availableTicketTypes[0]
        : null;
    const basePrice = selectedTicketType ? selectedTicketType.price : event.ticket_price || 0;

    // Calculate dynamic price reactively based on visible fields, chosen options, and promo code
    const calculatedPricing = useMemo(() => {
        let modifierTotal = 0;
        let override = null;
        const breakdown = [];

        formFields.forEach((field) => {
            if (!isConditionSatisfied(field, data.form_answers)) {
                return;
            }
            const selectedVal = data.form_answers[field.field_key];
            if (selectedVal === undefined || selectedVal === null || selectedVal === '') {
                return;
            }

            if (Array.isArray(field.options)) {
                field.options.forEach((opt) => {
                    if (
                        String(opt.value) === String(selectedVal) &&
                        opt.price !== undefined &&
                        opt.price !== null &&
                        !isNaN(Number(opt.price))
                    ) {
                        const optPrice = Number(opt.price);
                        if (opt.is_override) {
                            override = optPrice;
                            breakdown.push({
                                label: `${field.label}: ${opt.label}`,
                                amount: optPrice,
                                isOverride: true,
                            });
                        } else if (optPrice !== 0) {
                            modifierTotal += optPrice;
                            breakdown.push({
                                label: `${field.label}: ${opt.label}`,
                                amount: optPrice,
                                isOverride: false,
                            });
                        }
                    }
                });
            }
        });

        const subtotal = Math.max(
            0,
            override !== null ? override + modifierTotal : basePrice + modifierTotal
        );
        let promoDiscount = 0;

        if (appliedPromo) {
            if (appliedPromo.discount_type === 'percentage') {
                promoDiscount = Math.round(subtotal * (Number(appliedPromo.discount_value) / 100));
            } else if (appliedPromo.discount_type === 'fixed') {
                promoDiscount = Math.min(subtotal, Number(appliedPromo.discount_value));
            } else if (appliedPromo.discount_type === 'complimentary') {
                promoDiscount = subtotal;
            }

            if (promoDiscount > 0 || appliedPromo.discount_type === 'complimentary') {
                breakdown.push({
                    label: `Promo (${appliedPromo.code})`,
                    amount: promoDiscount,
                    isDiscount: true,
                    discountType: appliedPromo.discount_type,
                });
            }
        }

        const ticketTotal = Math.max(0, subtotal - promoDiscount);

        // When the organizer has chosen to pass the platform fee on, the buyer
        // sees it as its own line. The server recomputes it authoritatively at
        // registration; this mirrors that arithmetic so the two agree.
        let serviceFee = 0;

        if (ticketTotal > 0 && event.fee_bearer === 'attendee') {
            const uncapped = Math.round(
                (ticketTotal * Number(event.platform_fee_percentage || 0)) / 100
            );
            serviceFee =
                event.platform_fee_cap_amount === null ||
                event.platform_fee_cap_amount === undefined
                    ? uncapped
                    : Math.min(uncapped, Number(event.platform_fee_cap_amount));

            if (serviceFee > 0) {
                breakdown.push({ label: 'Service fee', amount: serviceFee, isOverride: false });
            }
        }

        const finalTotal = ticketTotal + serviceFee;

        return {
            subtotal,
            total: finalTotal,
            promoDiscount,
            serviceFee,
            breakdown,
            hasAdjustments:
                override !== null || modifierTotal !== 0 || promoDiscount > 0 || serviceFee > 0,
        };
    }, [
        basePrice,
        formFields,
        data.form_answers,
        appliedPromo,
        event.fee_bearer,
        event.platform_fee_percentage,
        event.platform_fee_cap_amount,
    ]);

    const handleAnswerChange = (fieldKey, value) => {
        setData('form_answers', {
            ...data.form_answers,
            [fieldKey]: value,
        });
    };

    const handleUnlockTickets = async (e) => {
        e?.preventDefault();
        if (!accessCodeInput.trim()) return;
        setUnlocking(true);
        setAccessCodeError('');
        try {
            const res = await fetch(route('public.events.unlock-tickets', { event: event.slug }), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN':
                        document
                            .querySelector('meta[name="csrf-token"]')
                            ?.getAttribute('content') || '',
                },
                body: JSON.stringify({ access_code: accessCodeInput.trim() }),
            });
            const json = await res.json();
            if (!res.ok) {
                setAccessCodeError(json.message || 'Invalid access code');
            } else {
                const newlyUnlocked = json.ticket_types || [];
                setUnlockedTicketTypes((prev) => [...prev, ...newlyUnlocked]);
                if (newlyUnlocked.length > 0) {
                    setData((prev) => ({
                        ...prev,
                        ticket_type_id: newlyUnlocked[0].id,
                        access_code: accessCodeInput.trim().toUpperCase(),
                    }));
                }
                setShowAccessCodeInput(false);
                setAccessCodeInput('');
            }
        } catch (err) {
            setAccessCodeError('Error unlocking tickets. Please try again.');
        } finally {
            setUnlocking(false);
        }
    };

    const handleApplyPromo = async (e) => {
        e?.preventDefault();
        if (!promoCodeInput.trim()) return;
        setValidatingPromo(true);
        setPromoError('');
        try {
            const res = await fetch(route('public.events.validate-promo', { event: event.slug }), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN':
                        document
                            .querySelector('meta[name="csrf-token"]')
                            ?.getAttribute('content') || '',
                },
                body: JSON.stringify({
                    code: promoCodeInput.trim(),
                    ticket_type_id: data.ticket_type_id || null,
                    email: data.email || null,
                    amount: basePrice,
                }),
            });
            const json = await res.json();
            if (!res.ok) {
                setPromoError(json.message || 'Invalid promo code');
                setAppliedPromo(null);
                setData('promo_code', '');
            } else {
                setAppliedPromo(json);
                setData('promo_code', json.code);
                setPromoError('');
            }
        } catch (err) {
            setPromoError('Failed to validate promo code.');
        } finally {
            setValidatingPromo(false);
        }
    };

    const handleRemovePromo = () => {
        setAppliedPromo(null);
        setPromoCodeInput('');
        setPromoError('');
        setData('promo_code', '');
    };

    const submit = (e) => {
        e.preventDefault();
        setFieldsExpanded(true);
        post(route('public.events.register', { event: event.slug }));
    };

    const titles = ['Mr', 'Mrs', 'Ms', 'Miss', 'Dr', 'Prof', 'Rev', 'Hon', 'Other'];

    return (
        <>
            <aside
                id="register-section"
                className="border border-border bg-surface p-5 sm:p-6 lg:sticky lg:top-5"
            >
                <div className="flex items-center gap-3 border-b border-border pb-4">
                    <div
                        aria-hidden="true"
                        className="w-12 shrink-0 bg-accent/10 py-2 text-center leading-none text-accent"
                    >
                        <span className="block text-[10px] uppercase tracking-wider">
                            {new Date(event.starts_at).toLocaleDateString(undefined, {
                                timeZone: event.timezone,
                                month: 'short',
                            })}
                        </span>
                        <b className="mt-1 block text-2xl font-medium">
                            {new Date(event.starts_at).toLocaleDateString(undefined, {
                                timeZone: event.timezone,
                                day: 'numeric',
                            })}
                        </b>
                    </div>
                    <div>
                        <p className="text-[13px] font-medium">
                            {formatDateRange(event.starts_at, event.ends_at, event.timezone)}
                        </p>
                        <p className="mt-1 text-[11px] leading-relaxed text-ink-secondary">
                            {new Date(event.starts_at).toLocaleTimeString(undefined, {
                                timeZone: event.timezone,
                                hour: 'numeric',
                                minute: '2-digit',
                            })}{' '}
                            –{' '}
                            {new Date(event.ends_at).toLocaleTimeString(undefined, {
                                timeZone: event.timezone,
                                hour: 'numeric',
                                minute: '2-digit',
                            })}{' '}
                            · {event.timezone}
                        </p>
                    </div>
                </div>
                <p className="my-4 flex items-start gap-2 text-xs leading-relaxed text-ink-secondary">
                    <MapPin className="mt-0.5 h-4 w-4 shrink-0" />
                    {event.location_type === 'virtual'
                        ? 'Online event'
                        : event.address || 'Venue to be announced'}
                </p>
                <h2 className="mb-4 flex items-center justify-between gap-3 text-lg font-medium tracking-tight">
                    Choose how to attend
                    <Ticket className="h-4 w-4 text-ink-tertiary" />
                </h2>
                {availableTicketTypes.length > 0 && (
                    <fieldset className="space-y-2">
                        <legend className="sr-only">Ticket options</legend>
                        {availableTicketTypes.map((ticket) => (
                            <label
                                key={ticket.id}
                                className={`flex cursor-pointer items-start gap-2.5 border p-3 text-xs ${data.ticket_type_id === ticket.id ? 'border-accent bg-accent/5' : 'border-border'}`}
                            >
                                <input
                                    type="radio"
                                    name="preview_ticket_type_id"
                                    value={ticket.id}
                                    checked={data.ticket_type_id === ticket.id}
                                    onChange={() => setData('ticket_type_id', ticket.id)}
                                    className="mt-0.5"
                                />
                                <span className="min-w-0 flex-1">
                                    <span className="block font-medium">{ticket.name}</span>
                                    {ticket.is_sold_out && (
                                        <span className="mt-1 block text-[11px] text-ink-secondary">
                                            Full — join the waitlist
                                        </span>
                                    )}
                                </span>
                                <span className="shrink-0 font-mono text-[11px]">
                                    {ticket.is_sold_out
                                        ? 'Waitlist'
                                        : formatMoney(ticket.price, event.currency)}
                                </span>
                            </label>
                        ))}
                    </fieldset>
                )}
                <button
                    type="button"
                    onClick={() => {
                        setShowRegistration(true);
                        setShowPromoInput(true);
                        setShowAccessCodeInput(hasTicketTypes);
                    }}
                    className="mt-4 text-xs text-ink-secondary underline underline-offset-4"
                >
                    Have a promo or access code?
                </button>
                <div className="mt-4 flex items-baseline justify-between gap-3 border-t border-border pt-4">
                    <span className="text-sm text-ink-secondary">Total</span>
                    <strong className="text-xl font-medium tracking-tight">
                        {formatMoney(calculatedPricing.total, event.currency)}
                    </strong>
                </div>
                {calculatedPricing.serviceFee > 0 && (
                    <p className="mt-1 text-[11px] text-ink-secondary">
                        Includes {formatMoney(calculatedPricing.serviceFee, event.currency)} service
                        fee.
                    </p>
                )}
                {flash?.error && (
                    <p role="alert" className="mt-3 text-xs text-danger-fg">
                        {flash.error}
                    </p>
                )}
                {flash?.success && (
                    <p role="status" className="mt-3 text-xs text-success-fg">
                        {flash.success}
                    </p>
                )}
                <Button
                    type="button"
                    onClick={() => setShowRegistration(true)}
                    className="mt-5 w-full justify-center"
                >
                    Continue <ChevronDown className="ml-2 h-3.5 w-3.5 -rotate-90" />
                </Button>
                <p className="mt-3 text-center text-[11px] leading-relaxed text-ink-tertiary">
                    One attendee per registration.
                </p>
                <FindMyTicket event={event} />
            </aside>
            <Modal
                open={showRegistration}
                onClose={() => setShowRegistration(false)}
                title="Complete your registration"
                className="max-w-lg"
            >
                {flash?.error && (
                    <div className="mt-3 bg-danger-bg px-3.5 py-2.5 text-[13px] text-danger-fg">
                        {flash.error}
                    </div>
                )}
                {flash?.success && (
                    <div className="mt-3 bg-success-bg px-3.5 py-2.5 text-[13px] text-success-fg">
                        {flash.success}
                    </div>
                )}

                <form onSubmit={submit} className="mt-4 space-y-4">
                    {hasTicketTypes && (
                        <div className="space-y-2">
                            <div className="flex flex-col gap-px bg-border">
                                {availableTicketTypes.map((ticketType) => (
                                    <label
                                        key={ticketType.id}
                                        className={`flex cursor-pointer items-center justify-between gap-3.5 bg-surface px-3.5 py-3 text-left ${
                                            data.ticket_type_id === ticketType.id
                                                ? 'shadow-[inset_2px_0_0_var(--color-accent)]'
                                                : ''
                                        }`}
                                    >
                                        <span className="flex items-start gap-2.5">
                                            <input
                                                type="radio"
                                                name="ticket_type_id"
                                                value={ticketType.id}
                                                checked={data.ticket_type_id === ticketType.id}
                                                onChange={(e) =>
                                                    setData('ticket_type_id', e.target.value)
                                                }
                                                className="mt-0.5"
                                            />
                                            <span>
                                                <span className="flex items-center gap-1.5 text-[13.5px] text-ink">
                                                    {ticketType.name}
                                                    {ticketType.is_invite_only && (
                                                        <span className=" bg-accent/15 px-1.5 py-0.5 text-[10.5px] font-semibold text-accent">
                                                            Unlocked VIP
                                                        </span>
                                                    )}
                                                </span>
                                                {ticketType.is_sold_out && (
                                                    <span className="block text-[11.5px] text-ink-secondary">
                                                        Full — join the waitlist
                                                    </span>
                                                )}
                                            </span>
                                        </span>
                                        <em
                                            className={`shrink-0 font-mono text-[14px] not-italic ${data.ticket_type_id === ticketType.id ? 'text-accent' : 'text-ink'}`}
                                        >
                                            {ticketType.is_sold_out
                                                ? 'Waitlist'
                                                : formatMoney(ticketType.price, event.currency)}
                                        </em>
                                    </label>
                                ))}
                            </div>

                            {/* Unlock Invite-only Tickets */}
                            <div className="pt-1">
                                {!showAccessCodeInput ? (
                                    <button
                                        type="button"
                                        onClick={() => setShowAccessCodeInput(true)}
                                        className="text-[12px] text-ink-secondary hover:text-accent underline transition-colors"
                                    >
                                        Have an access code for hidden tickets?
                                    </button>
                                ) : (
                                    <div className=" border border-border p-2.5 bg-surface-alt/40 space-y-2">
                                        <div className="flex items-center justify-between">
                                            <span className="text-[11.5px] font-medium text-ink">
                                                Enter Access Code:
                                            </span>
                                            <button
                                                type="button"
                                                onClick={() => setShowAccessCodeInput(false)}
                                                className="text-[11px] text-ink-muted hover:text-ink"
                                            >
                                                Cancel
                                            </button>
                                        </div>
                                        <div className="flex gap-2">
                                            <input
                                                type="text"
                                                value={accessCodeInput}
                                                onChange={(e) =>
                                                    setAccessCodeInput(e.target.value.toUpperCase())
                                                }
                                                placeholder="e.g. VIP2026"
                                                className="flex-1 uppercase font-mono text-xs border border-border px-2.5 py-1.5 bg-surface text-ink focus:border-accent focus:outline-none"
                                            />
                                            <Button
                                                type="button"
                                                onClick={handleUnlockTickets}
                                                disabled={unlocking || !accessCodeInput.trim()}
                                            >
                                                {unlocking ? 'Unlocking...' : 'Unlock'}
                                            </Button>
                                        </div>
                                        {accessCodeError && (
                                            <p className="text-[11px] text-danger-fg">
                                                {accessCodeError}
                                            </p>
                                        )}
                                    </div>
                                )}
                            </div>
                        </div>
                    )}
                    {errors.ticket_type_id && (
                        <p className="text-[13px] text-danger-fg">{errors.ticket_type_id}</p>
                    )}

                    {/* Core Attendee Identity Fields */}
                    <div className="grid grid-cols-3 gap-2.5">
                        <div>
                            <label
                                htmlFor={titleId}
                                className="block text-[12.5px] font-medium text-ink mb-1"
                            >
                                Title <span className="text-danger-fg">*</span>
                            </label>
                            <select
                                id={titleId}
                                value={data.title}
                                onChange={(e) => setData('title', e.target.value)}
                                className="w-full border border-border bg-surface px-2.5 py-2 text-[13.5px] text-ink focus:border-accent focus:outline-none"
                                required
                            >
                                <option value="">Select...</option>
                                {titles.map((t) => (
                                    <option key={t} value={t}>
                                        {t}
                                    </option>
                                ))}
                            </select>
                            {errors.title && (
                                <p className="mt-1 text-[11.5px] text-danger-fg">{errors.title}</p>
                            )}
                        </div>
                        <div className="col-span-2">
                            <Input
                                label="First name *"
                                type="text"
                                value={data.first_name}
                                onChange={(e) => setData('first_name', e.target.value)}
                                error={errors.first_name}
                                required
                            />
                        </div>
                    </div>

                    <Input
                        label="Last name *"
                        type="text"
                        value={data.last_name}
                        onChange={(e) => setData('last_name', e.target.value)}
                        error={errors.last_name}
                        required
                    />

                    <Input
                        label="Email *"
                        type="email"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        error={errors.email}
                        required
                    />

                    {/* Additional registration fields (Phone, Custom fields, Dietary/Accessibility) */}
                    {totalExtraCount > 0 && (
                        <div className="space-y-3">
                            <div
                                className={`transition-all duration-300 ${
                                    !fieldsExpanded && totalExtraCount >= 2
                                        ? 'max-h-60 overflow-hidden relative'
                                        : ''
                                }`}
                            >
                                {/* Standard Configurable Requirements */}
                                {settings.phone !== 'hidden' && (
                                    <Input
                                        label={`Phone ${settings.phone === 'required' ? '*' : '(optional)'}`}
                                        type="tel"
                                        value={data.phone}
                                        onChange={(e) => setData('phone', e.target.value)}
                                        error={errors.phone}
                                        required={settings.phone === 'required'}
                                    />
                                )}

                                {/* Custom Event Form Fields (Conditional & Dynamic Pricing) */}
                                {formFields.map((field) => {
                                    const visible = isConditionSatisfied(field, data.form_answers);
                                    if (!visible) return null;

                                    const fieldError = errors[`form_answers.${field.field_key}`];
                                    const val = data.form_answers[field.field_key] ?? '';
                                    const fieldId = `${titleId}-${field.field_key}`;

                                    return (
                                        <div
                                            key={field.id}
                                            className="border-t border-border/60 pt-3 mt-3"
                                        >
                                            <label
                                                id={`${fieldId}-label`}
                                                htmlFor={
                                                    field.field_type === 'radio'
                                                        ? undefined
                                                        : fieldId
                                                }
                                                className="block text-[13px] font-medium text-ink"
                                            >
                                                {field.label}{' '}
                                                {field.is_required && (
                                                    <span className="text-danger-fg">*</span>
                                                )}
                                            </label>
                                            {field.help_text && (
                                                <p className="mt-0.5 text-[11.5px] text-ink-secondary">
                                                    {field.help_text}
                                                </p>
                                            )}

                                            {field.field_type === 'select' && (
                                                <select
                                                    id={fieldId}
                                                    value={val}
                                                    onChange={(e) =>
                                                        handleAnswerChange(
                                                            field.field_key,
                                                            e.target.value
                                                        )
                                                    }
                                                    className="mt-1.5 w-full border border-border bg-surface px-3 py-2 text-[13.5px] text-ink focus:border-accent focus:outline-none"
                                                    required={field.is_required}
                                                >
                                                    <option value="">Select an option...</option>
                                                    {(field.options || []).map((opt, i) => (
                                                        <option key={i} value={opt.value}>
                                                            {opt.label}
                                                            {opt.price
                                                                ? ` (${opt.is_override ? 'Set to' : '+'} ${formatMoney(Number(opt.price), event.currency)})`
                                                                : ''}
                                                        </option>
                                                    ))}
                                                </select>
                                            )}

                                            {field.field_type === 'radio' && (
                                                <div
                                                    role="radiogroup"
                                                    aria-labelledby={`${fieldId}-label`}
                                                    className="mt-2 space-y-1.5"
                                                >
                                                    {(field.options || []).map((opt, i) => (
                                                        <label
                                                            key={i}
                                                            className="flex cursor-pointer items-center justify-between gap-2 border border-border/70 p-2 text-[13px] hover:bg-surface-sunken"
                                                        >
                                                            <span className="flex items-center gap-2">
                                                                <input
                                                                    type="radio"
                                                                    name={`custom_${field.field_key}`}
                                                                    value={opt.value}
                                                                    checked={
                                                                        String(val) ===
                                                                        String(opt.value)
                                                                    }
                                                                    onChange={(e) =>
                                                                        handleAnswerChange(
                                                                            field.field_key,
                                                                            e.target.value
                                                                        )
                                                                    }
                                                                    required={field.is_required}
                                                                />
                                                                <span className="text-ink">
                                                                    {opt.label}
                                                                </span>
                                                            </span>
                                                            {opt.price ? (
                                                                <span className="font-mono text-xs text-accent">
                                                                    {opt.is_override
                                                                        ? 'Total '
                                                                        : '+'}
                                                                    {formatMoney(
                                                                        Number(opt.price),
                                                                        event.currency
                                                                    )}
                                                                </span>
                                                            ) : null}
                                                        </label>
                                                    ))}
                                                </div>
                                            )}

                                            {field.field_type === 'text' && (
                                                <input
                                                    id={fieldId}
                                                    type="text"
                                                    value={val}
                                                    onChange={(e) =>
                                                        handleAnswerChange(
                                                            field.field_key,
                                                            e.target.value
                                                        )
                                                    }
                                                    className="mt-1.5 w-full border border-border bg-surface px-3 py-2 text-[13.5px] text-ink focus:border-accent focus:outline-none"
                                                    required={field.is_required}
                                                />
                                            )}

                                            {field.field_type === 'textarea' && (
                                                <textarea
                                                    id={fieldId}
                                                    rows={2}
                                                    value={val}
                                                    onChange={(e) =>
                                                        handleAnswerChange(
                                                            field.field_key,
                                                            e.target.value
                                                        )
                                                    }
                                                    className="mt-1.5 w-full border border-border bg-surface px-3 py-2 text-[13.5px] text-ink focus:border-accent focus:outline-none"
                                                    required={field.is_required}
                                                />
                                            )}

                                            {field.field_type === 'number' && (
                                                <input
                                                    id={fieldId}
                                                    type="number"
                                                    value={val}
                                                    onChange={(e) =>
                                                        handleAnswerChange(
                                                            field.field_key,
                                                            e.target.value
                                                        )
                                                    }
                                                    className="mt-1.5 w-full border border-border bg-surface px-3 py-2 text-[13.5px] text-ink focus:border-accent focus:outline-none"
                                                    required={field.is_required}
                                                />
                                            )}

                                            {field.field_type === 'checkbox' && (
                                                <label className="mt-2 flex items-center gap-2 cursor-pointer text-[13px] text-ink">
                                                    <input
                                                        id={fieldId}
                                                        type="checkbox"
                                                        checked={Boolean(val)}
                                                        onChange={(e) =>
                                                            handleAnswerChange(
                                                                field.field_key,
                                                                e.target.checked
                                                            )
                                                        }
                                                        required={field.is_required}
                                                    />
                                                    <span>{field.label}</span>
                                                </label>
                                            )}

                                            {fieldError && (
                                                <p className="mt-1 text-[11.5px] text-danger-fg">
                                                    {fieldError}
                                                </p>
                                            )}
                                        </div>
                                    );
                                })}

                                {/* Dietary and Accessibility standard options */}
                                {(settings.dietary_requirements !== 'hidden' ||
                                    settings.accessibility_needs !== 'hidden') && (
                                    <div className="border-t border-border/60 pt-3 mt-3">
                                        {settings.dietary_requirements === 'required' ||
                                        settings.accessibility_needs === 'required' ||
                                        fieldsExpanded ||
                                        showExtras ? (
                                            <div className="space-y-3">
                                                {settings.dietary_requirements !== 'hidden' && (
                                                    <Input
                                                        label={`Dietary requirements ${settings.dietary_requirements === 'required' ? '*' : '(optional)'}`}
                                                        type="text"
                                                        placeholder="e.g. Vegetarian, nut allergy"
                                                        value={data.dietary_requirements}
                                                        onChange={(e) =>
                                                            setData(
                                                                'dietary_requirements',
                                                                e.target.value
                                                            )
                                                        }
                                                        error={errors.dietary_requirements}
                                                        required={
                                                            settings.dietary_requirements ===
                                                            'required'
                                                        }
                                                    />
                                                )}
                                                {settings.accessibility_needs !== 'hidden' && (
                                                    <Input
                                                        label={`Accessibility needs ${settings.accessibility_needs === 'required' ? '*' : '(optional)'}`}
                                                        type="text"
                                                        placeholder="e.g. Step-free access, sign language"
                                                        value={data.accessibility_needs}
                                                        onChange={(e) =>
                                                            setData(
                                                                'accessibility_needs',
                                                                e.target.value
                                                            )
                                                        }
                                                        error={errors.accessibility_needs}
                                                        required={
                                                            settings.accessibility_needs ===
                                                            'required'
                                                        }
                                                    />
                                                )}
                                            </div>
                                        ) : (
                                            <button
                                                type="button"
                                                onClick={() => setShowExtras(true)}
                                                className="text-[12.5px] text-ink-secondary underline hover:text-accent"
                                            >
                                                Add dietary or accessibility needs
                                            </button>
                                        )}
                                    </div>
                                )}

                                {!fieldsExpanded && totalExtraCount >= 2 && (
                                    <div className="pointer-events-none absolute inset-x-0 bottom-0 h-16 bg-gradient-to-t from-surface to-transparent" />
                                )}
                            </div>

                            {totalExtraCount >= 2 && (
                                <button
                                    type="button"
                                    onClick={() => setFieldsExpanded(!fieldsExpanded)}
                                    className="flex w-full items-center justify-center gap-1.5 border border-border bg-surface-alt/60 hover:bg-surface-alt py-2 px-3 text-[12px] font-medium text-accent transition-colors cursor-pointer"
                                >
                                    <span>
                                        {fieldsExpanded
                                            ? 'Show fewer fields'
                                            : `Show all registration questions (${totalExtraCount} details)`}
                                    </span>
                                    {fieldsExpanded ? (
                                        <ChevronUp className="h-3.5 w-3.5" />
                                    ) : (
                                        <ChevronDown className="h-3.5 w-3.5" />
                                    )}
                                </button>
                            )}
                        </div>
                    )}

                    {/* Promo / Discount Code Input Section */}
                    <div className="border-t border-border/60 pt-3">
                        {appliedPromo ? (
                            <div className="flex items-center justify-between  border border-success-fg/30 bg-success-bg/40 px-3 py-2 text-xs">
                                <div className="flex items-center gap-2">
                                    <span className="inline-block h-2 w-2 rounded-full bg-success-fg animate-pulse"></span>
                                    <span className="font-semibold text-ink">
                                        Code: {appliedPromo.code}
                                    </span>
                                    <span className="text-[11px] text-success-fg">
                                        (
                                        {appliedPromo.discount_type === 'complimentary'
                                            ? 'Complimentary pass (100% OFF)'
                                            : appliedPromo.discount_type === 'percentage'
                                              ? `${appliedPromo.discount_value}% OFF`
                                              : `-${formatMoney(appliedPromo.discount_value, event.currency)}`}
                                        )
                                    </span>
                                </div>
                                <button
                                    type="button"
                                    onClick={handleRemovePromo}
                                    className="text-[11px] text-danger-fg hover:underline"
                                >
                                    Remove
                                </button>
                            </div>
                        ) : !showPromoInput ? (
                            <button
                                type="button"
                                onClick={() => setShowPromoInput(true)}
                                className="text-[12px] text-ink-secondary hover:text-accent underline transition-colors"
                            >
                                Have a promo code?
                            </button>
                        ) : (
                            <div className=" border border-border p-2.5 bg-surface-alt/40 space-y-2">
                                <div className="flex items-center justify-between">
                                    <span className="text-[11.5px] font-medium text-ink">
                                        Promo Code:
                                    </span>
                                    <button
                                        type="button"
                                        onClick={() => setShowPromoInput(false)}
                                        className="text-[11px] text-ink-muted hover:text-ink"
                                    >
                                        Cancel
                                    </button>
                                </div>
                                <div className="flex gap-2">
                                    <input
                                        type="text"
                                        value={promoCodeInput}
                                        onChange={(e) =>
                                            setPromoCodeInput(e.target.value.toUpperCase())
                                        }
                                        placeholder="e.g. EARLYBIRD20"
                                        className="flex-1 uppercase font-mono text-xs border border-border px-2.5 py-1.5 bg-surface text-ink focus:border-accent focus:outline-none"
                                    />
                                    <Button
                                        type="button"
                                        onClick={handleApplyPromo}
                                        disabled={validatingPromo || !promoCodeInput.trim()}
                                    >
                                        {validatingPromo ? 'Checking...' : 'Apply'}
                                    </Button>
                                </div>
                                {promoError && (
                                    <p className="text-[11px] text-danger-fg">{promoError}</p>
                                )}
                            </div>
                        )}
                    </div>

                    {/* Dynamic Price Summary Box */}
                    {calculatedPricing.hasAdjustments && (
                        <div className=" border border-accent/20 bg-accent-soft/30 p-3 text-xs space-y-1.5">
                            <div className="flex justify-between font-medium text-ink">
                                <span>Base Ticket:</span>
                                <span>{formatMoney(basePrice, event.currency)}</span>
                            </div>
                            {calculatedPricing.breakdown.map((item, idx) => (
                                <div
                                    key={idx}
                                    className={`flex justify-between ${
                                        item.isDiscount
                                            ? 'font-medium text-success-fg'
                                            : 'text-ink-secondary'
                                    }`}
                                >
                                    <span>{item.label}:</span>
                                    <span>
                                        {item.isDiscount
                                            ? `-${formatMoney(item.amount, event.currency)}`
                                            : item.isOverride
                                              ? 'Overrides to '
                                              : '+'}
                                        {!item.isDiscount &&
                                            formatMoney(item.amount, event.currency)}
                                    </span>
                                </div>
                            ))}
                            <div className="border-t border-accent/20 pt-1.5 flex justify-between font-bold text-sm text-accent">
                                <span>Total Payable:</span>
                                <span>
                                    {calculatedPricing.total > 0
                                        ? formatMoney(calculatedPricing.total, event.currency)
                                        : 'FREE (Complimentary)'}
                                </span>
                            </div>
                        </div>
                    )}

                    <Button
                        type="submit"
                        disabled={processing}
                        variant="primary"
                        className="w-full justify-center"
                    >
                        {selectedTicketType?.is_sold_out
                            ? 'Join waitlist'
                            : calculatedPricing.total > 0
                              ? `Continue to payment (${formatMoney(calculatedPricing.total, event.currency)})`
                              : appliedPromo?.discount_type === 'complimentary'
                                ? 'Complete Free Registration'
                                : 'Register'}
                    </Button>
                </form>

                <p className="mt-3.5 text-[11.5px] leading-relaxed text-ink-secondary">
                    Card and mobile money accepted where connected. Your entry code arrives by email
                    the moment payment clears.
                </p>
            </Modal>
        </>
    );
}

/**
 * A ticket lives behind a link in an email, so losing the email loses the
 * ticket. This sends it again rather than making anyone create an account.
 */
function FindMyTicket({ event }) {
    const [open, setOpen] = useState(false);
    const [email, setEmail] = useState('');
    const [sending, setSending] = useState(false);
    const [message, setMessage] = useState(null);

    const submit = async (e) => {
        e.preventDefault();
        setSending(true);
        const response = await csrfFetch(
            route('public.events.find-ticket', { event: event.slug }),
            {
                method: 'POST',
                body: JSON.stringify({ email }),
            }
        );
        const json = await response.json().catch(() => ({}));
        setSending(false);
        setMessage(
            response.ok ? json.message : 'Too many attempts just now. Try again in a minute.'
        );
    };

    if (message) {
        return (
            <p className="mt-4 border-t border-border pt-3.5 text-[12px] text-ink-secondary">
                {message}
            </p>
        );
    }

    return (
        <div className="mt-4 border-t border-border pt-3.5">
            {open ? (
                <form onSubmit={submit} className="space-y-2">
                    <label
                        htmlFor="find-ticket-email"
                        className="block text-[12px] text-ink-secondary"
                    >
                        The address you registered with
                    </label>
                    <input
                        id="find-ticket-email"
                        type="email"
                        value={email}
                        onChange={(e) => setEmail(e.target.value)}
                        required
                        placeholder="you@example.com"
                        className="w-full border border-border bg-surface px-3 py-2 text-[13px] text-ink focus:border-accent focus:outline-none"
                    />
                    <button
                        type="submit"
                        disabled={sending}
                        className="text-[12.5px] text-accent underline disabled:opacity-60"
                    >
                        {sending ? 'Sending…' : 'Email me my ticket'}
                    </button>
                </form>
            ) : (
                <button
                    type="button"
                    onClick={() => setOpen(true)}
                    className="text-[12px] text-ink-secondary underline hover:text-accent"
                >
                    Already registered? Find my ticket
                </button>
            )}
        </div>
    );
}

function OrganiserLinks({ links = {} }) {
    const labels = {
        website: 'Website',
        instagram: 'Instagram',
        x: 'X',
        facebook: 'Facebook',
        linkedin: 'LinkedIn',
        youtube: 'YouTube',
    };
    const marks = {
        website: <Globe className="h-4 w-4" />,
        instagram: 'IG',
        x: 'X',
        facebook: 'f',
        linkedin: 'in',
        youtube: '▶',
    };
    return (
        <div className="flex flex-wrap gap-2">
            {Object.entries(labels)
                .filter(([key]) => links[key])
                .map(([key, label]) => (
                    <a
                        key={key}
                        href={links[key]}
                        target="_blank"
                        rel="noopener noreferrer"
                        aria-label={label}
                        title={label}
                        className="flex h-9 w-9 items-center justify-center border border-border text-xs font-semibold text-ink-secondary transition-colors hover:border-accent hover:text-accent"
                    >
                        {marks[key]}
                    </a>
                ))}
        </div>
    );
}

export default function Show({ event, org, isPrivate = false, community = null }) {
    const workshops = useMemo(
        () => event.sessions.filter((session) => session.type === 'workshop'),
        [event.sessions]
    );
    const [active, setActive] = useState('about');
    const [dialog, setDialog] = useState(null);
    const [copied, setCopied] = useState(false);
    const [copyError, setCopyError] = useState(false);
    const price = lowestPrice(event);
    const interactive = ['qa', 'live', 'contributions'].includes(active);
    const contributionLabel =
        event.contribution_title || event.lexicon?.contributions_tab_label || 'Contributions';
    const sections = [
        { key: 'about', label: 'Overview' },
        ...(event.sessions.length ? [{ key: 'schedule', label: 'Programme' }] : []),
        ...(event.speakers.length ? [{ key: 'speakers', label: 'Speakers' }] : []),
        ...(workshops.length ? [{ key: 'workshops', label: 'Workshops' }] : []),
        ...(event.plan_your_visit_content ? [{ key: 'visit', label: 'Plan your visit' }] : []),
        ...(event.allow_contributions ? [{ key: 'contributions', label: contributionLabel }] : []),
        { key: 'qa', label: 'Q&A' },
        { key: 'live', label: 'Live poll' },
    ];
    const selectSection = (key) => {
        setActive(key);
        requestAnimationFrame(() =>
            document
                .getElementById(
                    ['qa', 'live', 'contributions'].includes(key)
                        ? 'event-interaction'
                        : `event-${key}`
                )
                ?.scrollIntoView({
                    behavior: window.matchMedia?.('(prefers-reduced-motion: reduce)').matches
                        ? 'instant'
                        : 'smooth',
                    block: 'start',
                })
        );
    };
    const shareUrl = typeof window === 'undefined' ? '' : window.location.href.split('#')[0];
    const copyLink = async () => {
        try {
            await navigator.clipboard.writeText(shareUrl);
            setCopied(true);
            setCopyError(false);
        } catch {
            setCopyError(true);
        }
    };
    const calendarUrl = route('public.events.schedule.ics', { event: event.slug });
    const isVirtual = event.location_type === 'virtual';
    const sectionHeading = 'mb-5 text-[23px] font-medium tracking-tight text-ink';

    return (
        <PublicLayout
            headerActions={
                <a href="/my" className="text-xs text-ink-secondary hover:text-accent">
                    My tickets
                </a>
            }
        >
            <article className="pb-16 [&_a]:rounded-none [&_button]:rounded-none [&_input:not([type=radio]):not([type=checkbox])]:rounded-none [&_select]:rounded-none [&_textarea]:rounded-none [&_[role=dialog]]:rounded-none">
                <div
                    className="mx-auto mt-3 w-[calc(100%-2rem)] overflow-hidden sm:mt-4 lg:w-[80vw]"
                    data-event-hero
                >
                    {event.hero_image_url ? (
                        <img
                            src={event.hero_image_url}
                            alt={event.name}
                            fetchPriority="high"
                            className="h-[clamp(220px,28vw,430px)] w-full object-cover"
                        />
                    ) : (
                        <CoverBars height={280} />
                    )}
                </div>
                <div className="mx-auto w-[calc(100%-3rem)] max-w-[1100px] lg:w-[72vw]">
                    <section
                        aria-label="Event organiser"
                        className="relative flex flex-wrap items-center gap-3 pb-6 pt-5"
                    >
                        {org.logo_url ? (
                            <img
                                src={org.logo_url}
                                alt=""
                                className="h-11 w-11 shrink-0 border border-border bg-white object-contain p-1"
                            />
                        ) : (
                            <div
                                aria-hidden="true"
                                className="flex h-11 w-11 shrink-0 items-center justify-center border border-border bg-surface text-lg font-medium text-accent"
                            >
                                {org.name?.slice(0, 1)}
                            </div>
                        )}
                        <div className="min-w-0 flex-1">
                            <span className="block text-[10px] text-ink-tertiary">Hosted by</span>
                            <a
                                href="#event-organiser"
                                className="text-[13px] font-medium hover:text-accent"
                            >
                                {org.name}
                            </a>
                        </div>
                        {community?.followingEnabled && (
                            <details className="group">
                                <summary className="cursor-pointer list-none border border-border bg-surface px-4 py-2 text-xs text-ink [&::-webkit-details-marker]:hidden">
                                    + Follow
                                </summary>
                                <div className="absolute inset-x-0 top-full z-20 border border-border bg-surface p-5 shadow-lg sm:inset-x-auto sm:right-0 sm:w-80">
                                    <FollowControl
                                        identity={community.identity}
                                        initialFollowing={community.following}
                                        followUrl={community.followUrl}
                                        signInUrl={community.signInUrl}
                                        sourceEventId={event.id}
                                    />
                                </div>
                            </details>
                        )}
                        {Object.keys(org.links || {}).length > 0 && (
                            <div className="w-full sm:w-auto">
                                <OrganiserLinks links={org.links} />
                            </div>
                        )}
                    </section>
                    <div className="grid min-w-0 gap-x-10 gap-y-6 lg:grid-cols-[minmax(0,1fr)_300px] xl:gap-x-12">
                        <header className="min-w-0 lg:col-start-1 lg:row-start-1">
                            <p className="flex items-center gap-2 text-[11px] font-medium uppercase tracking-[0.14em] text-accent">
                                {isPrivate && <Lock className="h-3 w-3" />}
                                {isPrivate
                                    ? 'Private event'
                                    : isVirtual
                                      ? 'Online event'
                                      : event.location_type === 'hybrid'
                                        ? 'Hybrid event'
                                        : 'In-person event'}
                            </p>
                            <h1 className="mt-3 break-words text-[clamp(32px,3.4vw,46px)] font-medium leading-[1.1] tracking-[-0.04em] text-ink">
                                {event.name}
                            </h1>
                            {event.description && (
                                <p className="mt-4 max-w-xl text-[15px] leading-7 text-ink-secondary">
                                    {event.description.split(/\n\s*\n/)[0].length > 220
                                        ? `${event.description.split(/\n\s*\n/)[0].slice(0, 217)}…`
                                        : event.description.split(/\n\s*\n/)[0]}
                                </p>
                            )}
                            {isPrivate && (
                                <p className="mt-3 text-xs leading-relaxed text-ink-secondary">
                                    The speakers, programme and materials for this event are shown
                                    to registered guests only.
                                </p>
                            )}
                            {event.is_recurring && event.recurrence_summary && (
                                <p className="mt-3 flex items-center gap-2 text-xs text-accent">
                                    <Repeat className="h-3.5 w-3.5 shrink-0" />
                                    {event.recurrence_summary}
                                </p>
                            )}
                            <div className="mt-5 flex flex-wrap items-start gap-x-5 gap-y-3 text-xs text-ink-secondary">
                                <span className="inline-flex items-start gap-2">
                                    <CalendarDays className="h-4 w-4 shrink-0" />
                                    {formatDateRange(
                                        event.starts_at,
                                        event.ends_at,
                                        event.timezone
                                    )}
                                </span>
                                <span className="inline-flex items-start gap-2">
                                    <MapPin className="h-4 w-4 shrink-0" />
                                    {isVirtual
                                        ? 'Online'
                                        : event.address || 'Venue to be announced'}
                                </span>
                                <button
                                    type="button"
                                    aria-label="Share event"
                                    onClick={() => {
                                        setCopied(false);
                                        setCopyError(false);
                                        setDialog('share');
                                    }}
                                    className="inline-flex items-center gap-2 text-accent hover:underline"
                                >
                                    <Share2 className="h-3.5 w-3.5" />
                                    Share event
                                </button>
                            </div>
                            <div className="mt-3 flex flex-wrap gap-x-5 gap-y-2 text-[11px] text-accent">
                                {!isPrivate && (
                                    <a href={calendarUrl} className="underline underline-offset-4">
                                        Add to calendar
                                    </a>
                                )}
                                {!isVirtual && event.address && (
                                    <a
                                        href={`https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(event.address)}`}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="underline underline-offset-4"
                                    >
                                        Get directions
                                    </a>
                                )}
                            </div>
                        </header>
                        <div className="min-w-0 lg:col-start-2 lg:row-start-1 lg:row-span-2">
                            <RegistrationPanel event={event} />
                        </div>
                        <main className="min-w-0 lg:col-start-1 lg:row-start-2">
                            <nav
                                aria-label="Event sections"
                                className="mb-7 flex max-w-full gap-6 overflow-x-auto border-b border-border"
                            >
                                {sections.map((section) => (
                                    <button
                                        type="button"
                                        key={section.key}
                                        aria-current={
                                            active === section.key ? 'location' : undefined
                                        }
                                        onClick={() => selectSection(section.key)}
                                        className={`shrink-0 border-b-2 py-3 text-xs font-medium transition-colors ${active === section.key ? 'border-accent text-accent' : 'border-transparent text-ink-secondary hover:text-ink'}`}
                                    >
                                        {section.label}
                                    </button>
                                ))}
                            </nav>
                            {interactive && (
                                <section
                                    id="event-interaction"
                                    className="mb-12 scroll-mt-6 border-b border-border pb-10"
                                >
                                    <h2 className={sectionHeading}>
                                        {sections.find((section) => section.key === active)?.label}
                                    </h2>
                                    {active === 'qa' && <ForumSection event={event} />}
                                    {active === 'live' && <LivePollSection event={event} />}
                                    {active === 'contributions' && (
                                        <ContributionWidget event={event} />
                                    )}
                                </section>
                            )}
                            <section id="event-about" className="scroll-mt-6 pb-8">
                                <h2 className={sectionHeading}>About the event</h2>
                                <p className="whitespace-pre-line text-[15px] leading-7 text-ink-secondary">
                                    {event.description ||
                                        'More information about this event will be shared soon.'}
                                </p>
                                {event.allow_contributions && (
                                    <div className="mt-6 border-l-2 border-accent bg-accent/5 p-5">
                                        <h3 className="flex items-center gap-2 text-sm font-medium">
                                            <Heart className="h-4 w-4 text-accent" />
                                            {event.contribution_title ||
                                                event.lexicon?.contributions_title ||
                                                'Voluntary contributions'}
                                        </h3>
                                        <p className="mt-2 text-sm text-ink-secondary">
                                            {event.contributions_total_pesewas > 0
                                                ? `${event.currency} ${(event.contributions_total_pesewas / 100).toFixed(2)} contributed so far.`
                                                : event.lexicon?.contributions_subtitle ||
                                                  'Support this gathering with a voluntary contribution.'}
                                        </p>
                                        <button
                                            type="button"
                                            onClick={() => selectSection('contributions')}
                                            className="mt-4 text-sm font-medium text-accent underline underline-offset-4"
                                        >
                                            {event.lexicon?.contributions_cta_label || 'Contribute'}
                                        </button>
                                    </div>
                                )}
                            </section>
                            {!isPrivate && Number.isInteger(event.going_count) && (
                                <div className="mb-8 flex items-center gap-3 border-y border-border py-4 text-sm text-ink-secondary">
                                    <Users className="h-5 w-5 text-accent" />
                                    <span>
                                        <b className="font-medium text-ink">
                                            {event.going_count.toLocaleString()} going
                                        </b>
                                        <span className="mt-0.5 block text-xs">
                                            Confirmed attendees
                                        </span>
                                    </span>
                                </div>
                            )}
                            {event.sessions.length > 0 && (
                                <section
                                    id="event-schedule"
                                    className="scroll-mt-6 border-t border-border py-8"
                                >
                                    <h2 className={sectionHeading}>Programme</h2>
                                    <ScheduleSection
                                        sessions={
                                            event.is_recurring && event.upcoming_occurrences?.length
                                                ? event.upcoming_occurrences
                                                : event.sessions
                                        }
                                        timezone={event.timezone}
                                        icsUrl={isPrivate ? null : calendarUrl}
                                        recurrenceSummary={
                                            event.is_recurring ? event.recurrence_summary : null
                                        }
                                        lexicon={event.lexicon}
                                    />
                                </section>
                            )}
                            {event.speakers.length > 0 && (
                                <section
                                    id="event-speakers"
                                    className="scroll-mt-6 border-t border-border py-8"
                                >
                                    <h2 className={sectionHeading}>Meet the speakers</h2>
                                    <SpeakersSection speakers={event.speakers} />
                                </section>
                            )}
                            {workshops.length > 0 && (
                                <section
                                    id="event-workshops"
                                    className="scroll-mt-6 border-t border-border py-8"
                                >
                                    <h2 className={sectionHeading}>Workshops</h2>
                                    <ScheduleSection
                                        sessions={workshops}
                                        timezone={event.timezone}
                                    />
                                </section>
                            )}
                            {event.plan_your_visit_content && (
                                <section
                                    id="event-visit"
                                    className="scroll-mt-6 border-t border-border py-8"
                                >
                                    <h2 className={sectionHeading}>Plan your visit</h2>
                                    <p className="whitespace-pre-line text-[15px] leading-7 text-ink-secondary">
                                        {event.plan_your_visit_content}
                                    </p>
                                </section>
                            )}
                            <section
                                id="event-organiser"
                                className="scroll-mt-6 border-t border-border py-8"
                            >
                                <p className="mb-5 text-xs uppercase tracking-[0.12em] text-ink-tertiary">
                                    Your organiser
                                </p>
                                <div className="flex items-start gap-4">
                                    {org.logo_url ? (
                                        <img
                                            src={org.logo_url}
                                            alt=""
                                            loading="lazy"
                                            className="h-14 w-14 border border-border bg-white object-contain p-1"
                                        />
                                    ) : (
                                        <div
                                            aria-hidden="true"
                                            className="flex h-14 w-14 shrink-0 items-center justify-center border border-border bg-surface text-lg font-medium text-accent"
                                        >
                                            {org.name?.slice(0, 1)}
                                        </div>
                                    )}
                                    <div className="min-w-0">
                                        <h2 className="text-xl font-medium tracking-tight">
                                            {org.name}
                                        </h2>
                                        {org.description && (
                                            <p className="mt-3 whitespace-pre-line text-sm leading-6 text-ink-secondary">
                                                {org.description}
                                            </p>
                                        )}
                                    </div>
                                </div>

                                {org.contact_email && (
                                    <button
                                        type="button"
                                        onClick={() => setDialog('contact')}
                                        className="mt-4 border border-border px-4 py-2.5 text-xs font-medium hover:border-accent hover:text-accent"
                                    >
                                        Contact organiser
                                    </button>
                                )}
                                {community && (
                                    <div className="mt-6 flex flex-col items-start gap-4 border-t border-border pt-5">
                                        <a
                                            href={community.organisation.about_url}
                                            className="inline-block text-sm text-accent underline underline-offset-4"
                                        >
                                            Organisation page and membership options
                                        </a>
                                    </div>
                                )}
                            </section>
                        </main>
                    </div>
                    {event.sponsors.length > 0 && (
                        <section className="border-t border-border py-10">
                            <h2 className="text-center text-xs uppercase tracking-widest text-ink-tertiary">
                                Supported by
                            </h2>
                            <div className="mt-7 flex flex-wrap items-center justify-center gap-x-10 gap-y-6">
                                {event.sponsors.map((sponsor) => (
                                    <div key={sponsor.id} title={sponsor.name}>
                                        {sponsor.logo_url ? (
                                            <img
                                                src={sponsor.logo_url}
                                                alt={sponsor.name}
                                                loading="lazy"
                                                className="h-12 max-w-35 object-contain"
                                            />
                                        ) : (
                                            <span className="text-sm text-ink-secondary">
                                                {sponsor.name}
                                            </span>
                                        )}
                                    </div>
                                ))}
                            </div>
                        </section>
                    )}
                    <footer className="border-t border-border py-6 text-center text-xs text-ink-tertiary">
                        Gather with purpose. Powered by{' '}
                        <a href="/" className="text-ink-secondary hover:text-accent">
                            MiConvener
                        </a>
                    </footer>
                </div>
                <Modal
                    open={dialog === 'share'}
                    onClose={() => setDialog(null)}
                    title="Share this event"
                >
                    <p className="mb-4 text-sm text-ink-secondary">
                        Invite someone to {event.name}.
                        {isPrivate && ' Guests will still need access to this private event.'}
                    </p>
                    <label className="block text-xs text-ink-secondary" htmlFor="event-share-link">
                        Event link
                    </label>
                    <input
                        id="event-share-link"
                        readOnly
                        value={shareUrl}
                        onFocus={(e) => e.target.select()}
                        className="mt-2 w-full border border-border bg-canvas p-3 text-sm text-ink"
                    />
                    <button
                        type="button"
                        onClick={copyLink}
                        className="mt-4 inline-flex items-center gap-2 bg-accent px-4 py-2.5 text-sm font-medium text-white"
                    >
                        {copied ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />}
                        {copied ? 'Copied' : 'Copy link'}
                    </button>
                    <p role="status" className="mt-3 text-xs text-ink-secondary">
                        {copied
                            ? 'Link copied to clipboard.'
                            : copyError
                              ? 'Select and copy the link above to share it.'
                              : ''}
                    </p>
                </Modal>
                <Modal
                    open={dialog === 'contact'}
                    onClose={() => setDialog(null)}
                    title={`Contact ${org.name}`}
                >
                    <p className="text-sm text-ink-secondary">
                        Questions about the event? Reach your organiser directly.
                    </p>
                    <a
                        href={`mailto:${org.contact_email}`}
                        className="mt-4 inline-block break-all text-sm text-accent underline"
                    >
                        {org.contact_email}
                    </a>
                </Modal>
            </article>
        </PublicLayout>
    );
}
