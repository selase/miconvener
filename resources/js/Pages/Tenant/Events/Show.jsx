import { useState } from 'react';
import { router } from '@inertiajs/react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import PageHeader from '@/Components/Console/PageHeader';
import Button from '@/Components/Console/Button';
import StatusPill from '@/Components/Console/StatusPill';
import CopyField from '@/Components/Console/CopyField';
import SegmentedControl from '@/Components/Console/SegmentedControl';
import { Table, Thead, Th, Tr, Td, TableEmpty } from '@/Components/Console/Table';
import { Download, Check, X, Pencil } from 'lucide-react';
import csrfFetch from '@/lib/csrfFetch';
import EventFormModal from './EventFormModal';
import CheckInPanel from './CheckInPanel';
import TicketTypesPanel from './TicketTypesPanel';
import SpeakersPanel from './SpeakersPanel';
import SchedulePanel from './SchedulePanel';
import BlastsPanel from './BlastsPanel';
import EngagementPanel from './panels/EngagementPanel';
import BadgesPanel from './panels/BadgesPanel';
import VenuePanel from './panels/VenuePanel';
import RequestsPanel from './panels/RequestsPanel';
import ForumPanel from './panels/ForumPanel';
import MaterialsPanel from './panels/MaterialsPanel';
import ReportsPanel from './panels/ReportsPanel';
import SponsorsPanel from './panels/SponsorsPanel';
import FinancePanel from './panels/FinancePanel';
import RegistrationFormPanel from './panels/RegistrationFormPanel';
import PromoCodePanel from './panels/PromoCodePanel';
import SessionOccupancyPanel from './panels/SessionOccupancyPanel';
import AbstractsPanel from './panels/AbstractsPanel';

const TABS = [
    'Overview',
    'Tickets',
    'Promos',
    'Form',
    'Speakers',
    'Schedule',
    'Abstracts',
    'Guests',
    'Blasts',
    'Check-in',
    'Occupancy',
    'Venue',
    'Requests',
    'Materials',
    'Forum',
    'Live',
    'Badges',
    'Finance',
    'Sponsors',
    'Reports',
];

const STATUS_VARIANT = {
    pending_payment: 'pending',
    pending_approval: 'pending',
    waitlisted: 'pending',
    confirmed: 'success',
    checked_in: 'success',
    cancelled: 'failed',
    rejected: 'failed',
};

function formatMoney(amount, currency) {
    return amount > 0 ? `${currency} ${(amount / 100).toFixed(2)}` : 'Free';
}

// Unlike formatMoney, never says "Free" — a GHS 0.00 collected total means
// nothing has come in yet, which reads very differently from "this event has no charge."
function formatAmount(amount, currency) {
    return `${currency} ${(amount / 100).toFixed(2)}`;
}

function OverviewTab({ event, registrations, hasActiveGateway, settlementMode, publicUrl }) {
    const confirmed = registrations.filter(
        (r) => r.status === 'confirmed' || r.status === 'checked_in'
    );

    const [visibility, setVisibilityState] = useState(event.visibility ?? 'public');

    // Persisted immediately: a host toggling this is making a confidentiality
    // decision and should not have to find a save button to make it real. On
    // failure it snaps back, so the control never claims a state the server
    // does not hold.
    const setVisibility = async (next) => {
        const previous = visibility;
        setVisibilityState(next);
        const response = await csrfFetch(route('tenant.events.visibility', { event: event.id }), {
            method: 'PATCH',
            body: JSON.stringify({ visibility: next }),
        });
        if (!response.ok) setVisibilityState(previous);
    };
    const confirmedCount = confirmed.length;
    const revenue = confirmed.reduce((sum, r) => sum + r.amount, 0);
    const platformFees = confirmed.reduce((sum, r) => sum + (r.platform_fee_amount ?? 0), 0);

    return (
        <div className="space-y-6">
            {!event.is_free && !hasActiveGateway && (
                <div className="rounded-md bg-warning-bg px-4 py-3 text-sm text-warning-fg">
                    Connect a payment gateway under Settings → Payments before publishing this paid
                    event.
                </div>
            )}

            <div className="grid grid-cols-4 gap-4">
                <div className="rounded-lg border border-border p-4">
                    <div className="text-xs text-ink-secondary">Registrations</div>
                    <div className="mt-1 text-2xl font-semibold text-ink">{confirmedCount}</div>
                </div>
                <div className="rounded-lg border border-border p-4">
                    <div className="text-xs text-ink-secondary">Collected</div>
                    <div className="mt-1 text-2xl font-semibold text-ink">
                        {formatAmount(revenue, event.currency)}
                    </div>
                </div>
                <div className="rounded-lg border border-border p-4">
                    <div className="text-xs text-ink-secondary">
                        Platform fee ({event.effective_platform_fee_percentage}%)
                    </div>
                    <div className="mt-1 text-2xl font-semibold text-ink">
                        {formatAmount(platformFees, event.currency)}
                    </div>
                </div>
                <div className="rounded-lg border border-border p-4">
                    <div className="text-xs text-ink-secondary">Capacity</div>
                    <div className="mt-1 text-2xl font-semibold text-ink">
                        {event.capacity ?? 'Unlimited'}
                    </div>
                </div>
            </div>

            <p className="text-xs text-ink-secondary">
                {settlementMode === 'platform_default'
                    ? 'MiConvener collects payments for this event and pays out to your registered payout account. The platform fee above is deducted from what settles to you — see the Finance tab for the full breakdown.'
                    : 'Money settles 100% to your own connected Paystack account. The platform fee shown above is what MiConvener separately invoices — it isn’t deducted automatically.'}
            </p>

            <div>
                <h3 className="mb-2 text-sm font-semibold text-ink">Public event page</h3>
                <CopyField value={publicUrl} />
                <div className="mt-3 flex flex-wrap items-center gap-3">
                    <SegmentedControl
                        value={visibility}
                        onChange={setVisibility}
                        options={[
                            { value: 'public', label: 'Public' },
                            { value: 'private', label: 'Private' },
                        ]}
                    />
                    <p className="max-w-md text-xs text-ink-secondary">
                        {visibility === 'private'
                            ? 'Only the name, date and registration form are shown. Speakers, the agenda and sponsors appear once someone has a confirmed registration, and there is no public forum.'
                            : 'Anyone with the link sees the full page, including speakers, the agenda and sponsors.'}
                    </p>
                </div>
            </div>

            <div>
                <h3 className="mb-2 text-sm font-semibold text-ink">Previews</h3>
                <div className="flex gap-2">
                    <Button
                        href={route('public.events.preview.attendee', { event: event.slug })}
                        target="_blank"
                    >
                        Attendee portal
                    </Button>
                    <Button
                        href={route('public.events.preview.speaker', { event: event.slug })}
                        target="_blank"
                    >
                        Speaker portal
                    </Button>
                </div>
            </div>
        </div>
    );
}

