import { useEffect, useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import Button from '@/Components/Console/Button';
import ConfirmModal from '@/Components/Console/ConfirmModal';
import Input from '@/Components/Console/Input';
import SearchInput from '@/Components/Console/SearchInput';
import csrfFetch from '@/lib/csrfFetch';

function buildGrid(room) {
    const rowLetters = Array.from({ length: room.rows }, (_, i) => String.fromCharCode(65 + i));
    return rowLetters.map((letter) => ({
        letter,
        seats: Array.from({ length: room.seats_per_row }, (_, i) => `${letter}-${String(i + 1).padStart(2, '0')}`),
    }));
}

function NewRoomForm({ event, onChange }) {
    const [form, setForm] = useState({ name: '', rows: 6, seats_per_row: 10 });
    const [saving, setSaving] = useState(false);

    const submit = async (e) => {
        e.preventDefault();
        setSaving(true);
        await csrfFetch(route('tenant.events.venue.rooms.store', { event: event.id }), {
            method: 'POST',
            body: JSON.stringify(form),
        });
        setSaving(false);
        setForm({ name: '', rows: 6, seats_per_row: 10 });
        onChange();
    };

    return (
        <form onSubmit={submit} className="grid grid-cols-[1fr_120px_120px_auto] items-end gap-3 border border-border p-4">
            <Input label="Room name" placeholder="e.g. Main Hall" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required />
            <Input label="Rows" type="number" min="1" max="26" value={form.rows} onChange={(e) => setForm({ ...form, rows: e.target.value })} required />
            <Input label="Seats per row" type="number" min="1" max="60" value={form.seats_per_row} onChange={(e) => setForm({ ...form, seats_per_row: e.target.value })} required />
            <Button type="submit" variant="primary" disabled={saving}>Add room</Button>
        </form>
    );
}

function SeatAssignPicker({ event, room, seatLabel, onDone }) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState([]);

    useEffect(() => {
        if (query.length < 2) {
            setResults([]);
            return;
        }
        const t = setTimeout(async () => {
            const res = await csrfFetch(`${route('tenant.events.venue.unseated', { event: event.id })}?q=${encodeURIComponent(query)}`);
            setResults(await res.json());
        }, 250);
        return () => clearTimeout(t);
    }, [query, event.id]);

    const assign = async (registrationId) => {
        await csrfFetch(route('tenant.events.venue.seats.assign', { event: event.id, room: room.id }), {
            method: 'POST',
            body: JSON.stringify({ seat_label: seatLabel, registration_id: registrationId }),
        });
        onDone();
    };

    return (
        <div className="border border-border p-4">
            <div className="mb-2 font-mono text-sm text-ink">Seat {seatLabel}</div>
            <SearchInput placeholder="Search confirmed guests" value={query} onChange={(e) => setQuery(e.target.value)} className="min-w-0 w-full" />
            {results.length > 0 && (
                <ul className="mt-2 divide-y divide-border border border-border">
                    {results.map((r) => (
                        <li key={r.id} className="flex items-center justify-between px-3 py-2">
                            <div>
                                <div className="text-[13px] text-ink">{r.full_name}</div>
                                <div className="text-xs text-ink-secondary">{r.email}</div>
                            </div>
                            <Button onClick={() => assign(r.id)}>Assign</Button>
                        </li>
                    ))}
                </ul>
            )}
            <Button onClick={onDone} className="mt-2">Cancel</Button>
        </div>
    );
}

