import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import PageHeader from '@/Components/Console/PageHeader';
import Button from '@/Components/Console/Button';
import Input from '@/Components/Console/Input';
import Select from '@/Components/Console/Select';
import Modal from '@/Components/Console/Modal';
import ConfirmModal from '@/Components/Console/ConfirmModal';
import StatusPill from '@/Components/Console/StatusPill';
import { useToast } from '@/Components/Console/Toast';
import { Table, Thead, Th, Tr, Td, TableEmpty } from '@/Components/Console/Table';
import { formatDuesMoney, sendJson, toMajorUnits, toMinorUnits } from '@/lib/dues';

const PERIODS = {
    daily: 'Daily',
    weekly: 'Weekly',
    monthly: 'Monthly',
    quarterly: 'Quarterly',
    yearly: 'Yearly',
};

function MembershipTypeFormModal({ membershipType, onClose, onSaved }) {
    const editing = membershipType !== null;
    const locked = editing && membershipType.has_charges;
    const [form, setForm] = useState({
        name: membershipType?.name ?? '',
        hasDues: membershipType ? membershipType.amount !== null : true,
        amount: toMajorUnits(membershipType?.amount),
        currency: membershipType?.currency ?? 'GHS',
        period: membershipType?.period ?? 'monthly',
    });
    const [errors, setErrors] = useState({});
    const [processing, setProcessing] = useState(false);
    const showToast = useToast();

    const submit = async (event) => {
        event.preventDefault();
        const amount = form.hasDues ? toMinorUnits(form.amount) : null;

        if (form.hasDues && (amount === null || Number.isNaN(amount) || amount < 100)) {
            setErrors({ amount: 'Enter an amount of at least 1.00, such as 50 or 49.99.' });
            return;
        }

        setProcessing(true);
        const payload = {
            name: form.name,
            currency: form.currency,
            amount,
            period: form.hasDues ? form.period : null,
        };
        const result = editing
            ? await sendJson(
                  'PATCH',
                  route('tenant.community.money.dues.update', { type: membershipType.id }),
                  { ...payload, is_active: membershipType.is_active }
              )
            : await sendJson('POST', route('tenant.community.money.dues.store'), payload);
        setProcessing(false);

        if (!result.ok) {
            setErrors(result.errors);
            if (Object.keys(result.errors).length === 0) {
                showToast?.(result.message);
            }
            return;
        }

        onSaved();
    };

    return (
        <Modal
            open
            onClose={onClose}
            title={editing ? 'Edit membership type' : 'Add membership type'}
        >
            <form onSubmit={submit} className="space-y-4">
                <Input
                    label="Name"
                    value={form.name}
                    onChange={(e) => setForm({ ...form, name: e.target.value })}
                    error={errors.name}
                    required
                />
                <Select
                    label="Dues"
                    value={form.hasDues ? 'yes' : 'no'}
                    onChange={(e) => setForm({ ...form, hasDues: e.target.value === 'yes' })}
                    disabled={locked}
                >
                    <option value="yes">Members of this type pay dues</option>
                    <option value="no">No dues (for example honorary members)</option>
                </Select>
                {form.hasDues && (
                    <div className="grid grid-cols-3 gap-3">
                        <div className="col-span-2">
                            <Input
                                label="Amount each period"
                                inputMode="decimal"
                                value={form.amount}
                                onChange={(e) => setForm({ ...form, amount: e.target.value })}
                                error={errors.amount}
                                hint="In whole currency units, for example 50.00."
                                required
                            />
                        </div>
                        <Input
                            label="Currency"
                            value={form.currency}
                            maxLength={3}
                            onChange={(e) => setForm({ ...form, currency: e.target.value })}
                            error={errors.currency}
                            disabled={locked}
                            required
                        />
                    </div>
                )}
                {form.hasDues && (
                    <Select
                        label="Billed"
                        value={form.period}
                        onChange={(e) => setForm({ ...form, period: e.target.value })}
                        error={errors.period}
                        disabled={locked}
                    >
                        {Object.entries(PERIODS).map(([value, label]) => (
                            <option key={value} value={value}>
                                {label}
                            </option>
                        ))}
                    </Select>
                )}
                {locked && (
                    <p className="text-sm text-ink-secondary">
                        This type already has charges, so its dues, currency and billing period are
                        fixed. Create a new type to bill differently.
                    </p>
                )}
                <Button type="submit" variant="primary" disabled={processing}>
                    {editing ? 'Save changes' : 'Add membership type'}
                </Button>
            </form>
        </Modal>
    );
}

