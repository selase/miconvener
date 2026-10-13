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

function ClassFormModal({ duesClass, onClose, onSaved }) {
    const editing = duesClass !== null;
    const [form, setForm] = useState({
        name: duesClass?.name ?? '',
        amount: toMajorUnits(duesClass?.amount),
        currency: duesClass?.currency ?? 'GHS',
        period: duesClass?.period ?? 'monthly',
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
                  route('tenant.dues.classes.update', { class: duesClass.id }),
                  { ...payload, is_active: duesClass.is_active }
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
                        required
                    />
                </div>
                <Select
                    label="Billed"
                    value={form.period}
                    onChange={(e) => setForm({ ...form, period: e.target.value })}
                    error={errors.period}
                >
                    {Object.entries(PERIODS).map(([value, label]) => (
                        <option key={value} value={value}>
                            {label}
                        </option>
                    ))}
                </Select>
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

    const toggleActive = async (duesClass) => {
        setBusy(true);
        const result = await sendJson(
            'PATCH',
            route('tenant.dues.classes.update', { class: duesClass.id }),
            {
                name: duesClass.name,
                amount: duesClass.amount,
                currency: duesClass.currency,
                period: duesClass.period,
                is_active: !duesClass.is_active,
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
                        <Button icon={Plus} onClick={() => setModal({ duesClass: null })}>
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
                        {classes.map((duesClass) => (
                            <Tr key={duesClass.id}>
                                <Td>
                                    <span className="font-medium text-ink">{duesClass.name}</span>
                                </Td>
                                <Td numeric>
                                    {formatDuesMoney(duesClass.amount, duesClass.currency)}
                                </Td>
                                <Td muted>{PERIODS[duesClass.period] ?? duesClass.period}</Td>
                                <Td muted numeric>
                                    {duesClass.member_count}
                                </Td>
                                <Td>
                                    <StatusPill
                                        status={duesClass.is_active ? 'success' : 'neutral'}
                                    >
                                        {duesClass.is_active ? 'Active' : 'Inactive'}
                                    </StatusPill>
                                </Td>
                                <Td>
                                    <div className="flex justify-end gap-2">
                                        <Button onClick={() => setModal({ duesClass })}>
                                            Edit
                                        </Button>
                                        <Button
                                            onClick={() =>
                                                duesClass.is_active
                                                    ? setToggling(duesClass)
                                                    : toggleActive(duesClass)
                                            }
                                            disabled={busy}
                                        >
                                            {duesClass.is_active ? 'Deactivate' : 'Activate'}
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
                    duesClass={modal.duesClass}
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
