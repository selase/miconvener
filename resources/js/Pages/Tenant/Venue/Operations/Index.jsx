import { Link, Head } from '@inertiajs/react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import PageHeader from '@/Components/Console/PageHeader';
import { Table, Thead, Tr, Th, Td, TableEmpty } from '@/Components/Console/Table';
import Button from '@/Components/Console/Button';

function Badge({ variant = 'neutral', children }) {
    const variants = {
        success:
            'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300 border-emerald-200 dark:border-emerald-800',
        danger: 'bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300 border-rose-200 dark:border-rose-800',
        warning:
            'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300 border-amber-200 dark:border-amber-800',
        primary:
            'bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300 border-blue-200 dark:border-blue-800',
        neutral:
            'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300 border-gray-200 dark:border-gray-700',
    };
    return (
        <span
            className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium border ${variants[variant] || variants.neutral}`}
        >
            {children}
        </span>
    );
}

function Card({ className = '', children, ...props }) {
    return (
        <div
            className={`rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-xs ${className}`}
            {...props}
        >
            {children}
        </div>
    );
}
import {
    CalendarDays,
    ClipboardCheck,
    MessageSquare,
    Users,
    CheckCircle2,
    Clock,
    AlertTriangle,
    ArrowRight,
    Building2,
} from 'lucide-react';

export default function OperationsIndex({ shop, stats, hostedEvents }) {
    const getStatusBadge = (status) => {
        switch (status) {
            case 'confirmed':
                return <Badge variant="success">Confirmed</Badge>;
            case 'completed':
                return <Badge variant="neutral">Completed</Badge>;
            case 'pending_payment':
                return <Badge variant="warning">Pending Payment</Badge>;
            default:
                return <Badge variant="neutral">{status}</Badge>;
        }
    };

    const getInspectionBadge = (status) => {
        switch (status) {
            case 'passed':
                return <Badge variant="success">Passed</Badge>;
            case 'flagged':
                return <Badge variant="danger">Flagged</Badge>;
            default:
                return <Badge variant="neutral">Pending</Badge>;
        }
    };

    return (
        <ConsoleLayout>
            <Head title={`Facility Operations — ${shop.name}`} />

            <PageHeader
                title="Facility Operations Hub"
                actions={
                    <div className="flex items-center gap-2">
                        <Link href={route('tenant.venue.calendar.index')}>
                            <Button variant="default">
                                <CalendarDays className="h-4 w-4 mr-1.5" />
                                Venue Calendar
                            </Button>
                        </Link>
                    </div>
                }
            />

            <div className="space-y-6 mt-6">
                {/* Metric KPI Cards */}
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                    <Card className="p-4 flex items-center gap-4">
                        <div className="h-12 w-12 rounded-xl bg-blue-50 dark:bg-blue-950/40 flex items-center justify-center text-blue-600 dark:text-blue-400">
                            <CalendarDays className="h-6 w-6" />
                        </div>
                        <div>
                            <div className="text-2xl font-bold text-gray-900 dark:text-white">
                                {stats.upcoming_events_count}
                            </div>
                            <div className="text-xs font-medium text-gray-500 dark:text-gray-400">
                                Upcoming Hosted Events
                            </div>
                        </div>
                    </Card>

                    <Card className="p-4 flex items-center gap-4">
                        <div className="h-12 w-12 rounded-xl bg-amber-50 dark:bg-amber-950/40 flex items-center justify-center text-amber-600 dark:text-amber-400">
                            <ClipboardCheck className="h-6 w-6" />
                        </div>
                        <div>
                            <div className="text-2xl font-bold text-gray-900 dark:text-white">
                                {stats.active_facility_tasks_count}
                            </div>
                            <div className="text-xs font-medium text-gray-500 dark:text-gray-400">
                                Active Facility Tasks
                            </div>
                        </div>
                    </Card>

                    <Card className="p-4 flex items-center gap-4">
                        <div className="h-12 w-12 rounded-xl bg-indigo-50 dark:bg-indigo-950/40 flex items-center justify-center text-indigo-600 dark:text-indigo-400">
                            <MessageSquare className="h-6 w-6" />
                        </div>
                        <div>
                            <div className="text-2xl font-bold text-gray-900 dark:text-white">
                                {stats.unread_messages_count}
                            </div>
                            <div className="text-xs font-medium text-gray-500 dark:text-gray-400">
                                Unread Coordinator Messages
                            </div>
                        </div>
                    </Card>

                    <Card className="p-4 flex items-center gap-4">
                        <div className="h-12 w-12 rounded-xl bg-emerald-50 dark:bg-emerald-950/40 flex items-center justify-center text-emerald-600 dark:text-emerald-400">
                            <Users className="h-6 w-6" />
                        </div>
                        <div>
                            <div className="text-2xl font-bold text-gray-900 dark:text-white">
                                {stats.today_occupancy_count}
                            </div>
                            <div className="text-xs font-medium text-gray-500 dark:text-gray-400">
                                Spaces Occupied Today
                            </div>
                        </div>
                    </Card>
                </div>

                {/* Hosted Events Table */}
                <Card>
                    <div className="p-4 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
                        <div>
                            <h2 className="text-base font-semibold text-gray-900 dark:text-white">
                                Hosted Client Events & Conferences
                            </h2>
                            <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                External organizer events booked at {shop.name}
                            </p>
                        </div>
                    </div>

                    <Table>
                        <Thead>
                            <Tr>
                                <Th>Event & Space</Th>
                                <Th>Planner / Organization</Th>
                                <Th>Date & Schedule</Th>
                                <Th>Setup Readiness</Th>
                                <Th>Inspections</Th>
                                <Th>Status</Th>
                                <Th align="right">Action</Th>
                            </Tr>
                        </Thead>
                        <tbody>
                            {hostedEvents.length === 0 ? (
                                <TableEmpty
                                    title="No upcoming hosted events"
                                    description="When clients book your venue spaces for conferences, their operational coordination files will appear here."
                                />
                            ) : (
                                hostedEvents.map((booking) => (
                                    <Tr key={booking.id}>
                                        <Td>
                                            <div className="font-medium text-gray-900 dark:text-white">
                                                {booking.title}
                                            </div>
                                            <div className="text-xs text-gray-500 flex items-center gap-1 mt-0.5">
                                                <Building2 className="h-3 w-3" />
                                                {booking.space_title} • {booking.guest_count} guests
                                                ({booking.layout_style})
                                            </div>
                                        </Td>
                                        <Td>
                                            <div className="font-medium text-gray-900 dark:text-white">
                                                {booking.planner.name}
                                            </div>
                                            <div className="text-xs text-gray-500">
                                                {booking.planner.company ||
                                                    booking.planner.tenant_name ||
                                                    booking.planner.email}
                                            </div>
                                        </Td>
                                        <Td>
                                            <div className="text-xs font-medium text-gray-900 dark:text-white">
                                                {new Date(booking.starts_at).toLocaleDateString(
                                                    undefined,
                                                    {
                                                        weekday: 'short',
                                                        month: 'short',
                                                        day: 'numeric',
                                                    }
                                                )}
                                            </div>
                                            <div className="text-xs text-gray-500 flex items-center gap-1 mt-0.5">
                                                <Clock className="h-3 w-3" />
                                                {new Date(booking.starts_at).toLocaleTimeString(
                                                    [],
                                                    { hour: '2-digit', minute: '2-digit' }
                                                )}{' '}
                                                -{' '}
                                                {new Date(booking.ends_at).toLocaleTimeString([], {
                                                    hour: '2-digit',
                                                    minute: '2-digit',
                                                })}
                                            </div>
                                        </Td>
                                        <Td>
                                            <div className="w-32">
                                                <div className="flex items-center justify-between text-xs mb-1">
                                                    <span className="font-medium text-gray-700 dark:text-gray-300">
                                                        {booking.tasks_completed} /{' '}
                                                        {booking.tasks_total}
                                                    </span>
                                                    <span className="text-gray-500">
                                                        {booking.readiness_percent}%
                                                    </span>
                                                </div>
                                                <div className="w-full bg-gray-100 dark:bg-gray-800 h-1.5 rounded-full overflow-hidden">
                                                    <div
                                                        className={`h-full rounded-full ${
                                                            booking.readiness_percent === 100
                                                                ? 'bg-emerald-500'
                                                                : 'bg-blue-600'
                                                        }`}
                                                        style={{
                                                            width: `${booking.readiness_percent}%`,
                                                        }}
                                                    />
                                                </div>
                                            </div>
                                        </Td>
                                        <Td>
                                            <div className="flex items-center gap-1.5 text-xs">
                                                <span className="text-gray-500">In:</span>
                                                {getInspectionBadge(booking.check_in_status)}
                                                <span className="text-gray-500 ml-1">Out:</span>
                                                {getInspectionBadge(booking.check_out_status)}
                                            </div>
                                        </Td>
                                        <Td>
                                            <div className="flex items-center gap-2">
                                                {getStatusBadge(booking.status)}
                                                {booking.unread_messages > 0 && (
                                                    <Badge variant="primary">
                                                        {booking.unread_messages} unread
                                                    </Badge>
                                                )}
                                            </div>
                                        </Td>
                                        <Td align="right">
                                            <Link
                                                href={route(
                                                    'tenant.venue.operations.show',
                                                    booking.id
                                                )}
                                            >
                                                <Button variant="default">
                                                    Open Workspace
                                                    <ArrowRight className="h-3.5 w-3.5 ml-1.5" />
                                                </Button>
                                            </Link>
                                        </Td>
                                    </Tr>
                                ))
                            )}
                        </tbody>
                    </Table>
                </Card>
            </div>
        </ConsoleLayout>
    );
}
