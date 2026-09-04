import { useState } from 'react';
import { Plus, Download, Copy, Wallet } from 'lucide-react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import PageHeader from '@/Components/Console/PageHeader';
import Button from '@/Components/Console/Button';
import IconButton from '@/Components/Console/IconButton';
import StatusPill from '@/Components/Console/StatusPill';
import StatusDot from '@/Components/Console/StatusDot';
import CurrencyChip from '@/Components/Console/CurrencyChip';
import SegmentedControl from '@/Components/Console/SegmentedControl';
import StatusBanner from '@/Components/Console/StatusBanner';
import DetailCard from '@/Components/Console/DetailCard';
import LabelValueRow from '@/Components/Console/LabelValueRow';
import CopyField from '@/Components/Console/CopyField';
import Drawer from '@/Components/Console/Drawer';
import Input from '@/Components/Console/Input';
import Select from '@/Components/Console/Select';
import Checkbox from '@/Components/Console/Checkbox';
import SearchInput from '@/Components/Console/SearchInput';
import Modal from '@/Components/Console/Modal';
import ConfirmModal from '@/Components/Console/ConfirmModal';
import { useToast } from '@/Components/Console/Toast';
import { Table, Thead, Th, Tr, Td, TableSkeleton, TableEmpty } from '@/Components/Console/Table';

function Section({ title, description, children }) {
    return (
        <section className="border-b border-border py-10 first:pt-0 last:border-0">
            <h2 className="text-lg font-bold text-ink">{title}</h2>
            {description && <p className="mt-1 max-w-2xl text-sm text-ink-secondary">{description}</p>}
            <div className="mt-5">{children}</div>
        </section>
    );
}

function Swatch({ name, className, hex }) {
    return (
        <div className="flex items-center gap-3">
            <div className={`h-10 w-10 shrink-0 rounded-md border border-border ${className}`} />
            <div>
                <div className="text-sm font-medium text-ink">{name}</div>
                <div className="num text-xs text-ink-secondary">{hex}</div>
            </div>
        </div>
    );
}

const COLORS = [
    { name: 'surface', className: 'bg-surface', hex: '#FFFFFF' },
    { name: 'surface-sunken', className: 'bg-surface-sunken', hex: '#F7F7F8' },
    { name: 'surface-hover', className: 'bg-surface-hover', hex: '#F2F2F4' },
    { name: 'ink', className: 'bg-ink', hex: '#111113' },
    { name: 'ink-secondary', className: 'bg-ink-secondary', hex: '#6B7280' },
    { name: 'ink-tertiary', className: 'bg-ink-tertiary', hex: '#9CA3AF' },
    { name: 'border', className: 'bg-border', hex: '#E8E8EC' },
    { name: 'border-strong', className: 'bg-border-strong', hex: '#D9D9DE' },
    { name: 'accent', className: 'bg-accent', hex: '#2563EB' },
    { name: 'accent-graph', className: 'bg-accent-graph', hex: '#4F46E5' },
    { name: 'inverse', className: 'bg-inverse', hex: '#0A0A0A' },
    { name: 'success', className: 'bg-success-bg', hex: '#E9F7F0 / #0F7A4D' },
    { name: 'warning', className: 'bg-warning-bg', hex: '#FDF5E3 / #9A6400' },
    { name: 'danger', className: 'bg-danger-bg', hex: '#FDF0EF / #C4281C' },
    { name: 'neutral', className: 'bg-neutral-bg', hex: '#F1F1F3 / #4B5563' },
];

const DEMO_ROWS = [
    { id: 1, name: 'Ada Lovelace', status: 'success', amount: '1,240.00' },
    { id: 2, name: 'Grace Hopper', status: 'pending', amount: '86.50' },
    { id: 3, name: 'Alan Turing', status: 'failed', amount: '412.00' },
];

