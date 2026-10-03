import { useMemo, useState } from 'react';
import {
    Trash2,
    Plus,
    GripVertical,
    Download,
    AlertTriangle,
    Repeat,
    RefreshCw,
    FileText,
    ExternalLink,
    Edit3,
    Calendar,
    Clock,
    Ban,
    BarChart2,
    Copy,
    Check,
} from 'lucide-react';
import Input from '@/Components/Console/Input';
import Select from '@/Components/Console/Select';
import Button from '@/Components/Console/Button';
import Modal from '@/Components/Console/Modal';
import { useToast } from '@/Components/Console/Toast';
import csrfFetch from '@/lib/csrfFetch';

const TYPES = [
    'session',
    'service',
    'lecture',
    'lab',
    'bible_study',
    'prayer',
    'keynote',
    'plenary',
    'workshop',
    'breakout',
    'panel',
    'break',
    'networking',
];

const EMPTY = {
    title: '',
    starts_at: '',
    ends_at: '',
    location: '',
    track: '',
    type: 'session',
    capacity: '',
    speaker_ids: [],
};

function formatTime(iso) {
    return new Date(iso).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
}

function formatDay(iso) {
    return new Date(iso).toLocaleDateString(undefined, {
        weekday: 'long',
        month: 'long',
        day: 'numeric',
    });
}

function dayKey(iso) {
    const d = new Date(iso);
    return `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}`;
}

function groupByDay(sessions) {
    const groups = new Map();
    for (const session of sessions) {
        const key = dayKey(session.starts_at);
        if (!groups.has(key)) groups.set(key, []);
        groups.get(key).push(session);
    }
    return Array.from(groups.values());
}

