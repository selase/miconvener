import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import Button from '@/Components/Console/Button';
import Input from '@/Components/Console/Input';
import Select from '@/Components/Console/Select';
import PageHeader from '@/Components/Console/PageHeader';
import { Table, Thead, Tr, Th, Td } from '@/Components/Console/Table';

const when = (start, end) => {
    const s = new Date(start);
    const e = new Date(end);

    return `${s.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' })}, ${s.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })} to ${e.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })}`;
};

function DeclineForm({ booking, onDone }) {
    const form = useForm({ reason: '' });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('tenant.venue.internal-bookings.decline', { booking: booking.id }), {
            preserveScroll: true,
            onSuccess: onDone,
        });
    };

    return (
        <form onSubmit={submit} className="mt-2 flex flex-wrap items-start gap-2">
            <div className="min-w-[14rem] flex-1">
                <Input
                    aria-label={`Reason for declining ${booking.for}`}
                    placeholder="Reason, which the staff member is emailed"
                    value={form.data.reason}
                    onChange={(e) => form.setData('reason', e.target.value)}
                    error={form.errors.reason}
                />
            </div>
            <Button type="submit" disabled={form.processing}>
                Decline
            </Button>
            <Button type="button" onClick={onDone}>
                Keep
            </Button>
        </form>
    );
}

