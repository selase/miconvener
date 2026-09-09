import { useEffect, useMemo, useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { Globe, Lock, User, Download, Trophy } from 'lucide-react';
import PublicLayout from '@/Layouts/PublicLayout';
import CoverBars from '@/Components/Console/CoverBars';
import Input from '@/Components/Console/Input';
import Button from '@/Components/Console/Button';
import csrfFetch, { csrfFetchFormData } from '@/lib/csrfFetch';
import respondentToken from '@/lib/respondentToken';
import { getRespondentName, setRespondentName } from '@/lib/respondentName';

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

function SpeakersSection({ speakers }) {
    return (
        <div className="grid grid-cols-2 gap-5">
            {speakers.map((speaker) => (
                <div key={speaker.id} className="flex gap-3.5">
                    {speaker.photo_url ? (
                        <img
                            src={speaker.photo_url}
                            alt={speaker.name}
                            className="h-16 w-16 shrink-0 rounded-full object-cover"
                        />
                    ) : (
                        <div className="flex h-16 w-16 shrink-0 items-center justify-center rounded-full bg-surface-sunken text-ink-secondary">
                            <User className="h-6 w-6" strokeWidth={1.5} />
                        </div>
                    )}
                    <div className="min-w-0">
                        <h3 className="text-[15px] font-medium text-ink">{speaker.name}</h3>
                        <p className="mt-0.5 text-[13px] text-ink-secondary">
                            {[speaker.title, speaker.organization].filter(Boolean).join(', ')}
                        </p>
                        {speaker.bio && (
                            <p className="mt-2 text-[13px] leading-relaxed text-ink-secondary">
                                {speaker.bio}
                            </p>
                        )}
                    </div>
                </div>
            ))}
        </div>
    );
}

function ScheduleSection({ sessions, timezone, icsUrl }) {
    return (
        <div>
            {icsUrl && (
                <a
                    href={icsUrl}
                    className="mb-4 inline-flex h-control items-center gap-1.5 border border-border px-3 text-[12.5px] text-ink-secondary hover:border-accent hover:text-accent"
                >
                    <Download className="h-3.5 w-3.5" strokeWidth={1.75} />
                    Add to calendar
                </a>
            )}
            <ul className="flex flex-col">
                {sessions.map((session) => (
                    <li
                        key={session.id}
                        className="flex items-baseline gap-4 border-t border-border py-3.5 first:border-t-0"
                    >
                        <span className="w-32 shrink-0 font-mono text-[12px] text-ink-secondary">
                            {formatSessionTime(session.starts_at, session.ends_at, timezone)}
                        </span>
                        <div className="min-w-0 flex-1">
                            <div className="text-[14px] text-ink">{session.title}</div>
                            {session.speaker_names?.length > 0 && (
                                <div className="mt-0.5 text-[12px] text-ink-secondary">
                                    {session.speaker_names.join(', ')}
                                </div>
                            )}
                        </div>
                        {session.location && (
                            <span className="shrink-0 text-[12px] text-ink-secondary">
                                {session.location}
                            </span>
                        )}
                    </li>
                ))}
            </ul>
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
        // eslint-disable-next-line react-hooks/exhaustive-deps
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
    const [selected, setSelected] = useState('');
    const [text, setText] = useState('');
    const [name, setName] = useState(getRespondentName());
    const [done, setDone] = useState(false);
    const [feedback, setFeedback] = useState(null);
    const [secondsLeft, setSecondsLeft] = useState(null);

    useEffect(() => {
        const load = () =>
            csrfFetch(route('public.events.poll.show', { event: event.slug }))
                .then((r) => r.json())
                .then((data) => {
                    setPoll((prev) => {
                        if (prev?.id !== data.poll?.id) {
                            setSelected('');
                            setText('');
                            setDone(false);
                            setFeedback(null);
                        }
                        return data.poll;
                    });
                });
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

    const submit = async (e) => {
        e.preventDefault();
        if (poll.type === 'quiz') setRespondentName(name);
        const response = await csrfFetch(
            route('public.events.poll.respond', { event: event.slug, poll: poll.id }),
            {
                method: 'POST',
                body: JSON.stringify({
                    option_id: poll.type !== 'open' ? selected : undefined,
                    response_text: poll.type === 'open' ? text : undefined,
                    respondent_name: poll.type === 'quiz' ? name || undefined : undefined,
                    respondent_token: respondentToken(),
                }),
            }
        );
        const json = await response.json();
        if (response.ok) {
            setDone(true);
            if (poll.type === 'quiz') setFeedback(json);
        }
    };

    if (done) {
        return (
            <div>
                {poll.type === 'quiz' && feedback ? (
                    <p
                        className={`text-sm ${feedback.is_correct ? 'text-accent' : 'text-ink-secondary'}`}
                    >
                        {feedback.is_correct
                            ? `Correct! +${feedback.points_awarded} points.`
                            : 'Not quite — better luck on the next question.'}
                    </p>
                ) : (
                    <p className="text-sm text-accent">Thanks for responding!</p>
                )}
                {poll.type === 'quiz' && <QuizLeaderboard event={event} />}
            </div>
        );
    }

    return (
        <div>
            <form onSubmit={submit} className="max-w-md space-y-3">
                <div className="flex items-baseline justify-between gap-3">
                    <b className="block text-[15px] text-ink">{poll.question}</b>
                    {secondsLeft !== null && (
                        <span
                            className={`shrink-0 font-mono text-sm ${secondsLeft <= 5 ? 'text-danger-fg' : 'text-ink-secondary'}`}
                        >
                            {secondsLeft}s
                        </span>
                    )}
                </div>

                {poll.type === 'quiz' && (
                    <input
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        placeholder="Your name, for the leaderboard (optional)"
                        className="w-full border border-border px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none"
                    />
                )}

                {poll.type === 'multiple_choice' || poll.type === 'quiz' ? (
                    <div className="space-y-2">
                        {poll.options.map((o) => (
                            <label
                                key={o.id}
                                className={`flex cursor-pointer items-center gap-2.5 border px-3.5 py-2.5 text-[13.5px] ${selected === o.id ? 'border-accent bg-accent-soft' : 'border-border'}`}
                            >
                                <input
                                    type="radio"
                                    name="poll_option"
                                    value={o.id}
                                    checked={selected === o.id}
                                    onChange={(e) => setSelected(e.target.value)}
                                    required
                                />
                                {o.label}
                            </label>
                        ))}
                    </div>
                ) : (
                    <textarea
                        value={text}
                        onChange={(e) => setText(e.target.value)}
                        rows={3}
                        required
                        className="w-full border border-border px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none"
                    />
                )}
                <Button type="submit" variant="primary" disabled={timeUp}>
                    {timeUp ? "Time's up" : 'Submit response'}
                </Button>
            </form>
            {poll.type === 'quiz' && <QuizLeaderboard event={event} />}
        </div>
    );
}

function RegistrationPanel({ event }) {
    const { flash } = usePage().props;
    const hasTicketTypes = event.ticket_types.length > 0;
    const { data, setData, post, processing, errors } = useForm({
        full_name: '',
        email: '',
        phone: '',
        dietary_requirements: '',
        accessibility_needs: '',
        ticket_type_id: hasTicketTypes ? event.ticket_types[0].id : '',
    });
    const [showExtras, setShowExtras] = useState(false);

    const selectedTicketType = hasTicketTypes
        ? event.ticket_types.find((t) => t.id === data.ticket_type_id)
        : null;
    const price = selectedTicketType ? selectedTicketType.price : event.ticket_price;

    const submit = (e) => {
        e.preventDefault();
        post(route('public.events.register', { event: event.slug }));
    };

    return (
        <aside className="border border-border bg-surface p-5 lg:sticky lg:top-6">
            <h3 className="text-[15px] font-medium text-ink">Register</h3>

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
                    <div className="flex flex-col gap-px bg-border">
                        {event.ticket_types.map((ticketType) => (
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
                                        onChange={(e) => setData('ticket_type_id', e.target.value)}
                                        className="mt-0.5"
                                    />
                                    <span>
                                        <span className="block text-[13.5px] text-ink">
                                            {ticketType.name}
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
                )}
                {errors.ticket_type_id && (
                    <p className="text-[13px] text-danger-fg">{errors.ticket_type_id}</p>
                )}

                <Input
                    label="Full name"
                    type="text"
                    value={data.full_name}
                    onChange={(e) => setData('full_name', e.target.value)}
                    error={errors.full_name}
                />
                <Input
                    label="Email"
                    type="email"
                    value={data.email}
                    onChange={(e) => setData('email', e.target.value)}
                    error={errors.email}
                />
                <Input
                    label="Phone (optional)"
                    type="tel"
                    value={data.phone}
                    onChange={(e) => setData('phone', e.target.value)}
                    error={errors.phone}
                />

                {showExtras ? (
                    <>
                        <Input
                            label="Dietary requirements (optional)"
                            type="text"
                            placeholder="e.g. Vegetarian, nut allergy"
                            value={data.dietary_requirements}
                            onChange={(e) => setData('dietary_requirements', e.target.value)}
                            error={errors.dietary_requirements}
                        />
                        <Input
                            label="Accessibility needs (optional)"
                            type="text"
                            placeholder="e.g. Step-free access, sign language"
                            value={data.accessibility_needs}
                            onChange={(e) => setData('accessibility_needs', e.target.value)}
                            error={errors.accessibility_needs}
                        />
                    </>
                ) : (
                    <button
                        type="button"
                        onClick={() => setShowExtras(true)}
                        className="text-[12.5px] text-ink-secondary underline hover:text-accent"
                    >
                        Add dietary or accessibility needs
                    </button>
                )}

                <Button
                    type="submit"
                    disabled={processing}
                    variant="primary"
                    className="w-full justify-center"
                >
                    {selectedTicketType?.is_sold_out
                        ? 'Join waitlist'
                        : price > 0
                          ? `Continue to payment`
                          : 'Register'}
                </Button>
            </form>

            <p className="mt-3.5 text-[11.5px] leading-relaxed text-ink-secondary">
                Card and mobile money accepted where connected. Your entry code arrives by email the
                moment payment clears.
            </p>

            <FindMyTicket event={event} />
        </aside>
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

export default function Show({ event, org, isPrivate = false }) {
    const workshops = useMemo(
        () => event.sessions.filter((s) => s.type === 'workshop'),
        [event.sessions]
    );

    const sections = useMemo(() => {
        const list = [{ key: 'about', label: 'About' }];
        if (event.sessions.length > 0) list.push({ key: 'schedule', label: 'Programme' });
        if (event.speakers.length > 0) list.push({ key: 'speakers', label: 'Speakers' });
        if (workshops.length > 0) list.push({ key: 'workshops', label: 'Workshops' });
        if (event.plan_your_visit_content) list.push({ key: 'visit', label: 'Plan Your Visit' });
        list.push({ key: 'qa', label: 'Q&A' });
        list.push({ key: 'live', label: 'Live poll' });
        return list;
    }, [event, workshops]);

    const [active, setActive] = useState('about');
    const price = lowestPrice(event);

    return (
        <PublicLayout>
            {event.hero_image_url ? (
                <img
                    src={event.hero_image_url}
                    alt={event.name}
                    className="h-56 w-full border-b border-border object-cover sm:h-72"
                />
            ) : (
                <CoverBars height={220} />
            )}

            <div className="mx-auto max-w-[1100px] px-6 pt-8 sm:px-10">
                {/* The badge was hardcoded open, which on a private event told a
                    visitor the opposite of the truth. */}
                {isPrivate ? (
                    <span className="inline-flex items-center gap-1.5 border border-border px-2 py-0.5 text-[11.5px] text-ink-secondary">
                        <Lock className="h-3 w-3" strokeWidth={1.75} />
                        Private event
                    </span>
                ) : (
                    <span className="inline-flex items-center gap-1.5 border border-accent px-2 py-0.5 text-[11.5px] text-accent">
                        <Globe className="h-3 w-3" strokeWidth={1.75} />
                        Open to anyone
                    </span>
                )}

                <h1 className="mt-4 text-[clamp(32px,6vw,64px)] font-normal leading-[0.98] tracking-tighter text-ink">
                    {event.name}
                </h1>
                <p className="mt-3.5 text-[13.5px] text-ink-secondary">Hosted by {org.name}</p>
                {isPrivate && (
                    <p className="mt-2 max-w-xl text-[13px] text-ink-secondary">
                        The speakers, programme and materials for this event are shown to registered
                        guests only.
                    </p>
                )}

                <div className="mt-8 grid grid-cols-2 border-t border-border sm:grid-cols-4">
                    <div className="border-r border-border py-4 pr-5">
                        <span className="block text-[11.5px] text-ink-tertiary">When</span>
                        <b className="mt-1.5 block text-[14px] font-normal text-ink">
                            {formatDateRange(event.starts_at, event.ends_at, event.timezone)}
                        </b>
                    </div>
                    <div className="border-r border-border py-4 pr-5 pl-5 sm:pl-0">
                        <span className="block text-[11.5px] text-ink-tertiary">Where</span>
                        <b className="mt-1.5 block truncate text-[14px] font-normal text-ink">
                            {event.location_type === 'virtual' ? 'Virtual' : event.address}
                        </b>
                    </div>
                    <div className="border-r border-border py-4 pr-5 pl-5 sm:border-r sm:pl-5">
                        <span className="block text-[11.5px] text-ink-tertiary">Format</span>
                        <b className="mt-1.5 block text-[14px] font-normal text-ink">
                            {event.location_type === 'virtual' ? 'Online' : 'In person'}
                        </b>
                    </div>
                    <div className="py-4 pl-5">
                        <span className="block text-[11.5px] text-ink-tertiary">From</span>
                        <b className="mt-1.5 block font-mono text-[14px] font-normal text-ink">
                            {formatMoney(price, event.currency)}
                        </b>
                    </div>
                </div>

                <nav className="mt-1 flex gap-0 overflow-x-auto border-b border-border">
                    {sections.map((section) => (
                        <button
                            key={section.key}
                            type="button"
                            onClick={() => setActive(section.key)}
                            className={`-mb-px shrink-0 border-b px-4 py-3 text-[13px] transition-colors first:pl-0 ${
                                active === section.key
                                    ? 'border-accent text-accent'
                                    : 'border-transparent text-ink-secondary hover:text-ink'
                            }`}
                        >
                            {section.label}
                        </button>
                    ))}
                </nav>

                <div className="grid gap-14 py-11 lg:grid-cols-[1fr_330px]">
                    <div className="min-w-0">
                        {active === 'about' && event.description && (
                            <p className="whitespace-pre-line text-[14px] leading-relaxed text-ink">
                                {event.description}
                            </p>
                        )}
                        {active === 'schedule' && (
                            <ScheduleSection
                                sessions={event.sessions}
                                timezone={event.timezone}
                                icsUrl={route('public.events.schedule.ics', { event: event.slug })}
                            />
                        )}
                        {active === 'speakers' && <SpeakersSection speakers={event.speakers} />}
                        {active === 'workshops' && (
                            <ScheduleSection sessions={workshops} timezone={event.timezone} />
                        )}
                        {active === 'visit' && (
                            <p className="whitespace-pre-line text-[14px] leading-relaxed text-ink">
                                {event.plan_your_visit_content}
                            </p>
                        )}
                        {active === 'qa' && <ForumSection event={event} />}
                        {active === 'live' && <LivePollSection event={event} />}
                    </div>

                    <RegistrationPanel event={event} />
                </div>

                {event.sponsors.length > 0 && (
                    <div className="border-t border-border py-10">
                        <p className="text-center text-[11px] uppercase tracking-wide text-ink-tertiary">
                            Supported by
                        </p>
                        <div className="mt-5 flex flex-wrap items-center justify-center gap-x-10 gap-y-6">
                            {event.sponsors.map((sponsor) => (
                                <div key={sponsor.id} title={sponsor.name}>
                                    {sponsor.logo_url ? (
                                        <img
                                            src={sponsor.logo_url}
                                            alt={sponsor.name}
                                            className="h-9 max-w-35 object-contain"
                                        />
                                    ) : (
                                        <span className="text-[13px] text-ink-secondary">
                                            {sponsor.name}
                                        </span>
                                    )}
                                </div>
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </PublicLayout>
    );
}
