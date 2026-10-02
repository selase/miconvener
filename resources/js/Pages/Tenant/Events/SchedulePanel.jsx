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
                <div className="mb-6 rounded-lg border border-border bg-surface-sunken/40 p-4">
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
                            onClick={handleGenerateRecurrence}
                            disabled={generating}
                            className="shrink-0"
                        >
                            <RefreshCw className={`h-4 w-4 mr-1.5 ${generating ? 'animate-spin' : ''}`} />
                            {generating ? 'Synchronizing...' : 'Generate Next 4 Weeks'}
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
                                                        speaker_ids: session.speaker_ids || [],
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
        </div>
    );
}
