import { useState } from 'react';
import { router } from '@inertiajs/react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import Button from '@/Components/Console/Button';
import PageHeader from '@/Components/Console/PageHeader';
import StatusPill from '@/Components/Console/StatusPill';
import { Table, Thead, Tr, Th, Td } from '@/Components/Console/Table';
import { FileText, Search, Eye, Inbox } from 'lucide-react';

const STATUS_MAP = {
    pending_quote: 'pending',
    quoted: 'neutral',
    accepted: 'success',
    rejected: 'failed',
    expired: 'neutral',
};

const STATUS_LABELS = {
    pending_quote: 'Quote needed',
    quoted: 'Proposal sent',
    accepted: 'Accepted',
    rejected: 'Declined',
    expired: 'Expired',
};

const formatCurrency = (pesewas) =>
    `GHS ${((pesewas || 0) / 100).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`;

const formatDate = (dateStr) =>
    dateStr
        ? new Date(dateStr).toLocaleDateString('en-US', {
              month: 'short',
              day: 'numeric',
              year: 'numeric',
          })
        : '';

export default function QuotesIndex({ shop, quotes, stats, filters }) {
    const [search, setSearch] = useState(filters.search || '');

    const reload = (params) =>
        router.get(route('tenant.venue.quotes.index'), params, { preserveState: true });

    const handleSearch = (e) => {
        e.preventDefault();
        reload({ search, status: filters.status || undefined });
    };

    return (
        <ConsoleLayout>
            <div className="space-y-6">
                <PageHeader title="Quotation Requests" />

                {!shop ? (
                    <div className="rounded-xl border border-dashed border-border p-12 text-center">
                        <FileText className="mx-auto h-12 w-12 text-ink-tertiary stroke-1" />
                        <h3 className="mt-3 text-sm font-semibold text-ink">
                            No marketplace shop yet
                        </h3>
                        <p className="mt-1 text-xs text-ink-secondary">
                            Set up your venue profile to receive quotation requests from planners.
                        </p>
                        <div className="mt-4">
                            <Button href={route('tenant.venue.profile')} variant="primary">
                                Set up profile
                            </Button>
                        </div>
                    </div>
                ) : (
                    <>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <div className="rounded-xl border border-border bg-surface p-4 space-y-1">
                                <div className="text-[11px] font-medium text-ink-secondary">
                                    Awaiting your quote
                                </div>
                                <div className="text-2xl font-bold text-amber-600 dark:text-amber-400">
                                    {stats.pending_quotes}
                                </div>
                            </div>
                            <div className="rounded-xl border border-border bg-surface p-4 space-y-1">
                                <div className="text-[11px] font-medium text-ink-secondary">
                                    Accepted
                                </div>
                                <div className="text-2xl font-bold text-emerald-600 dark:text-emerald-400">
                                    {stats.accepted_quotes}
                                </div>
                            </div>
                            <div className="rounded-xl border border-border bg-surface p-4 space-y-1">
                                <div className="text-[11px] font-medium text-ink-secondary">
                                    Value quoted
                                </div>
                                <div className="text-2xl font-bold text-accent">
                                    {formatCurrency(stats.total_quoted_pesewas)}
                                </div>
                            </div>
                        </div>

                        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                            <div className="flex flex-wrap gap-2 text-xs">
                                {[
                                    { key: '', label: 'All' },
                                    { key: 'pending_quote', label: 'Quote needed' },
                                    { key: 'quoted', label: 'Proposal sent' },
                                    { key: 'accepted', label: 'Accepted' },
                                    { key: 'rejected', label: 'Declined' },
                                ].map((tab) => (
                                    <button
                                        key={tab.key || 'all'}
                                        type="button"
                                        onClick={() =>
                                            reload({ search, status: tab.key || undefined })
                                        }
                                        className={`rounded-lg px-3 py-1.5 font-medium transition-colors ${
                                            (filters.status || '') === tab.key
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

                        {quotes.data.length > 0 ? (
                            <Table>
                                <Thead>
                                    <Th>Reference / Date</Th>
                                    <Th>Requested by</Th>
                                    <Th>Listing</Th>
                                    <Th>Event</Th>
                                    <Th>Total</Th>
                                    <Th>Status</Th>
                                    <Th align="right">Action</Th>
                                </Thead>
                                <tbody>
                                    {quotes.data.map((quote) => (
                                        <Tr key={quote.id}>
                                            <Td>
                                                <div className="font-mono text-xs font-bold text-ink">
                                                    {quote.quote_reference}
                                                </div>
                                                <div className="text-[11px] text-ink-tertiary">
                                                    {formatDate(quote.created_at)}
                                                </div>
                                            </Td>
                                            <Td>
                                                <div className="font-semibold text-ink">
                                                    {quote.planner_name}
                                                </div>
                                                <div className="text-[11px] text-ink-secondary">
                                                    {quote.planner_email}
                                                </div>
                                            </Td>
                                            <Td>
                                                <div className="font-medium text-ink">
                                                    {quote.listing?.title}
                                                </div>
                                            </Td>
                                            <Td>
                                                <div className="text-xs text-ink">
                                                    {quote.event_title}
                                                </div>
                                                <div className="text-[11px] text-ink-tertiary">
                                                    {formatDate(quote.event_date)}
                                                    {quote.guest_count
                                                        ? ` · ${quote.guest_count} guests`
                                                        : ''}
                                                </div>
                                            </Td>
                                            <Td>
                                                <div className="font-medium text-ink">
                                                    {quote.total_amount_pesewas > 0
                                                        ? formatCurrency(quote.total_amount_pesewas)
                                                        : '—'}
                                                </div>
                                            </Td>
                                            <Td>
                                                <StatusPill
                                                    status={STATUS_MAP[quote.status] || 'neutral'}
                                                >
                                                    {STATUS_LABELS[quote.status] || quote.status}
                                                </StatusPill>
                                            </Td>
                                            <Td align="right">
                                                <Button
                                                    href={route('tenant.venue.quotes.show', {
                                                        quote: quote.id,
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
                        ) : null}

                        {quotes.data.length > 0 &&
                            (quotes.prev_page_url || quotes.next_page_url) && (
                                <div className="flex items-center justify-between text-xs text-ink-secondary">
                                    <span>
                                        Page {quotes.current_page} of {quotes.last_page}
                                    </span>
                                    <div className="flex gap-2">
                                        {quotes.prev_page_url && (
                                            <Button href={quotes.prev_page_url} variant="default">
                                                Previous
                                            </Button>
                                        )}
                                        {quotes.next_page_url && (
                                            <Button href={quotes.next_page_url} variant="default">
                                                Next
                                            </Button>
                                        )}
                                    </div>
                                </div>
                            )}

                        {quotes.data.length === 0 && (
                            <div className="rounded-xl border border-dashed border-border p-12 text-center">
                                <Inbox className="mx-auto h-12 w-12 text-ink-tertiary stroke-1" />
                                <h3 className="mt-3 text-sm font-semibold text-ink">
                                    No quotation requests found
                                </h3>
                                <p className="mt-1 text-xs text-ink-secondary">
                                    When planners request an itemized quote for your equipment or
                                    services, it will arrive here.
                                </p>
                            </div>
                        )}
                    </>
                )}
            </div>
        </ConsoleLayout>
    );
}