function GuestsTab({ event, registrations }) {
    const reload = () => router.reload({ only: ['registrations'] });

    const act = async (registration, action, body) => {
        await csrfFetch(
            route(`tenant.events.registrations.${action}`, {
                event: event.id,
                registration: registration.id,
            }),
            {
                method: 'POST',
                body: body ? JSON.stringify(body) : undefined,
            }
        );
        reload();
    };

    const reject = (registration) => {
        const note = window.prompt('Reason (optional, shared with the registrant):') ?? '';
        act(registration, 'reject', { note: note || null });
    };

    return (
        <div>
            <div className="mb-4 flex justify-end">
                <Button
                    icon={Download}
                    href={route('tenant.events.guests.export', { event: event.id })}
                >
                    Export CSV
                </Button>
            </div>

            {registrations.length > 0 ? (
                <Table>
                    <Thead>
                        <Th>Name</Th>
                        <Th>Email</Th>
                        <Th>Status</Th>
                        <Th>Ticket type</Th>
                        <Th>Ticket code</Th>
                        <Th>Seat</Th>
                        <Th>Checked in</Th>
                        <Th />
                    </Thead>
                    <tbody>
                        {registrations.map((registration) => (
                            <Tr key={registration.id}>
                                <Td>{registration.full_name}</Td>
                                <Td muted>{registration.email}</Td>
                                <Td>
                                    <StatusPill status={STATUS_VARIANT[registration.status]}>
                                        {registration.status === 'waitlisted'
                                            ? `Waitlisted, #${registration.waitlist_position}`
                                            : registration.status.replace('_', ' ')}
                                    </StatusPill>
                                </Td>
                                <Td muted>{registration.ticket_type_name ?? '—'}</Td>
                                <Td muted>{registration.ticket_code ?? '—'}</Td>
                                <Td muted>
                                    {registration.seat_label
                                        ? `${registration.seat_label} · ${registration.room_name}`
                                        : '—'}
                                </Td>
                                <Td muted>
                                    {registration.checked_in_at
                                        ? new Date(registration.checked_in_at).toLocaleString()
                                        : '—'}
                                </Td>
                                <Td align="right">
                                    {registration.status === 'pending_approval' && (
                                        <div className="flex justify-end gap-1.5">
                                            <button
                                                onClick={() => act(registration, 'approve')}
                                                title="Approve"
                                                className="text-ink-secondary hover:text-success-fg"
                                            >
                                                <Check className="h-4 w-4" strokeWidth={1.75} />
                                            </button>
                                            <button
                                                onClick={() => reject(registration)}
                                                title="Reject"
                                                className="text-ink-secondary hover:text-danger-fg"
                                            >
                                                <X className="h-4 w-4" strokeWidth={1.75} />
                                            </button>
                                        </div>
                                    )}
                                    {(registration.status === 'confirmed' ||
                                        registration.status === 'checked_in' ||
                                        registration.status === 'waitlisted') && (
                                        <button
                                            onClick={() => act(registration, 'cancel')}
                                            className="text-xs text-ink-secondary hover:text-danger-fg"
                                        >
                                            Cancel
                                        </button>
                                    )}
                                </Td>
                            </Tr>
                        ))}
                    </tbody>
                </Table>
            ) : (
                <Table>
                    <Thead>
                        <Th>Name</Th>
                        <Th>Email</Th>
                        <Th>Status</Th>
                        <Th>Ticket type</Th>
                        <Th>Ticket code</Th>
                        <Th>Seat</Th>
                        <Th>Checked in</Th>
                        <Th />
                    </Thead>
                    <tbody>
                        <tr>
                            <td colSpan={8}>
                                <TableEmpty
                                    title="No guests yet"
                                    description="Share the event page to start collecting registrations."
                                />
                            </td>
                        </tr>
                    </tbody>
                </Table>
            )}
        </div>
    );
}

