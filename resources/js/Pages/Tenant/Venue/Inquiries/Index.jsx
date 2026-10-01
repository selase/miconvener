import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import Button from '@/Components/Console/Button';
import PageHeader from '@/Components/Console/PageHeader';
import StatusPill from '@/Components/Console/StatusPill';
import { Table, Thead, Tr, Th, Td } from '@/Components/Console/Table';
import {
    Inbox,
    Calendar,
    Users,
    Clock,
    Search,
    Building2,
    Eye,
    ShieldCheck,
    CreditCard,
} from 'lucide-react';

const STATUS_MAP = {
    pending_quote: 'pending',
    pending_payment: 'pending',
    confirmed: 'success',
    completed: 'neutral',
    rejected: 'failed',
    cancelled: 'neutral',
};

export default function InquiriesIndex({ shop, inquiries, stats, filters }) {
    const [search, setSearch] = useState(filters.search || '');

    const formatCurrency = (pesewas) => {
        if (!pesewas && pesewas !== 0) return 'GHS 0.00';
        return `GHS ${(pesewas / 100).toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        })}`;
    };

    const formatDate = (dateStr) => {
        if (!dateStr) return '';
        const d = new Date(dateStr);
        return d.toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
        });
    };

    const handleSearch = (e) => {
        e.preventDefault();
        router.get(
            route('tenant.venue.inquiries.index'),
            { search, status: filters.status },
            { preserveState: true }
        );
    };

    const handleFilterStatus = (newStatus) => {
        router.get(
            route('tenant.venue.inquiries.index'),
            { search, status: newStatus },
            { preserveState: true }
        );
    };

    return (
        <ConsoleLayout>
            <div className="space-y-6">
                <PageHeader
                    title="Venue Inquiries & Bookings"
                    actions={
                        <div className="flex items-center gap-3">
                            <Button
                                href={route('tenant.venue.spaces.index')}
                                variant="default"
                                icon={Building2}
                            >
                                Venue Spaces
                            </Button>
                        </div>
                    }
                />

                {/* Pipeline Metrics */}
                <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div className="rounded-xl border border-border bg-surface p-4 space-y-1">
                        <div className="text-[11px] font-medium text-ink-secondary">
                            Total RFQs & Leads
                        </div>
                        <div className="text-2xl font-bold text-ink">{stats.total_leads}</div>
                    </div>
                    <div className="rounded-xl border border-border bg-surface p-4 space-y-1">
                        <div className="text-[11px] font-medium text-ink-secondary">
                            Pending Quotes
                        </div>
                        <div className="text-2xl font-bold text-amber-600 dark:text-amber-400">
                            {stats.pending_quotes}
                        </div>
                    </div>
                    <div className="rounded-xl border border-border bg-surface p-4 space-y-1">
                        <div className="text-[11px] font-medium text-ink-secondary">
                            Confirmed Bookings
                        </div>
                        <div className="text-2xl font-bold text-emerald-600 dark:text-emerald-400">
                            {stats.confirmed_bookings}
                        </div>
                    </div>
                    <div className="rounded-xl border border-border bg-surface p-4 space-y-1">
                        <div className="text-[11px] font-medium text-ink-secondary">
                            Gross Captured Revenue
                        </div>
                        <div className="text-2xl font-bold text-accent">
                            {formatCurrency(stats.total_revenue_pesewas)}
                        </div>
                    </div>
                </div>

                {/* Search & Status Filters */}
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex flex-wrap gap-2 text-xs">
                        {[
                            { key: 'all', label: 'All Inquiries' },
                            { key: 'pending_quote', label: 'Quotes Needed' },
                            { key: 'pending_payment', label: 'Pending Payment / On Hold' },
                            { key: 'confirmed', label: 'Confirmed' },
                            { key: 'rejected', label: 'Declined' },
                        ].map((tab) => (
                            <button
                                key={tab.key}
                                type="button"
                                onClick={() => handleFilterStatus(tab.key)}
                                className={`rounded-lg px-3 py-1.5 font-medium transition-colors ${
                                    (filters.status || 'all') === tab.key
                                        ? 'bg-accent text-white shadow-2xs font-semibold'
                                        : 'bg-surface border border-border text-ink-secondary hover:text-ink'
                                }`}
                            >
                                {tab.label}
                            </button>
                        ))}
                    </div>

                    <form onSubmit={handleSearch} className="flex gap-2">
                        <div className="relative">
                            <Search className="pointer-events-none absolute left-3 top-2.5 h-3.5 w-3.5 text-ink-tertiary" />
                            <input
                                type="text"
                                placeholder="Search by name, ref, email..."
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                className="w-56 rounded-lg border border-border bg-surface pl-9 pr-3 py-1.5 text-xs text-ink focus:border-accent focus:outline-none"
                            />
                        </div>
                        <Button type="submit" variant="default">
                            Search
                        </Button>
                    </form>
                </div>

                {/* Leads Table */}
                {inquiries.data.length > 0 ? (
                    <Table>
                        <Thead>
                            <Tr>
                                <Th>Reference / Date</Th>
                                <Th>Planner / Organization</Th>
                                <Th>Space Requested</Th>
                                <Th>Event Schedule</Th>
                                <Th>Layout & Guests</Th>
                                <Th>Estimated Amount</Th>
                                <Th>Status</Th>
                                <Th align="right">Action</Th>
                            </Tr>
                        </Thead>
                        <tbody>
                            {inquiries.data.map((lead) => (
                                <Tr key={lead.id}>
                                    <Td>
                                        <div className="font-mono text-xs font-bold text-ink">
                                            {lead.booking_reference}
                                        </div>
                                        <div className="text-[11px] text-ink-tertiary">
                                            {formatDate(lead.created_at)}
                                        </div>
                                    </Td>
                                    <Td>
                                        <div className="font-semibold text-ink flex items-center gap-1.5">
                                            <span>{lead.planner_name}</span>
                                            {lead.planner_tenant && (
                                                <span className="rounded bg-accent/10 px-1.5 py-0.5 text-[9px] font-bold text-accent">
                                                    Agency Partner
                                                </span>
                                            )}
                                        </div>
                                        <div className="text-[11px] text-ink-secondary">
                                            {lead.planner_email}
                                        </div>
                                        {lead.planner_company && (
                                            <div className="text-[10px] text-ink-tertiary">
                                                {lead.planner_company}
                                            </div>
                                        )}
                                    </Td>
                                    <Td>
                                        <div className="font-medium text-ink">
                                            {lead.listing?.title}
                                        </div>
                                        <div className="text-[11px] text-ink-tertiary capitalize">
                                            {lead.event_type}
                                        </div>
                                    </Td>
                                    <Td>
                                        <div className="text-xs text-ink">
                                            {formatDate(lead.starts_at)}
                                        </div>
                                        <div className="text-[11px] text-ink-tertiary">
                                            {lead.duration_units}{' '}
                                            {lead.time_slot_type === 'hourly' ? 'hrs' : 'days'}
                                        </div>
                                    </Td>
                                    <Td>
                                        <div className="text-xs font-medium text-ink">
                                            {lead.guest_count} guests
                                        </div>
                                        <div className="text-[11px] text-ink-tertiary capitalize">
                                            {lead.layout_style}
                                        </div>
                                    </Td>
                                    <Td>
                                        <div className="font-medium text-ink">
                                            {formatCurrency(lead.total_amount_pesewas)}
                                        </div>
                                        {lead.deposit_required_pesewas > 0 && (
                                            <div className="text-[10px] text-ink-tertiary">
                                                Deposit: {formatCurrency(lead.deposit_required_pesewas)}
                                            </div>
                                        )}
                                    </Td>
                                    <Td>
                                        <StatusPill
                                            status={STATUS_MAP[lead.status] || 'neutral'}
                                        >
                                            {lead.status.replace('_', ' ')}
                                        </StatusPill>
                                    </Td>
                                    <Td align="right">
                                        <Button
                                            href={route('tenant.venue.inquiries.show', {
                                                inquiry: lead.id,
                                            })}
                                            variant="default"
                                            icon={Eye}
                                        >
                                            Review
                                        </Button>
                                    </Td>
                                </Tr>
                            ))}
                        </tbody>
                    </Table>
                ) : (
                    <div className="rounded-xl border border-dashed border-border p-12 text-center">
                        <Inbox className="mx-auto h-12 w-12 text-ink-tertiary stroke-1" />
                        <h3 className="mt-3 text-sm font-semibold text-ink">
                            No Inquiries or Bookings Found
                        </h3>
                        <p className="mt-1 text-xs text-ink-secondary">
                            When event planners request quotes or book your spaces on the
                            marketplace, leads will arrive here.
                        </p>
                    </div>
                )}
            </div>
        </ConsoleLayout>
    );
}
