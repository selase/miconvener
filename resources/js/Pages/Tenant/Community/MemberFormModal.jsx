import { useState } from 'react';
import Modal from '@/Components/Console/Modal';
import Button from '@/Components/Console/Button';
import Input from '@/Components/Console/Input';
import Select from '@/Components/Console/Select';
import { useToast } from '@/Components/Console/Toast';
import { formatDuesMoney, sendJson, toMajorUnits, toMinorUnits } from '@/lib/dues';

export default function MemberFormModal({ member, membershipTypes, onClose, onSaved }) {
    const editing = member !== null;
    const showToast = useToast();
    const [form, setForm] = useState({
        name: member?.name ?? '',
        phone: member?.phone ?? '',
        email: member?.email ?? '',
        membership_type_id: member?.membership_type_id ?? membershipTypes[0]?.id ?? '',
        amount_override: toMajorUnits(member?.amount_override),
        joined_on: member?.joined_on ?? '',
    });
    const [errors, setErrors] = useState({});
    const [processing, setProcessing] = useState(false);

    const set = (field) => (event) => setForm({ ...form, [field]: event.target.value });

    // A class deactivated after the member joined is still theirs to keep.
    const options =
        member && !membershipTypes.some((c) => c.id === member.membership_type_id)
            ? [
                  { id: member.membership_type_id, name: `${member.class_name} (inactive)` },
                  ...membershipTypes,
              ]
            : membershipTypes;

    const submit = async (event) => {
        event.preventDefault();
        const override = toMinorUnits(form.amount_override);

        if (Number.isNaN(override)) {
            setErrors({
                amount_override: 'Enter an amount such as 50 or 49.99, or leave it blank.',
            });
            return;
        }

        const payload = {
            name: form.name,
            phone: form.phone || null,
            email: form.email || null,
            membership_type_id: form.membership_type_id,
            amount_override: override,
        };

        if (editing) {
            payload.joined_on = form.joined_on || null;
        } else if (form.joined_on) {
            payload.joined_on = form.joined_on;
        }

        setProcessing(true);
        const result = editing
            ? await sendJson(
                  'PATCH',
                  route('tenant.community.members.update', { member: member.id }),
                  payload
              )
            : await sendJson('POST', route('tenant.community.members.store'), payload);
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

    const chosen = options.find((c) => c.id === form.membership_type_id);

    return (
        <Modal open onClose={onClose} title={editing ? 'Edit member' : 'Add member'}>
            <form onSubmit={submit} className="space-y-4">
                <Input
                    label="Name"
                    value={form.name}
                    onChange={set('name')}
                    error={errors.name}
                    required
                />
                <div className="grid gap-4 sm:grid-cols-2">
                    <Input
                        label="Phone"
                        type="tel"
                        value={form.phone}
                        onChange={set('phone')}
                        error={errors.phone}
                    />
                    <Input
                        label="Email"
                        type="email"
                        value={form.email}
                        onChange={set('email')}
                        error={errors.email}
                    />
                </div>
                <p className="-mt-2 text-xs text-ink-secondary">
                    Add a phone number, an email, or both, so we can reach this member.
                </p>
                <Select
                    label="Membership type"
                    value={form.membership_type_id}
                    onChange={set('membership_type_id')}
                    error={errors.membership_type_id}
                >
                    {options.map((c) => (
                        <option key={c.id} value={c.id}>
                            {c.name}
                            {c.amount !== undefined
                                ? ` – ${formatDuesMoney(c.amount, c.currency)}`
                                : ''}
                        </option>
                    ))}
                </Select>
                <Input
                    label="Amount instead of the class amount (optional)"
                    inputMode="decimal"
                    value={form.amount_override}
                    onChange={set('amount_override')}
                    error={errors.amount_override}
                    hint={
                        chosen?.currency
                            ? `In ${chosen.currency}. Enter 0 to waive this member's dues.`
                            : "Enter 0 to waive this member's dues."
                    }
                />
                <Input
                    label="Joined on"
                    type="date"
                    value={form.joined_on}
                    onChange={set('joined_on')}
                    error={errors.joined_on}
                />
                <Button
                    type="submit"
                    variant="primary"
                    disabled={processing || options.length === 0}
                >
                    {editing ? 'Save changes' : 'Add member'}
                </Button>
            </form>
        </Modal>
    );
}