export default function SchedulePanel({ event, sessions, speakers, onChange }) {
    const [form, setForm] = useState(EMPTY);
    const [saving, setSaving] = useState(false);
    const [generating, setGenerating] = useState(false);
    const [editingOccurrence, setEditingOccurrence] = useState(null);
    const [savingOccurrence, setSavingOccurrence] = useState(false);
    const [dragging, setDragging] = useState(null);
    const toast = useToast();

    const days = useMemo(() => groupByDay(sessions), [sessions]);

    const handleGenerateRecurrence = async () => {
        setGenerating(true);
        try {
            const res = await csrfFetch(
                route('tenant.events.recurrence.generate', { event: event.id }),
                {
                    method: 'POST',
                    body: JSON.stringify({ weeks: 4 }),
                }
            );
            const json = await res.json();
            toast?.(json.message || 'Occurrences synchronized.');
            onChange();
        } catch {
            toast?.('Could not generate recurring sessions.');
        } finally {
            setGenerating(false);
        }
    };

    const handleSaveOccurrence = async (e) => {
        e.preventDefault();
        if (!editingOccurrence) return;

        setSavingOccurrence(true);
        try {
            const res = await csrfFetch(
                route('tenant.events.sessions.occurrence', {
                    event: event.id,
                    session: editingOccurrence.id,
                }),
                {
                    method: 'PATCH',
                    body: JSON.stringify({
                        title: editingOccurrence.title,
                        type: editingOccurrence.type,
                        location: editingOccurrence.location,
                        occurrence_status: editingOccurrence.occurrence_status,
                        notes: editingOccurrence.notes || null,
                        presentation_url: editingOccurrence.presentation_url || null,
                        speaker_ids: editingOccurrence.speaker_ids || [],
                    }),
                }
            );
            const json = await res.json();
            toast?.(json.message || 'Occurrence updated.');
            setEditingOccurrence(null);
            onChange();
        } catch {
            toast?.('Could not update occurrence.');
        } finally {
            setSavingOccurrence(false);
        }
    };

    const [batchCancelOpen, setBatchCancelOpen] = useState(false);
    const [cancelForm, setCancelForm] = useState({ start_date: '', end_date: '', reason: 'Public Holiday' });
    const [submittingCancel, setSubmittingCancel] = useState(false);

    const [batchRescheduleOpen, setBatchRescheduleOpen] = useState(false);
    const [rescheduleForm, setRescheduleForm] = useState({
        time_start: event.recurrence_time_start || '09:00',
        time_end: event.recurrence_time_end || '11:00',
        future_only: true,
        update_series_defaults: true,
    });
    const [submittingReschedule, setSubmittingReschedule] = useState(false);

    const [feedModalOpen, setFeedModalOpen] = useState(false);
    const [copiedFeed, setCopiedFeed] = useState(false);

    const [analyticsModalOpen, setAnalyticsModalOpen] = useState(false);
    const [analyticsData, setAnalyticsData] = useState(null);
    const [loadingAnalytics, setLoadingAnalytics] = useState(false);

    const handleBatchCancel = async (e) => {
        e.preventDefault();
        setSubmittingCancel(true);
        try {
            const res = await csrfFetch(
                route('tenant.events.occurrences.batch-cancel', { event: event.id }),
                {
                    method: 'POST',
                    body: JSON.stringify(cancelForm),
                }
            );
            const json = await res.json();
            toast?.(json.message || 'Occurrences blacked out / cancelled.');
            setBatchCancelOpen(false);
            onChange();
        } catch {
            toast?.('Could not batch cancel occurrences.');
        } finally {
            setSubmittingCancel(false);
        }
    };

    const handleBatchReschedule = async (e) => {
        e.preventDefault();
        setSubmittingReschedule(true);
        try {
            const res = await csrfFetch(
                route('tenant.events.occurrences.batch-reschedule', { event: event.id }),
                {
                    method: 'POST',
                    body: JSON.stringify(rescheduleForm),
                }
            );
            const json = await res.json();
            toast?.(json.message || 'Occurrences rescheduled.');
            setBatchRescheduleOpen(false);
            onChange();
        } catch {
            toast?.('Could not reschedule occurrences.');
        } finally {
            setSubmittingReschedule(false);
        }
    };

    const loadAnalytics = async () => {
        setAnalyticsModalOpen(true);
        setLoadingAnalytics(true);
        try {
            const res = await csrfFetch(
                route('tenant.events.occurrences.analytics', { event: event.id })
            );
            const json = await res.json();
            setAnalyticsData(json);
        } catch {
            toast?.('Could not load series analytics.');
        } finally {
            setLoadingAnalytics(false);
        }
    };

    const copyFeedUrl = () => {
        const url = `${window.location.origin}/e/${event.slug}/calendar.ics`;
        navigator.clipboard.writeText(url);
        setCopiedFeed(true);
        toast?.('Calendar subscription URL copied to clipboard.');
        setTimeout(() => setCopiedFeed(false), 3000);
    };

    const addSession = async (e) => {
        e.preventDefault();
        setSaving(true);

        const response = await csrfFetch(
            route('tenant.events.sessions.store', { event: event.id }),
            {
                method: 'POST',
                body: JSON.stringify({ ...form, capacity: form.capacity || null }),
            }
        );
        const json = await response.json();

        setSaving(false);
        setForm(EMPTY);
        onChange();

        if (json.clashes?.length > 0) {
            toast?.(`Added, but it clashes with: ${json.clashes.map((c) => c.title).join(', ')}`);
        }
    };

    const removeSession = async (session) => {
        await csrfFetch(
            route('tenant.events.sessions.destroy', { event: event.id, session: session.id }),
            { method: 'DELETE' }
        );
        onChange();
    };

    const toggleSpeaker = (speakerId) => {
        setForm((prev) => ({
            ...prev,
            speaker_ids: prev.speaker_ids.includes(speakerId)
                ? prev.speaker_ids.filter((id) => id !== speakerId)
                : [...prev.speaker_ids, speakerId],
        }));
    };

    const reorderDay = async (dayList, fromIndex, toIndex) => {
        const reordered = [...dayList];
        const [moved] = reordered.splice(fromIndex, 1);
        reordered.splice(toIndex, 0, moved);

        await csrfFetch(route('tenant.events.sessions.reorder', { event: event.id }), {
            method: 'PATCH',
            body: JSON.stringify({ session_ids: reordered.map((s) => s.id) }),
        });
        onChange();
    };

    return (
        <div className="max-w-2xl">
            {event.is_recurring && (
                <div className="mb-6 rounded-lg border border-border bg-surface-sunken/40 p-4 space-y-3.5">
                    <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                        <div className="flex items-start gap-3">
                            <Repeat className="h-5 w-5 text-accent shrink-0 mt-0.5" />
                            <div>
                                <h3 className="text-sm font-semibold text-ink">
                                    Recurring Gathering Series
                                </h3>
                                <p className="text-xs text-ink-secondary mt-0.5">
                                    {event.recurrence_summary || 'Weekly recurring schedule'}
                                </p>
                            </div>
                        </div>
                        <Button
                            type="button"
                            variant="default"
                            onClick={handleGenerateRecurrence}
                            disabled={generating}
                            className="shrink-0"
                        >
                            <RefreshCw className={`h-4 w-4 mr-1.5 ${generating ? 'animate-spin' : ''}`} />
                            {generating ? 'Synchronizing...' : 'Generate Next 4 Weeks'}
                        </Button>
                    </div>

                    <div className="flex items-center gap-2 pt-3 border-t border-border flex-wrap">
                        <Button
                            type="button"
                            variant="default"
                            onClick={() => setBatchCancelOpen(true)}
                        >
                            <Ban className="h-3.5 w-3.5 mr-1.5 text-rose-500" />
                            Holiday Blackout
                        </Button>
                        <Button
                            type="button"
                            variant="default"
                            onClick={() => setBatchRescheduleOpen(true)}
                        >
                            <Clock className="h-3.5 w-3.5 mr-1.5 text-accent" />
                            Shift Times
                        </Button>
                        <Button
                            type="button"
                            variant="default"
                            onClick={() => setFeedModalOpen(true)}
                        >
                            <Calendar className="h-3.5 w-3.5 mr-1.5 text-emerald-600" />
                            Calendar Feed (.ics)
                        </Button>
                        <Button
                            type="button"
                            variant="default"
                            onClick={loadAnalytics}
                        >
                            <BarChart2 className="h-3.5 w-3.5 mr-1.5 text-indigo-600" />
                            Series Retention
                        </Button>
                    </div>
                </div>
            )}

            <div className="mb-5 flex items-center justify-between">
                <p className="text-sm text-ink-secondary">
                    Drag a session within its day to reorder — times shift to stay back-to-back.
                </p>
                <a
                    href={route('public.events.schedule.ics', { event: event.slug })}
                    className="inline-flex h-control items-center gap-2 border border-border px-4 text-sm text-ink hover:border-accent"
                >
                    <Download className="h-4 w-4" strokeWidth={1.75} />
                    Export programme (.ics)
                </a>
            </div>

            {days.length > 0 ? (
                <div className="mb-6 space-y-6">
                    {days.map((dayList) => (
                        <div key={dayKey(dayList[0].starts_at)}>
                            <b className="mb-2 block text-xs uppercase tracking-wide text-ink-secondary">
                                {formatDay(dayList[0].starts_at)}
                            </b>
                            <ul className="divide-y divide-border border border-border">
                                {dayList.map((session, index) => (
                                    <li
                                        key={session.id}
                                        draggable={dayList.length > 1}
                                        onDragStart={() =>
                                            setDragging({
                                                dayKey: dayKey(session.starts_at),
                                                index,
                                            })
                                        }
                                        onDragOver={(e) => e.preventDefault()}
                                        onDrop={(e) => {
                                            e.preventDefault();
                                            if (
                                                dragging &&
                                                dragging.dayKey === dayKey(session.starts_at) &&
                                                dragging.index !== index
                                            ) {
                                                reorderDay(dayList, dragging.index, index);
                                            }
                                            setDragging(null);
                                        }}
                                        className={`flex items-center gap-3 px-4 py-3 ${dayList.length > 1 ? 'cursor-grab' : ''}`}
                                    >
                                        {dayList.length > 1 && (
                                            <GripVertical
                                                className="h-4 w-4 shrink-0 text-ink-tertiary"
                                                strokeWidth={1.75}
                                            />
                                        )}
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center gap-2 flex-wrap">
                                                <span className="text-sm font-medium text-ink">
                                                    {session.title}
                                                </span>
                                                {session.is_occurrence && (
                                                    <span className={`px-1.5 py-0.5 rounded text-[10px] font-mono uppercase tracking-wider ${
                                                        session.occurrence_status === 'completed'
                                                            ? 'bg-success-bg text-success-fg'
                                                            : session.occurrence_status === 'cancelled'
                                                            ? 'bg-danger-bg text-danger-fg'
                                                            : 'bg-surface-sunken text-ink-secondary border border-border'
                                                    }`}>
                                                        {session.occurrence_status || 'scheduled'}
                                                    </span>
                                                )}
                                            </div>
                                            <div className="text-xs text-ink-secondary flex items-center flex-wrap gap-1.5 mt-0.5">
                                                <span>
                                                    {formatTime(session.starts_at)}–{formatTime(session.ends_at)} · {session.type}
                                                </span>
                                                {session.location ? <span>· {session.location}</span> : null}
                                                {session.speaker_names?.length ? (
                                                    <span>· {session.speaker_names.join(', ')}</span>
                                                ) : null}
                                                {session.capacity ? (
                                                    <span>· {session.signup_count ?? 0}/{session.capacity} signed up</span>
                                                ) : null}
                                                {session.attendances_count > 0 ? (
                                                    <span className="text-accent font-medium">· {session.attendances_count} attended</span>
                                                ) : null}
                                                {session.notes && (
                                                    <span className="inline-flex items-center gap-0.5 text-accent font-medium">
                                                        <FileText className="h-3 w-3" /> Notes
                                                    </span>
                                                )}
                                                {session.presentation_url && (
                                                    <a
                                                        href={session.presentation_url}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="inline-flex items-center gap-0.5 text-accent hover:underline font-medium"
                                                    >
                                                        <ExternalLink className="h-3 w-3" /> Deck
                                                    </a>
                                                )}
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-1.5 shrink-0">
                                            {session.is_occurrence && (
                                                <button
                                                    type="button"
                                                    onClick={() => setEditingOccurrence({
                                                        ...session,
                                                        notes: session.notes || '',
                                                        presentation_url: session.presentation_url || '',
                                                        speaker_ids: session.speaker_ids || session.speakers?.map((s) => s.id) || [],
                                                    })}
                                                    className="p-1 text-ink-secondary hover:text-accent rounded"
                                                    title="Edit sermon notes, presentation, & occurrence details"
                                                >
                                                    <Edit3 className="h-4 w-4" strokeWidth={1.75} />
                                                </button>
                                            )}
                                            <button
                                                type="button"
                                                onClick={() => removeSession(session)}
                                                className="shrink-0 text-ink-secondary hover:text-danger-fg"
                                            >
                                                <Trash2 className="h-4 w-4" strokeWidth={1.75} />
                                            </button>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))}
                </div>
            ) : (
                <p className="mb-6 text-sm text-ink-secondary">No schedule items yet.</p>
            )}

            <div className="border border-border p-4">
                <b className="mb-3 block text-sm font-medium text-ink">Add schedule item</b>
                <form onSubmit={addSession} className="space-y-3">
                    <Input
                        label="Title"
                        value={form.title}
                        onChange={(e) => setForm({ ...form, title: e.target.value })}
                        required
                    />

                    <div className="grid grid-cols-2 gap-3">
                        <Input
                            label="Starts"
                            type="datetime-local"
                            value={form.starts_at}
                            onChange={(e) => setForm({ ...form, starts_at: e.target.value })}
                            required
                        />
                        <Input
                            label="Ends"
                            type="datetime-local"
                            value={form.ends_at}
                            onChange={(e) => setForm({ ...form, ends_at: e.target.value })}
                            required
                        />
                    </div>

                    <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                        <Input
                            label="Location"
                            value={form.location}
                            onChange={(e) => setForm({ ...form, location: e.target.value })}
                        />
                        <Input
                            label="Track"
                            value={form.track}
                            onChange={(e) => setForm({ ...form, track: e.target.value })}
                        />
                        <Select
                            label="Type"
                            value={form.type}
                            onChange={(e) => setForm({ ...form, type: e.target.value })}
                        >
                            {event.lexicon?.session_types ? (
                                Object.entries(event.lexicon.session_types).map(([key, label]) => (
                                    <option key={key} value={key}>
                                        {label}
                                    </option>
                                ))
                            ) : (
                                TYPES.map((t) => (
                                    <option key={t} value={t}>
                                        {t.replace('_', ' ')}
                                    </option>
                                ))
                            )}
                        </Select>
                        <Input
                            label="Capacity"
                            type="number"
                            min="1"
                            placeholder="Unlimited"
                            value={form.capacity}
                            onChange={(e) => setForm({ ...form, capacity: e.target.value })}
                        />
                    </div>
                    <p className="-mt-2 text-xs text-ink-secondary">
                        Set a capacity for workshops with limited seats — attendees can no longer
                        add it to their day once full.
                    </p>

                    {speakers.length > 0 && (
                        <div>
                            <label className="mb-1.5 block text-sm font-medium text-ink">
                                Speakers
                            </label>
                            <div className="flex flex-wrap gap-2">
                                {speakers.map((speaker) => (
                                    <button
                                        type="button"
                                        key={speaker.id}
                                        onClick={() => toggleSpeaker(speaker.id)}
                                        className={`border px-3 py-1 text-xs font-medium ${
                                            form.speaker_ids.includes(speaker.id)
                                                ? 'border-accent bg-accent-soft text-accent'
                                                : 'border-border text-ink-secondary'
                                        }`}
                                    >
                                        {speaker.name}
                                    </button>
                                ))}
                            </div>
                        </div>
                    )}

                    <Button type="submit" icon={Plus} variant="primary" disabled={saving}>
                        Add to schedule
                    </Button>
                </form>
            </div>

            {days.some(
                (d) =>
                    d.length > 1 &&
                    d.some(
                        (s) =>
                            s.location && d.some((o) => o.id !== s.id && o.location === s.location)
                    )
            ) && (
                <p className="mt-3 flex items-center gap-1.5 text-xs text-warning-fg">
                    <AlertTriangle className="h-3.5 w-3.5" strokeWidth={1.75} />
                    Some sessions share a room at an overlapping time — check the schedule above.
                </p>
            )}

            {editingOccurrence && (
                <Modal
                    open
                    onClose={() => setEditingOccurrence(null)}
                    title={`Edit Gathering: ${editingOccurrence.title}`}
                    className="max-w-xl"
                >
                    <form onSubmit={handleSaveOccurrence} className="space-y-4">
                        <Input
                            label="Title / Topic"
                            value={editingOccurrence.title}
                            onChange={(e) =>
                                setEditingOccurrence({ ...editingOccurrence, title: e.target.value })
                            }
                            required
                        />

                        <div className="grid grid-cols-2 gap-3">
                            <Select
                                label="Session Type"
                                value={editingOccurrence.type}
                                onChange={(e) =>
                                    setEditingOccurrence({ ...editingOccurrence, type: e.target.value })
                                }
                            >
                                {event.lexicon?.session_types ? (
                                    Object.entries(event.lexicon.session_types).map(([key, label]) => (
                                        <option key={key} value={key}>
                                            {label}
                                        </option>
                                    ))
                                ) : (
                                    TYPES.map((t) => (
                                        <option key={t} value={t}>
                                            {t.replace('_', ' ')}
                                        </option>
                                    ))
                                )}
                            </Select>

                            <Select
                                label="Status"
                                value={editingOccurrence.occurrence_status || 'scheduled'}
                                onChange={(e) =>
                                    setEditingOccurrence({
                                        ...editingOccurrence,
                                        occurrence_status: e.target.value,
                                    })
                                }
                            >
                                <option value="scheduled">Scheduled</option>
                                <option value="completed">Completed</option>
                                <option value="cancelled">Cancelled</option>
                            </Select>
                        </div>

                        <Input
                            label="Location / Room"
                            value={editingOccurrence.location || ''}
                            onChange={(e) =>
                                setEditingOccurrence({ ...editingOccurrence, location: e.target.value })
                            }
                            placeholder="e.g. Main Sanctuary, Lecture Hall 1"
                        />

                        {speakers?.length > 0 && (
                            <div>
                                <label className="mb-1.5 block text-xs font-medium text-ink">
                                    {event.event_category === 'faith'
                                        ? 'Preacher / Minister'
                                        : event.event_category === 'academic'
                                          ? 'Lecturer / Instructor'
                                          : event.event_category === 'memorial'
                                            ? 'Officiant / Speaker'
                                            : 'Speaker / Presenter'}
                                </label>
                                <div className="flex flex-wrap gap-2">
                                    {speakers.map((s) => {
                                        const isSelected = editingOccurrence.speaker_ids?.includes(s.id);
                                        return (
                                            <button
                                                key={s.id}
                                                type="button"
                                                onClick={() => {
                                                    const cur = editingOccurrence.speaker_ids || [];
                                                    setEditingOccurrence({
                                                        ...editingOccurrence,
                                                        speaker_ids: isSelected
                                                            ? cur.filter((id) => id !== s.id)
                                                            : [...cur, s.id],
                                                    });
                                                }}
                                                className={`px-3 py-1.5 rounded-md text-xs font-medium border transition-colors ${
                                                    isSelected
                                                        ? 'bg-accent text-white border-accent'
                                                        : 'bg-surface border-border text-ink-secondary hover:text-ink'
                                                }`}
                                            >
                                                {s.name}
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>
                        )}

                        <div>
                            <label className="mb-1.5 block text-xs font-medium text-ink">
                                {event.lexicon?.notes_label || 'Session Notes & Handouts'}
                            </label>
                            <textarea
                                value={editingOccurrence.notes || ''}
                                onChange={(e) =>
                                    setEditingOccurrence({ ...editingOccurrence, notes: e.target.value })
                                }
                                rows={4}
                                placeholder={event.lexicon?.notes_placeholder || 'Add key notes, outlines, or references...'}
                                className="w-full rounded-lg border border-border px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                            />
                        </div>

                        <Input
                            label="Presentation / Slides URL"
                            type="url"
                            value={editingOccurrence.presentation_url || ''}
                            onChange={(e) =>
                                setEditingOccurrence({
                                    ...editingOccurrence,
                                    presentation_url: e.target.value,
                                })
                            }
                            placeholder="https://docs.google.com/presentation/... or Canva deck"
                            hint="Link to Google Slides, Canva deck, or YouTube stream recording."
                        />

                        <div className="flex items-center justify-end gap-2 pt-2 border-t border-border">
                            <Button
                                type="button"
                                variant="default"
                                onClick={() => setEditingOccurrence(null)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" variant="primary" disabled={savingOccurrence}>
                                {savingOccurrence ? 'Saving...' : 'Save Gathering Details'}
                            </Button>
                        </div>
                    </form>
                </Modal>
            )}

            {batchCancelOpen && (
                <Modal
                    open={batchCancelOpen}
                    onClose={() => setBatchCancelOpen(false)}
                    title="Blackout / Cancel Occurrences"
                >
                    <form onSubmit={handleBatchCancel} className="space-y-4">
                        <p className="text-xs text-ink-secondary">
                            Mark occurrences within a date window as cancelled (e.g. national holidays, exam breaks, or facility maintenance).
                        </p>

                        <div className="grid grid-cols-2 gap-3">
                            <Input
                                label="From Date"
                                type="date"
                                value={cancelForm.start_date}
                                onChange={(e) =>
                                    setCancelForm({ ...cancelForm, start_date: e.target.value })
                                }
                                required
                            />
                            <Input
                                label="To Date"
                                type="date"
                                value={cancelForm.end_date}
                                onChange={(e) =>
                                    setCancelForm({ ...cancelForm, end_date: e.target.value })
                                }
                                required
                            />
                        </div>

                        <Input
                            label="Reason for Blackout / Cancellation"
                            value={cancelForm.reason}
                            onChange={(e) =>
                                setCancelForm({ ...cancelForm, reason: e.target.value })
                            }
                            placeholder="e.g. Public Holiday, Easter Break, Hall Renovation"
                            required
                        />

                        <div className="flex items-center justify-end gap-2 pt-2 border-t border-border">
                            <Button
                                type="button"
                                variant="default"
                                onClick={() => setBatchCancelOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" variant="primary" disabled={submittingCancel}>
                                {submittingCancel ? 'Cancelling...' : 'Confirm Blackout'}
                            </Button>
                        </div>
                    </form>
                </Modal>
            )}

            {batchRescheduleOpen && (
                <Modal
                    open={batchRescheduleOpen}
                    onClose={() => setBatchRescheduleOpen(false)}
                    title="Shift Gathering Schedule Times"
                >
                    <form onSubmit={handleBatchReschedule} className="space-y-4">
                        <p className="text-xs text-ink-secondary">
                            Bulk update the start and end times across all upcoming scheduled occurrences in this series.
                        </p>

                        <div className="grid grid-cols-2 gap-3">
                            <Input
                                label="New Start Time"
                                type="time"
                                value={rescheduleForm.time_start}
                                onChange={(e) =>
                                    setRescheduleForm({ ...rescheduleForm, time_start: e.target.value })
                                }
                                required
                            />
                            <Input
                                label="New End Time"
                                type="time"
                                value={rescheduleForm.time_end}
                                onChange={(e) =>
                                    setRescheduleForm({ ...rescheduleForm, time_end: e.target.value })
                                }
                                required
                            />
                        </div>

                        <div className="space-y-2 pt-1">
                            <label className="flex items-center gap-2 text-xs text-ink cursor-pointer">
                                <input
                                    type="checkbox"
                                    checked={rescheduleForm.future_only}
                                    onChange={(e) =>
                                        setRescheduleForm({ ...rescheduleForm, future_only: e.target.checked })
                                    }
                                    className="rounded border-border text-accent focus:ring-accent"
                                />
                                <span>Apply to future occurrences only (leave past gatherings untouched)</span>
                            </label>

                            <label className="flex items-center gap-2 text-xs text-ink cursor-pointer">
                                <input
                                    type="checkbox"
                                    checked={rescheduleForm.update_series_defaults}
                                    onChange={(e) =>
                                        setRescheduleForm({
                                            ...rescheduleForm,
                                            update_series_defaults: e.target.checked,
                                        })
                                    }
                                    className="rounded border-border text-accent focus:ring-accent"
                                />
                                <span>Update series default times for future auto-generated weeks</span>
                            </label>
                        </div>

                        <div className="flex items-center justify-end gap-2 pt-2 border-t border-border">
                            <Button
                                type="button"
                                variant="default"
                                onClick={() => setBatchRescheduleOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" variant="primary" disabled={submittingReschedule}>
                                {submittingReschedule ? 'Rescheduling...' : 'Apply Time Shift'}
                            </Button>
                        </div>
                    </form>
                </Modal>
            )}

            {feedModalOpen && (
                <Modal
                    open={feedModalOpen}
                    onClose={() => setFeedModalOpen(false)}
                    title="Subscribable Calendar Feed"
                >
                    <div className="space-y-4">
                        <p className="text-xs text-ink-secondary">
                            Share this real-time calendar subscription feed. When you schedule, update, or cancel occurrences, members&apos; Apple Calendar, Google Calendar, and Outlook apps sync automatically.
                        </p>

                        <div className="flex items-center gap-2">
                            <input
                                type="text"
                                readOnly
                                value={typeof window !== 'undefined' ? `${window.location.origin}/e/${event.slug}/calendar.ics` : `/e/${event.slug}/calendar.ics`}
                                className="flex-1 rounded-md border border-border bg-surface-sunken/40 px-3 py-2 text-xs text-ink font-mono select-all"
                            />
                            <Button
                                type="button"
                                variant="default"
                                onClick={copyFeedUrl}
                                className="shrink-0"
                            >
                                {copiedFeed ? <Check className="h-4 w-4 text-emerald-600" /> : <Copy className="h-4 w-4" />}
                                {copiedFeed ? 'Copied' : 'Copy'}
                            </Button>
                        </div>

                        <div className="rounded-md border border-border/80 bg-surface p-3 space-y-2 text-xs text-ink-secondary">
                            <p className="font-semibold text-ink">How to subscribe:</p>
                            <ul className="list-disc pl-4 space-y-1">
                                <li><strong>Apple Calendar (iOS/macOS):</strong> File &rarr; New Calendar Subscription &rarr; Paste URL.</li>
                                <li><strong>Google Calendar:</strong> Other calendars (+) &rarr; From URL &rarr; Paste URL.</li>
                                <li><strong>Microsoft Outlook:</strong> Add Calendar &rarr; Subscribe from web &rarr; Paste URL.</li>
                            </ul>
                        </div>

                        <div className="flex justify-end pt-2 border-t border-border">
                            <Button
                                type="button"
                                variant="default"
                                onClick={() => setFeedModalOpen(false)}
                            >
                                Close
                            </Button>
                        </div>
                    </div>
                </Modal>
            )}

            {analyticsModalOpen && (
                <Modal
                    open={analyticsModalOpen}
                    onClose={() => setAnalyticsModalOpen(false)}
                    title="Series Attendance & Retention Rollup"
                >
                    {loadingAnalytics ? (
                        <div className="py-8 text-center text-xs text-ink-secondary">
                            Loading attendance records...
                        </div>
                    ) : analyticsData ? (
                        <div className="space-y-4">
                            <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
                                <div className="rounded-lg border border-border bg-surface-sunken/40 p-3">
                                    <div className="text-[10px] uppercase font-semibold text-ink-secondary">Total Occurrences</div>
                                    <div className="text-xl font-bold text-ink mt-0.5">{analyticsData.total_occurrences}</div>
                                    <div className="text-[10px] text-ink-muted mt-0.5">
                                        {analyticsData.scheduled_count} active &bull; {analyticsData.cancelled_count} off
                                    </div>
                                </div>
                                <div className="rounded-lg border border-border bg-surface-sunken/40 p-3">
                                    <div className="text-[10px] uppercase font-semibold text-ink-secondary">Unique Attendees</div>
                                    <div className="text-xl font-bold text-ink mt-0.5">{analyticsData.unique_attendees}</div>
                                    <div className="text-[10px] text-ink-muted mt-0.5">All-time check-ins</div>
                                </div>
                                <div className="rounded-lg border border-border bg-surface-sunken/40 p-3">
                                    <div className="text-[10px] uppercase font-semibold text-ink-secondary">Avg Attendance</div>
                                    <div className="text-xl font-bold text-ink mt-0.5">{analyticsData.average_attendance}</div>
                                    <div className="text-[10px] text-ink-muted mt-0.5">Per gathering</div>
                                </div>
                                <div className="rounded-lg border border-border bg-surface-sunken/40 p-3">
                                    <div className="text-[10px] uppercase font-semibold text-ink-secondary">Retention Rate</div>
                                    <div className="text-xl font-bold text-ink mt-0.5">{analyticsData.retention_rate}%</div>
                                    <div className="text-[10px] text-ink-muted mt-0.5">{analyticsData.retained_attendees} returned 2+ times</div>
                                </div>
                            </div>

                            {analyticsData.recent_trend?.length > 0 && (
                                <div>
                                    <h4 className="text-xs font-semibold text-ink mb-2">Recent Gatherings Check-in Rollup</h4>
                                    <div className="divide-y divide-border border border-border rounded-md overflow-hidden text-xs">
                                        {analyticsData.recent_trend.map((occ) => (
                                            <div key={occ.id} className="flex items-center justify-between p-2.5 bg-surface hover:bg-surface-sunken/30">
                                                <div>
                                                    <div className="font-medium text-ink">{occ.title}</div>
                                                    <div className="text-[11px] text-ink-secondary">{occ.date}</div>
                                                </div>
                                                <div className="text-right">
                                                    <span className="font-semibold text-ink">{occ.attendees_count}</span>
                                                    <span className="text-[11px] text-ink-secondary ml-1">checked in</span>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}

                            <div className="flex justify-end pt-2 border-t border-border">
                                <Button
                                    type="button"
                                    variant="default"
                                    onClick={() => setAnalyticsModalOpen(false)}
                                >
                                    Close
                                </Button>
                            </div>
                        </div>
                    ) : null}
                </Modal>
            )}
        </div>
    );
}