export default function MoneyDues({ membershipTypes }) {
    const showToast = useToast();
    const [modal, setModal] = useState(null);
    const [toggling, setToggling] = useState(null);
    const [busy, setBusy] = useState(false);

    const refresh = () => router.reload({ only: ['membershipTypes'], preserveScroll: true });

    const toggleActive = async (membershipType) => {
        setBusy(true);
        const result = await sendJson(
            'PATCH',
            route('tenant.community.money.dues.update', { type: membershipType.id }),
            {
                name: membershipType.name,
                amount: membershipType.amount,
                currency: membershipType.currency,
                period: membershipType.period,
                is_active: !membershipType.is_active,
            }
        );
        setBusy(false);
        setToggling(null);

        if (!result.ok) {
            showToast?.(result.message);
            return;
        }

        refresh();
    };

    return (
        <ConsoleLayout>
            <PageHeader
                title="Money"
                tabs={[
                    {
                        key: 'dues',
                        label: 'Dues',
                        href: route('tenant.community.money.dues.index'),
                    },
                ]}
                activeTab="dues"
                actions={
                    <Button icon={Plus} onClick={() => setModal({ membershipType: null })}>
                        Add membership type
                    </Button>
                }
            />

            <div className="px-8 py-6">
                <Table>
                    <Thead>
                        <Th>Name</Th>
                        <Th>Amount</Th>
                        <Th>Billed</Th>
                        <Th>Members</Th>
                        <Th>Status</Th>
                        <Th>
                            <span className="sr-only">Actions</span>
                        </Th>
                    </Thead>
                    <tbody>
                        {membershipTypes.map((membershipType) => (
                            <Tr key={membershipType.id}>
                                <Td>
                                    <span className="font-medium text-ink">
                                        {membershipType.name}
                                    </span>
                                </Td>
                                <Td numeric>
                                    {membershipType.amount === null
                                        ? 'No dues'
                                        : formatDuesMoney(
                                              membershipType.amount,
                                              membershipType.currency
                                          )}
                                </Td>
                                <Td muted>
                                    {membershipType.period === null
                                        ? '—'
                                        : (PERIODS[membershipType.period] ?? membershipType.period)}
                                </Td>
                                <Td muted numeric>
                                    {membershipType.member_count}
                                </Td>
                                <Td>
                                    <StatusPill
                                        status={membershipType.is_active ? 'success' : 'neutral'}
                                    >
                                        {membershipType.is_active ? 'Active' : 'Inactive'}
                                    </StatusPill>
                                </Td>
                                <Td>
                                    <div className="flex justify-end gap-2">
                                        <Button onClick={() => setModal({ membershipType })}>
                                            Edit
                                        </Button>
                                        <Button
                                            onClick={() =>
                                                membershipType.is_active
                                                    ? setToggling(membershipType)
                                                    : toggleActive(membershipType)
                                            }
                                            disabled={busy}
                                        >
                                            {membershipType.is_active ? 'Deactivate' : 'Activate'}
                                        </Button>
                                    </div>
                                </Td>
                            </Tr>
                        ))}
                        {membershipTypes.length === 0 && (
                            <tr>
                                <td colSpan={6}>
                                    <TableEmpty
                                        title="No membership types yet"
                                        description="A membership type sets whether members pay dues, how much and how often. Add one before adding members."
                                    />
                                </td>
                            </tr>
                        )}
                    </tbody>
                </Table>
            </div>

            {modal && (
                <MembershipTypeFormModal
                    membershipType={modal.membershipType}
                    onClose={() => setModal(null)}
                    onSaved={() => {
                        setModal(null);
                        refresh();
                    }}
                />
            )}

            <ConfirmModal
                open={toggling !== null}
                onClose={() => setToggling(null)}
                onConfirm={() => toggleActive(toggling)}
                processing={busy}
                title="Deactivate membership type"
                description={
                    toggling &&
                    `${toggling.name} will no longer be offered when adding members. Existing members and their charges are kept.`
                }
                confirmLabel="Deactivate"
                danger
            />
        </ConsoleLayout>
    );
}