export default function VenuePanel({ event, venueRooms }) {
    const [roomId, setRoomId] = useState(null);
    const [picked, setPicked] = useState(null);
    const [deleting, setDeleting] = useState(null);
    const [deleteSaving, setDeleteSaving] = useState(false);

    // Falls back to the first room whenever roomId is unset or points at a
    // room that no longer exists (just created, or the selected one was
    // deleted) — a plain useState initial value would go stale here since
    // this component doesn't remount when venueRooms reloads.
    const room = venueRooms.find((r) => r.id === roomId) ?? venueRooms[0];
    const grid = useMemo(() => (room ? buildGrid(room) : []), [room]);
    const assignmentByLabel = useMemo(() => {
        const map = {};
        room?.assignments.forEach((a) => {
            map[a.seat_label] = a;
        });
        return map;
    }, [room]);

    const reload = () => {
        setPicked(null);
        router.reload({ only: ['event'] });
    };

    const unassign = async (assignment) => {
        await csrfFetch(route('tenant.events.venue.seats.unassign', { event: event.id, room: room.id, assignment: assignment.id }), { method: 'DELETE' });
        reload();
    };

    const confirmDeleteRoom = async () => {
        setDeleteSaving(true);
        await csrfFetch(route('tenant.events.venue.rooms.destroy', { event: event.id, room: deleting.id }), { method: 'DELETE' });
        setDeleteSaving(false);
        setDeleting(null);
        if (roomId === deleting.id) setRoomId(null);
        reload();
    };

    if (venueRooms.length === 0) {
        return (
            <div className="max-w-2xl">
                <p className="mb-4 text-sm text-ink-secondary">No rooms set up yet. Add one to start assigning seats.</p>
                <NewRoomForm event={event} onChange={() => router.reload({ only: ['event'] })} />
            </div>
        );
    }

    return (
        <div className="max-w-3xl">
            <div className="mb-5 flex gap-px overflow-x-auto bg-border">
                {venueRooms.map((r) => (
                    <div
                        key={r.id}
                        className={`flex shrink-0 items-center gap-2 bg-surface pl-4 pr-2.5 py-2.5 ${room?.id === r.id ? 'shadow-[inset_0_-2px_0_var(--color-accent)]' : ''}`}
                    >
                        <button
                            onClick={() => {
                                setRoomId(r.id);
                                setPicked(null);
                            }}
                            className="flex flex-col gap-0.5 text-left"
                        >
                            <span className="text-[13px] text-ink">{r.name}</span>
                            <span className="text-[11px] text-ink-secondary">{r.assignments.length}/{r.rows * r.seats_per_row} seated</span>
                        </button>
                        <button
                            onClick={() => setDeleting(r)}
                            title="Delete room"
                            className="text-ink-tertiary hover:text-danger-fg"
                        >
                            <Trash2 className="h-3.5 w-3.5" strokeWidth={1.75} />
                        </button>
                    </div>
                ))}
            </div>

            {room && (
                <div className="grid gap-5 lg:grid-cols-[1fr_280px]">
                    <div className="border border-border p-6">
                        <div className="mx-auto mb-6 w-fit border border-border px-8 py-1.5 text-center text-[11px] uppercase tracking-wide text-ink-secondary">Stage</div>
                        <div className="flex flex-col items-center gap-1.5 overflow-x-auto">
                            {grid.map((row) => (
                                <div key={row.letter} className="flex items-center gap-1.5">
                                    <span className="w-4 font-mono text-[10px] text-ink-secondary">{row.letter}</span>
                                    {row.seats.map((label) => {
                                        const taken = assignmentByLabel[label];
                                        return (
                                            <button
                                                key={label}
                                                title={taken ? taken.registration_name : label}
                                                onClick={() => setPicked(taken ? { assignment: taken, label } : { label })}
                                                className={`h-3.5 w-3.5 border ${taken ? 'border-accent bg-accent' : 'border-border hover:border-accent'} ${picked?.label === label ? 'ring-1 ring-accent' : ''}`}
                                            />
                                        );
                                    })}
                                </div>
                            ))}
                        </div>
                        <div className="mt-6 flex justify-center gap-5 text-[12px] text-ink-secondary">
                            <span className="flex items-center gap-1.5"><span className="h-3 w-3 border border-border" /> Free</span>
                            <span className="flex items-center gap-1.5"><span className="h-3 w-3 border border-accent bg-accent" /> Assigned</span>
                        </div>
                    </div>

                    <div>
                        {!picked && <p className="text-sm text-ink-secondary">Click a seat to assign or view who's in it.</p>}

                        {picked?.assignment && (
                            <div className="border border-border p-4">
                                <div className="font-mono text-sm text-ink">Seat {picked.label}</div>
                                <div className="mt-2 text-[13.5px] text-ink">{picked.assignment.registration_name}</div>
                                <Button onClick={() => unassign(picked.assignment)} className="mt-3">Unassign</Button>
                            </div>
                        )}

                        {picked && !picked.assignment && (
                            <SeatAssignPicker event={event} room={room} seatLabel={picked.label} onDone={reload} />
                        )}
                    </div>
                </div>
            )}

            <div className="mt-6">
                <NewRoomForm event={event} onChange={() => router.reload({ only: ['event'] })} />
            </div>

            <ConfirmModal
                open={!!deleting}
                onClose={() => setDeleting(null)}
                onConfirm={confirmDeleteRoom}
                title="Delete room?"
                description={deleting ? `"${deleting.name}" and its ${deleting.assignments.length} seat assignment(s) will be permanently removed.` : ''}
                confirmLabel="Delete room"
                danger
                processing={deleteSaving}
            />
        </div>
    );
}