export default function Show({
    event,
    registrations,
    hasActiveGateway,
    settlementMode,
    publicUrl,
}) {
    const [tab, setTab] = useState('Overview');
    const [editing, setEditing] = useState(false);

    return (
        <ConsoleLayout>
            <PageHeader
                title={event.name}
                actions={
                    <Button icon={Pencil} onClick={() => setEditing(true)}>
                        Edit event
                    </Button>
                }
            />

            <div className="border-b border-border px-8">
                <nav className="flex gap-6 overflow-x-auto">
                    {TABS.map((label) => (
                        <button
                            key={label}
                            type="button"
                            onClick={() => setTab(label)}
                            className={`shrink-0 border-b-2 py-3 text-sm font-medium transition-colors ${
                                tab === label
                                    ? 'border-accent text-ink'
                                    : 'border-transparent text-ink-secondary hover:text-ink'
                            }`}
                        >
                            {label}
                        </button>
                    ))}
                </nav>
            </div>

            <div className="px-8 py-6">
                {tab === 'Overview' && (
                    <OverviewTab
                        event={event}
                        registrations={registrations}
                        hasActiveGateway={hasActiveGateway}
                        settlementMode={settlementMode}
                        publicUrl={publicUrl}
                    />
                )}
                {tab === 'Tickets' && (
                    <TicketTypesPanel
                        event={event}
                        ticketTypes={event.ticket_types}
                        onChange={() => router.reload({ only: ['event'] })}
                    />
                )}
                {tab === 'Promos' && (
                    <PromoCodePanel
                        event={event}
                        onChange={() => router.reload({ only: ['event'] })}
                    />
                )}
                {tab === 'Form' && (
                    <RegistrationFormPanel
                        event={event}
                        onChange={() => router.reload({ only: ['event'] })}
                    />
                )}
                {tab === 'Speakers' && (
                    <SpeakersPanel
                        event={event}
                        speakers={event.speakers}
                        onChange={() => router.reload({ only: ['event'] })}
                    />
                )}
                {tab === 'Schedule' && (
                    <SchedulePanel
                        event={event}
                        sessions={event.sessions}
                        speakers={event.speakers}
                        onChange={() => router.reload({ only: ['event'] })}
                    />
                )}
                {tab === 'Abstracts' && <AbstractsPanel event={event} />}
                {tab === 'Guests' && <GuestsTab event={event} registrations={registrations} />}
                {tab === 'Blasts' && <BlastsPanel event={event} />}
                {tab === 'Check-in' && (
                    <CheckInPanel
                        event={event}
                        sessions={event.sessions || []}
                        scanUrl={route('tenant.events.checkin.scan', { event: event.id })}
                        searchUrl={route('tenant.events.checkin.search', { event: event.id })}
                        checkInUrlFor={(registrationId) =>
                            route('tenant.events.checkin', {
                                event: event.id,
                                registration: registrationId,
                            })
                        }
                    />
                )}
                {tab === 'Occupancy' && (
                    <SessionOccupancyPanel
                        event={event}
                        onOpenScannerForSession={(session) => setTab('Check-in')}
                    />
                )}
                {tab === 'Venue' && <VenuePanel event={event} venueRooms={event.venue_rooms} />}
                {tab === 'Requests' && <RequestsPanel event={event} />}
                {tab === 'Materials' && (
                    <MaterialsPanel event={event} materials={event.materials} />
                )}
                {tab === 'Forum' && <ForumPanel event={event} />}
                {tab === 'Live' && <EngagementPanel event={event} />}
                {tab === 'Badges' && <BadgesPanel event={event} />}
                {tab === 'Finance' && <FinancePanel event={event} />}
                {tab === 'Sponsors' && <SponsorsPanel event={event} />}
                {tab === 'Reports' && <ReportsPanel event={event} />}
            </div>

            {editing && (
                <EventFormModal mode="edit" event={event} onClose={() => setEditing(false)} />
            )}
        </ConsoleLayout>
    );
}
