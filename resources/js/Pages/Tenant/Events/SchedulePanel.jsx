import { useMemo, useState } from 'react';
import { Trash2, Plus, GripVertical, Download, AlertTriangle } from 'lucide-react';
import Input from '@/Components/Console/Input';
import Select from '@/Components/Console/Select';
import Button from '@/Components/Console/Button';
import { useToast } from '@/Components/Console/Toast';
import csrfFetch from '@/lib/csrfFetch';

const TYPES = ['keynote', 'plenary', 'workshop', 'breakout', 'panel', 'break', 'networking', 'session'];

const EMPTY = { title: '', starts_at: '', ends_at: '', location: '', track: '', type: 'session', capacity: '', speaker_ids: [] };

function formatTime(iso) {
    return new Date(iso).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
}

function formatDay(iso) {
    return new Date(iso).toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' });
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
    const [dragging, setDragging] = useState(null);
    const toast = useToast();

    const days = useMemo(() => groupByDay(sessions), [sessions]);

    const addSession = async (e) => {
        e.preventDefault();
        setSaving(true);

        const response = await csrfFetch(route('tenant.events.sessions.store', { event: event.id }), {
            method: 'POST',
            body: JSON.stringify({ ...form, capacity: form.capacity || null }),
        });
        const json = await response.json();

        setSaving(false);
        setForm(EMPTY);
        onChange();

        if (json.clashes?.length > 0) {
            toast?.(`Added, but it clashes with: ${json.clashes.map((c) => c.title).join(', ')}`);
        }
    };

    const removeSession = async (session) => {
        await csrfFetch(route('tenant.events.sessions.destroy', { event: event.id, session: session.id }), { method: 'DELETE' });
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
            <div className="mb-5 flex items-center justify-between">
                <p className="text-sm text-ink-secondary">Drag a session within its day to reorder — times shift to stay back-to-back.</p>
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
                            <b className="mb-2 block text-xs uppercase tracking-wide text-ink-secondary">{formatDay(dayList[0].starts_at)}</b>
                            <ul className="divide-y divide-border border border-border">
                                {dayList.map((session, index) => (
                                    <li
                                        key={session.id}
                                        draggable={dayList.length > 1}
                                        onDragStart={() => setDragging({ dayKey: dayKey(session.starts_at), index })}
                                        onDragOver={(e) => e.preventDefault()}
                                        onDrop={(e) => {
                                            e.preventDefault();
                                            if (dragging && dragging.dayKey === dayKey(session.starts_at) && dragging.index !== index) {
                                                reorderDay(dayList, dragging.index, index);
                                            }
                                            setDragging(null);
                                        }}
                                        className={`flex items-center gap-3 px-4 py-3 ${dayList.length > 1 ? 'cursor-grab' : ''}`}
                                    >
                                        {dayList.length > 1 && <GripVertical className="h-4 w-4 shrink-0 text-ink-tertiary" strokeWidth={1.75} />}
                                        <div className="min-w-0 flex-1">
                                            <div className="text-sm font-medium text-ink">{session.title}</div>
                                            <div className="text-xs text-ink-secondary">
                                                {formatTime(session.starts_at)}–{formatTime(session.ends_at)} · {session.type}
                                                {session.location ? ` · ${session.location}` : ''}
                                                {session.speaker_names?.length ? ` · ${session.speaker_names.join(', ')}` : ''}
                                                {session.capacity ? ` · ${session.signup_count ?? 0}/${session.capacity} signed up` : ''}
                                            </div>
                                        </div>
                                        <button type="button" onClick={() => removeSession(session)} className="shrink-0 text-ink-secondary hover:text-danger-fg">
                                            <Trash2 className="h-4 w-4" strokeWidth={1.75} />
                                        </button>
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
                    <Input label="Title" value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} required />

                    <div className="grid grid-cols-2 gap-3">
                        <Input label="Starts" type="datetime-local" value={form.starts_at} onChange={(e) => setForm({ ...form, starts_at: e.target.value })} required />
                        <Input label="Ends" type="datetime-local" value={form.ends_at} onChange={(e) => setForm({ ...form, ends_at: e.target.value })} required />
                    </div>

                    <div className="grid grid-cols-4 gap-3">
                        <Input label="Location" value={form.location} onChange={(e) => setForm({ ...form, location: e.target.value })} />
                        <Input label="Track" value={form.track} onChange={(e) => setForm({ ...form, track: e.target.value })} />
                        <Select label="Type" value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })}>
                            {TYPES.map((t) => (
                                <option key={t} value={t}>{t}</option>
                            ))}
                        </Select>
                        <Input label="Capacity" type="number" min="1" placeholder="Unlimited" value={form.capacity} onChange={(e) => setForm({ ...form, capacity: e.target.value })} />
                    </div>
                    <p className="-mt-2 text-xs text-ink-secondary">
                        Set a capacity for workshops with limited seats — attendees can no longer add it to their day once full.
                    </p>

                    {speakers.length > 0 && (
                        <div>
                            <label className="mb-1.5 block text-sm font-medium text-ink">Speakers</label>
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

                    <Button type="submit" icon={Plus} variant="primary" disabled={saving}>Add to schedule</Button>
                </form>
            </div>

            {days.some((d) => d.length > 1 &&
                d.some((s) => s.location && d.some((o) => o.id !== s.id && o.location === s.location))) && (
                <p className="mt-3 flex items-center gap-1.5 text-xs text-warning-fg">
                    <AlertTriangle className="h-3.5 w-3.5" strokeWidth={1.75} />
                    Some sessions share a room at an overlapping time — check the schedule above.
                </p>
            )}
        </div>
    );
}