function StaffList({ staff }) {
    const form = useForm({ name: '', email: '' });

    const add = (event) => {
        event.preventDefault();
        form.post(route('tenant.venue.internal-bookings.staff.store'), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    const remove = (person) => {
        if (
            !window.confirm(
                `Remove ${person.name} from staff? They keep any bookings already confirmed.`
            )
        ) {
            return;
        }
        router.delete(
            route('tenant.venue.internal-bookings.staff.destroy', { member: person.id }),
            {
                preserveScroll: true,
            }
        );
    };

    return (
        <div className="space-y-4">
            {staff.length === 0 ? (
                <p className="text-sm text-ink-secondary">
                    No staff yet. Add the people who may book your rooms.
                </p>
            ) : (
                <ul className="divide-y divide-border rounded-lg border border-border bg-surface">
                    {staff.map((person) => (
                        <li
                            key={person.id}
                            className="flex items-center justify-between gap-3 px-4 py-2.5 text-sm"
                        >
                            <span>
                                <span className="font-medium text-ink">{person.name}</span>
                                {person.email && (
                                    <span className="ml-2 text-ink-secondary">{person.email}</span>
                                )}
                            </span>
                            <Button onClick={() => remove(person)}>Remove</Button>
                        </li>
                    ))}
                </ul>
            )}
            <form onSubmit={add} className="flex flex-wrap items-start gap-2">
                <div className="min-w-[12rem] flex-1">
                    <Input
                        aria-label="Staff name"
                        placeholder="Name"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        error={form.errors.name}
                    />
                </div>
                <div className="min-w-[12rem] flex-1">
                    <Input
                        aria-label="Staff email"
                        type="email"
                        placeholder="Email"
                        value={form.data.email}
                        onChange={(e) => form.setData('email', e.target.value)}
                        error={form.errors.email}
                    />
                </div>
                <Button type="submit" disabled={form.processing}>
                    Add to staff
                </Button>
            </form>
        </div>
    );
}

function BookForStaff({ rooms, staff, limits }) {
    const [mode, setMode] = useState(staff.length > 0 ? 'existing' : 'new');
    const form = useForm({
        store_listing_id: rooms[0]?.id ?? '',
        member_id: staff[0]?.id ?? '',
        staff_name: '',
        staff_email: '',
        starts_at: '',
        ends_at: '',
        purpose: '',
        department: '',
        guest_count: '',
        confirm: false,
    });

    const submit = (event) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            member_id: mode === 'existing' ? data.member_id : null,
            staff_name: mode === 'new' ? data.staff_name : null,
            staff_email: mode === 'new' ? data.staff_email : null,
            guest_count: data.guest_count === '' ? null : data.guest_count,
            starts_at: data.starts_at ? new Date(data.starts_at).toISOString() : '',
            ends_at: data.ends_at ? new Date(data.ends_at).toISOString() : '',
        }));
        form.post(route('tenant.venue.internal-bookings.store'), {
            preserveScroll: true,
            onSuccess: () =>
                form.reset('purpose', 'department', 'guest_count', 'starts_at', 'ends_at'),
        });
    };

    if (rooms.length === 0) {
        return (
            <p className="text-sm text-ink-secondary">
                Add a space first, then staff can book it. Spaces are under Venue, Spaces.
            </p>
        );
    }

    return (
        <form onSubmit={submit} className="space-y-4">
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                <Select
                    label="Room"
                    value={form.data.store_listing_id}
                    onChange={(e) => form.setData('store_listing_id', e.target.value)}
                    error={form.errors.store_listing_id}
                >
                    {rooms.map((room) => (
                        <option key={room.id} value={room.id}>
                            {room.title}
                            {room.audience === 'staff' ? ' (staff only)' : ''}
                        </option>
                    ))}
                </Select>
                <div>
                    <span className="mb-1.5 block text-sm font-medium text-ink">Who is it for</span>
                    <div className="mb-2 inline-flex rounded-lg bg-surface-sunken p-1" role="group">
                        {[
                            ['existing', 'A staff member'],
                            ['new', 'Someone else'],
                        ].map(([value, label]) => (
                            <button
                                key={value}
                                type="button"
                                aria-pressed={mode === value}
                                onClick={() => setMode(value)}
                                className={`rounded-md px-3 py-1 text-[13px] font-medium ${mode === value ? 'bg-surface text-ink shadow-raised' : 'text-ink-secondary'}`}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                    {mode === 'existing' ? (
                        <Select
                            aria-label="Staff member"
                            value={form.data.member_id}
                            onChange={(e) => form.setData('member_id', e.target.value)}
                            error={form.errors.member_id}
                        >
                            {staff.length === 0 && <option value="">No staff yet</option>}
                            {staff.map((person) => (
                                <option key={person.id} value={person.id}>
                                    {person.name}
                                    {person.email ? ` (${person.email})` : ''}
                                </option>
                            ))}
                        </Select>
                    ) : (
                        <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <Input
                                aria-label="Name"
                                placeholder="Name"
                                value={form.data.staff_name}
                                onChange={(e) => form.setData('staff_name', e.target.value)}
                                error={form.errors.staff_name}
                            />
                            <Input
                                aria-label="Email"
                                type="email"
                                placeholder="Email"
                                value={form.data.staff_email}
                                onChange={(e) => form.setData('staff_email', e.target.value)}
                                error={form.errors.staff_email}
                            />
                        </div>
                    )}
                </div>
                <Input
                    label="Starts"
                    type="datetime-local"
                    value={form.data.starts_at}
                    onChange={(e) => form.setData('starts_at', e.target.value)}
                    error={form.errors.starts_at}
                />
                <Input
                    label="Ends"
                    type="datetime-local"
                    value={form.data.ends_at}
                    onChange={(e) => form.setData('ends_at', e.target.value)}
                    error={form.errors.ends_at}
                    hint={`Up to ${limits.max_hours} hours, ${limits.window_days} days ahead.`}
                />
                <Input
                    label="What it is for"
                    value={form.data.purpose}
                    onChange={(e) => form.setData('purpose', e.target.value)}
                    error={form.errors.purpose}
                />
                <Input
                    label="Department"
                    value={form.data.department}
                    onChange={(e) => form.setData('department', e.target.value)}
                    error={form.errors.department}
                />
                <Input
                    label="Expected people"
                    type="number"
                    min="1"
                    value={form.data.guest_count}
                    onChange={(e) => form.setData('guest_count', e.target.value)}
                    error={form.errors.guest_count}
                />
            </div>
            <label className="flex items-center gap-2 text-sm text-ink">
                <input
                    type="checkbox"
                    checked={form.data.confirm}
                    onChange={(e) => form.setData('confirm', e.target.checked)}
                />
                Confirm now (skip approval)
            </label>
            <Button type="submit" variant="primary" disabled={form.processing}>
                Book for staff
            </Button>
        </form>
    );
}

export default function InternalBookingsIndex({ pending, upcoming, rooms, staff, limits }) {
    const [declining, setDeclining] = useState(null);

    const act = (name, booking) =>
        router.post(
            route(`tenant.venue.internal-bookings.${name}`, { booking: booking.id }),
            {},
            { preserveScroll: true }
        );

    return (
        <ConsoleLayout>
            <div className="space-y-8">
                <PageHeader title="Staff bookings" />

                <section aria-labelledby="pending-heading" className="space-y-3">
                    <h2 id="pending-heading" className="text-sm font-semibold text-ink">
                        Waiting for a decision{pending.length > 0 ? ` (${pending.length})` : ''}
                    </h2>
                    {pending.length === 0 ? (
                        <p className="text-sm text-ink-secondary">No requests are waiting.</p>
                    ) : (
                        <Table>
                            <Thead>
                                <Tr>
                                    <Th>Staff member</Th>
                                    <Th>Room</Th>
                                    <Th>When</Th>
                                    <Th>For</Th>
                                    <Th>
                                        <span className="sr-only">Actions</span>
                                    </Th>
                                </Tr>
                            </Thead>
                            <tbody>
                                {pending.map((booking) => (
                                    <Tr key={booking.id}>
                                        <Td>
                                            <div className="font-medium text-ink">
                                                {booking.for}
                                            </div>
                                            <div className="text-xs text-ink-secondary">
                                                {booking.department}
                                            </div>
                                        </Td>
                                        <Td>{booking.room}</Td>
                                        <Td>{when(booking.starts_at, booking.ends_at)}</Td>
                                        <Td>{booking.purpose}</Td>
                                        <Td>
                                            {declining === booking.id ? (
                                                <DeclineForm
                                                    booking={booking}
                                                    onDone={() => setDeclining(null)}
                                                />
                                            ) : (
                                                <div className="flex gap-2">
                                                    <Button
                                                        variant="primary"
                                                        onClick={() => act('approve', booking)}
                                                    >
                                                        Approve
                                                    </Button>
                                                    <Button
                                                        onClick={() => setDeclining(booking.id)}
                                                    >
                                                        Decline
                                                    </Button>
                                                </div>
                                            )}
                                        </Td>
                                    </Tr>
                                ))}
                            </tbody>
                        </Table>
                    )}
                </section>

                <section aria-labelledby="upcoming-heading" className="space-y-3">
                    <h2 id="upcoming-heading" className="text-sm font-semibold text-ink">
                        Upcoming staff bookings
                    </h2>
                    {upcoming.length === 0 ? (
                        <p className="text-sm text-ink-secondary">Nothing is booked yet.</p>
                    ) : (
                        <Table>
                            <Thead>
                                <Tr>
                                    <Th>Staff member</Th>
                                    <Th>Room</Th>
                                    <Th>When</Th>
                                    <Th>For</Th>
                                    <Th>
                                        <span className="sr-only">Actions</span>
                                    </Th>
                                </Tr>
                            </Thead>
                            <tbody>
                                {upcoming.map((booking) => (
                                    <Tr key={booking.id}>
                                        <Td>
                                            <div className="font-medium text-ink">
                                                {booking.for}
                                            </div>
                                            <div className="text-xs text-ink-secondary">
                                                {booking.department}
                                            </div>
                                        </Td>
                                        <Td>{booking.room}</Td>
                                        <Td>{when(booking.starts_at, booking.ends_at)}</Td>
                                        <Td>{booking.purpose}</Td>
                                        <Td>
                                            <Button onClick={() => act('cancel', booking)}>
                                                Cancel
                                            </Button>
                                        </Td>
                                    </Tr>
                                ))}
                            </tbody>
                        </Table>
                    )}
                </section>

                <section aria-labelledby="staff-heading" className="space-y-3">
                    <h2 id="staff-heading" className="text-sm font-semibold text-ink">
                        Staff who can book your rooms
                    </h2>
                    <StaffList staff={staff} />
                </section>

                <section aria-labelledby="book-heading" className="space-y-3">
                    <h2 id="book-heading" className="text-sm font-semibold text-ink">
                        Book a room for a staff member
                    </h2>
                    <p className="text-sm text-ink-secondary">
                        For someone who asked by email or in person. Staff never pay for their
                        organisation&apos;s rooms.
                    </p>
                    <BookForStaff rooms={rooms} staff={staff} limits={limits} />
                </section>
            </div>
        </ConsoleLayout>
    );
}