export default function DesignSystemIndex() {
    const showToast = useToast();
    const [segment, setSegment] = useState('month');
    const [drawerOpen, setDrawerOpen] = useState(false);
    const [tableState, setTableState] = useState('data');
    const [modalOpen, setModalOpen] = useState(false);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [name, setName] = useState('');
    const [checked, setChecked] = useState(true);
    const [search, setSearch] = useState('');

    return (
        <ConsoleLayout>
            <PageHeader title="Design system" />

            <div className="px-8 py-6">
                <div className="rounded-lg border border-border-strong bg-surface-sunken p-4 text-sm text-ink-secondary">
                    Living reference for the primitives in <code className="num">resources/js/Components/Console</code>.{' '}
                    <code className="num">Tabs</code> is still inlined in <code className="num">PageHeader</code> rather than
                    standalone, and <code className="num">DateRangeControl</code>, <code className="num">FilterButton</code>,{' '}
                    <code className="num">PaymentCardArt</code> and <code className="num">FloatingPanel</code> haven&apos;t been
                    built — no page in the app has a real use for them yet.
                </div>

                <Section title="Color" description="Tokens defined in tailwind.config.js theme.extend.colors.">
                    <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
                        {COLORS.map((c) => (
                            <Swatch key={c.name} {...c} />
                        ))}
                    </div>
                </Section>

                <Section title="Typography">
                    <div className="space-y-3">
                        <div className="text-[30px] font-bold leading-9 tracking-tight text-ink">Page title — 30px bold</div>
                        <div className="text-lg font-bold text-ink">Section heading — 18px bold</div>
                        <div className="text-[15px] font-medium text-ink">Body / control text — 15px medium</div>
                        <div className="text-sm text-ink-secondary">Secondary text — 14px, ink-secondary</div>
                        <div className="text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                            Eyebrow / table header — 12px semibold uppercase
                        </div>
                        <div className="num text-2xl font-bold text-ink">$12,480.00 — tabular nums (.num)</div>
                        <div className="font-mono text-sm text-ink">sk_live_51H8x… — JetBrains Mono for IDs</div>
                    </div>
                </Section>

                <Section title="Button" description="Neutral outline only — never a solid accent fill.">
                    <div className="flex flex-wrap items-center gap-4">
                        <Button>Default</Button>
                        <Button icon={Plus}>With icon</Button>
                        <Button disabled>Disabled</Button>
                        <Button href="#" onClick={(e) => e.preventDefault()}>
                            As link (href)
                        </Button>
                        <Button href="#" disabled>
                            Disabled link
                        </Button>
                    </div>
                    <p className="mt-3 text-xs text-ink-tertiary">
                        Hover, focus and active states are native — try tabbing to a button or hovering it.
                    </p>
                </Section>

                <Section title="Input, Select & Checkbox" description="The shared field recipe used across every form in the console.">
                    <div className="grid max-w-md gap-5">
                        <Input label="Role name" value={name} onChange={(event) => setName(event.target.value)} placeholder="e.g. Event Organizer" />
                        <Input label="With error" value="" onChange={() => {}} error="This field is required." />
                        <Select label="Status" value="active" onChange={() => {}}>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </Select>
                        <Checkbox label="Use this key" checked={checked} onChange={(event) => setChecked(event.target.checked)} />
                    </div>
                </Section>

                <Section title="SearchInput">
                    <SearchInput placeholder="Search name, email, or reference" value={search} onChange={(event) => setSearch(event.target.value)} />
                </Section>

                <Section title="IconButton">
                    <div className="flex items-center gap-3">
                        <IconButton icon={Download} label="Download" />
                        <IconButton icon={Copy} label="Copy" />
                        <IconButton icon={Wallet} label="Wallet" disabled />
                    </div>
                </Section>

                <Section title="StatusPill & StatusDot" description="Table-cell status indicators.">
                    <div className="flex flex-wrap items-center gap-3">
                        <StatusPill status="success">Success</StatusPill>
                        <StatusPill status="pending">Pending</StatusPill>
                        <StatusPill status="failed">Failed</StatusPill>
                        <StatusPill status="neutral">Neutral</StatusPill>
                    </div>
                    <div className="mt-4 flex flex-wrap items-center gap-5">
                        {['success', 'pending', 'failed', 'neutral'].map((s) => (
                            <div key={s} className="flex items-center gap-2 text-sm text-ink">
                                <StatusDot status={s} /> {s}
                            </div>
                        ))}
                    </div>
                </Section>

                <Section title="CurrencyChip">
                    <div className="flex items-center gap-3">
                        <CurrencyChip>USD</CurrencyChip>
                        <CurrencyChip>GHS</CurrencyChip>
                        <CurrencyChip>EUR</CurrencyChip>
                    </div>
                </Section>

                <Section title="SegmentedControl">
                    <SegmentedControl
                        value={segment}
                        onChange={setSegment}
                        options={[
                            { value: 'month', label: 'Monthly' },
                            { value: 'year', label: 'Yearly' },
                        ]}
                    />
                </Section>

                <Section title="StatusBanner" description="Used at the top of drawer detail views.">
                    <div className="grid gap-3 sm:grid-cols-2">
                        <StatusBanner status="success" title="Payment succeeded" code="200" description="Settled to your default payout account." />
                        <StatusBanner status="pending" title="Payment pending" code="102" description="Awaiting confirmation from the provider." />
                        <StatusBanner status="failed" title="Payment failed" code="402" description="The card was declined by the issuer." />
                        <StatusBanner status="neutral" title="Payment voided" description="This transaction was cancelled before capture." />
                    </div>
                </Section>

                <Section title="DetailCard, LabelValueRow & CopyField" description="Composed inside a Drawer's detail sections.">
                    <div className="max-w-md">
                        <DetailCard title="Customer">
                            <LabelValueRow label="Name" value="Ada Lovelace" />
                            <LabelValueRow label="Amount" value="$1,240.00" numeric />
                            <CopyField label="Transaction ID" value="txn_51H8xJ2eZvKYlo2C" />
                        </DetailCard>
                    </div>
                </Section>

                <Section title="Table" description="Populated, loading (skeleton), and empty states.">
                    <SegmentedControl
                        value={tableState}
                        onChange={setTableState}
                        options={[
                            { value: 'data', label: 'Data' },
                            { value: 'loading', label: 'Loading' },
                            { value: 'empty', label: 'Empty' },
                        ]}
                    />
                    <div className="mt-4">
                        <Table>
                            <Thead>
                                <Th>Name</Th>
                                <Th>Status</Th>
                                <Th align="right">Amount</Th>
                            </Thead>
                            <tbody>
                                {tableState === 'data' &&
                                    DEMO_ROWS.map((row) => (
                                        <Tr key={row.id}>
                                            <Td>{row.name}</Td>
                                            <Td>
                                                <StatusPill status={row.status}>{row.status}</StatusPill>
                                            </Td>
                                            <Td align="right" numeric>
                                                ${row.amount}
                                            </Td>
                                        </Tr>
                                    ))}
                                {tableState === 'loading' && <TableSkeleton columns={3} rows={3} />}
                                {tableState === 'empty' && (
                                    <tr>
                                        <td colSpan={3}>
                                            <TableEmpty title="No records yet" description="They'll show up here once created." />
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </Table>
                    </div>
                </Section>

                <Section title="Drawer" description="Persistent 3-column layout at ≥1280px, overlay sheet below. Scoped here to a local flex row, same as a real page pairs a table with its drawer.">
                    <div className="flex rounded-lg border border-border">
                        <div className="min-w-0 flex-1 p-6">
                            <p className="text-sm text-ink-secondary">Content column — the drawer docks to its right at ≥1280px.</p>
                            <Button className="mt-4" onClick={() => setDrawerOpen(true)}>Open drawer</Button>
                        </div>

                        <Drawer open={drawerOpen} onClose={() => setDrawerOpen(false)}>
                            <h2 className="text-lg font-bold text-ink">Drawer example</h2>
                            <div className="mt-5">
                                <StatusBanner status="success" title="Payment succeeded" code="200" />
                            </div>
                            <div className="mt-5">
                                <DetailCard title="Details">
                                    <LabelValueRow label="Plan" value="Pro" />
                                    <LabelValueRow label="Amount" value="$19.00" numeric />
                                    <CopyField label="Reference" value="ref_9F82KcQ1" />
                                </DetailCard>
                            </div>
                        </Drawer>
                    </div>
                </Section>

                <Section title="Modal & ConfirmModal" description="Centered overlay for small self-contained forms and destructive confirmations.">
                    <div className="flex flex-wrap gap-3">
                        <Button onClick={() => setModalOpen(true)}>Open modal</Button>
                        <Button onClick={() => setConfirmOpen(true)}>Open confirm modal</Button>
                    </div>

                    <Modal open={modalOpen} onClose={() => setModalOpen(false)} title="New role" className="max-w-md">
                        <p className="text-sm text-ink-secondary">A small self-contained form, same pattern used by the Roles and Team modals.</p>
                        <div className="mt-4">
                            <Input label="Role name" value="" onChange={() => {}} />
                        </div>
                        <Button className="mt-6" onClick={() => setModalOpen(false)}>Create role</Button>
                    </Modal>

                    <ConfirmModal
                        open={confirmOpen}
                        onClose={() => setConfirmOpen(false)}
                        onConfirm={() => setConfirmOpen(false)}
                        title="Revoke API key"
                        description='Revoke "Production integration"? Any integration using it will stop working immediately.'
                        confirmLabel="Revoke"
                        danger
                    />
                </Section>

                <Section title="Toast">
                    <Button onClick={() => showToast?.('Saved successfully')}>Trigger toast</Button>
                </Section>
            </div>
        </ConsoleLayout>
    );
}
