import { useState } from 'react';
import { router } from '@inertiajs/react';
import { ArrowLeft, Plus } from 'lucide-react';
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

const PERIODS = { monthly: 'Monthly', quarterly: 'Quarterly', yearly: 'Yearly' };

function ClassFormModal({ membershipType, onClose, onSaved }) {
    const editing = membershipType !== null;
    const locked = editing && membershipType.has_charges;
    const [form, setForm] = useState({
        name: membershipType?.name ?? '',
        amount: toMajorUnits(membershipType?.amount),
        currency: membershipType?.currency ?? 'GHS',
        period: membershipType?.period ?? 'monthly',
    });
    const [errors, setErrors] = useState({});
    const [processing, setProcessing] = useState(false);
    const showToast = useToast();

    const submit = async (event) => {
        event.preventDefault();
        const amount = toMinorUnits(form.amount);

        if (amount === null || Number.isNaN(amount) || amount < 100) {
            setErrors({ amount: 'Enter an amount of at least 1.00, such as 50 or 49.99.' });
            return;
        }

        setProcessing(true);
        const payload = { ...form, amount };
        const result = editing
            ? await sendJson(
                  'PATCH',
                  route('tenant.dues.classes.update', { class: membershipType.id }),
                  { ...payload, is_active: membershipType.is_active }
              )
            : await sendJson('POST', route('tenant.dues.classes.store'), payload);
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
        <Modal open onClose={onClose} title={editing ? 'Edit class' : 'Add class'}>
            <form onSubmit={submit} className="space-y-4">
                <Input
                    label="Name"
                    value={form.name}
                    onChange={(e) => setForm({ ...form, name: e.target.value })}
                    error={errors.name}
                    required
                />
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
                {locked && (
                    <p className="text-sm text-ink-secondary">
                        This class already has charges, so its currency and billing period are
                        fixed. Create a new class to bill differently.
                    </p>
                )}
                <Button type="submit" variant="primary" disabled={processing}>
                    {editing ? 'Save changes' : 'Add class'}
                </Button>
            </form>
        </Modal>
    );
}

export default function Classes({ classes }) {
    const showToast = useToast();
    const [modal, setModal] = useState(null);
    const [toggling, setToggling] = useState(null);
    const [busy, setBusy] = useState(false);

    const refresh = () => router.reload({ only: ['classes'], preserveScroll: true });

    const toggleActive = async (membershipType) => {
        setBusy(true);
        const result = await sendJson(
            'PATCH',
            route('tenant.dues.classes.update', { class: membershipType.id }),
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
                title="Dues classes"
                actions={
                    <>
                        <Button href={route('tenant.dues.members.index')} icon={ArrowLeft}>
                            Members
                        </Button>
                        <Button icon={Plus} onClick={() => setModal({ membershipType: null })}>
                            Add class
                        </Button>
                    </>
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
                        {classes.map((membershipType) => (
                            <Tr key={membershipType.id}>
                                <Td>
                                    <span className="font-medium text-ink">{membershipType.name}</span>
                                </Td>
                                <Td numeric>
                                    {formatDuesMoney(membershipType.amount, membershipType.currency)}
                                </Td>
                                <Td muted>{PERIODS[membershipType.period] ?? membershipType.period}</Td>
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
                        {classes.length === 0 && (
                            <tr>
                                <td colSpan={6}>
                                    <TableEmpty
                                        title="No classes yet"
                                        description="A class sets what members pay and how often. Add one before adding members."
                                    />
                                </td>
                            </tr>
                        )}
                    </tbody>
                </Table>
            </div>

            {modal && (
                <ClassFormModal
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
                title="Deactivate class"
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
