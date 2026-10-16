import { useState } from 'react';
import Modal from '@/Components/Console/Modal';
import Button from '@/Components/Console/Button';
import Input from '@/Components/Console/Input';
import Select from '@/Components/Console/Select';
import { useToast } from '@/Components/Console/Toast';
import { formatMemberDues, sendJson, toMajorUnits, toMinorUnits } from '@/lib/dues';

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
    const [review, setReview] = useState(null);
    const [confirmedDistinct, setConfirmedDistinct] = useState(false);

    const set = (field) => (event) => {
        setForm({ ...form, [field]: event.target.value });
        setReview(null);
        setConfirmedDistinct(false);
    };

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
        const possible = await sendJson('POST', route('tenant.community.members.candidates'), {
            ...payload,
            ignore_id: member?.id ?? null,
        });
        if (!possible.ok) {
            setProcessing(false);
            setErrors(possible.errors);
            if (Object.keys(possible.errors).length === 0) showToast?.(possible.message);
            return;
        }
        if (possible.body.candidates.length > 0) {
            if (
                !possible.body.can_create_distinct ||
                !confirmedDistinct ||
                review?.revision !== possible.body.revision
            ) {
                setReview(possible.body);
                setConfirmedDistinct(false);
                setProcessing(false);
                return;
            }
            payload.confirm_distinct = true;
            payload.duplicate_revision = possible.body.revision;
        }
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
                {review?.candidates.length > 0 && (
                    <section
                        aria-label="Possible matching members"
                        className="space-y-3 rounded-md border border-warning-fg/30 bg-warning-bg p-4 text-sm text-ink"
                    >
                        <h3 className="font-semibold">Check possible matches</h3>
                        <p>
                            A shared phone does not identify a person. Review these records before
                            saving a distinct member.
                        </p>
                        <ul className="space-y-2">
                            {review.candidates.map((candidate) => (
                                <li key={candidate.id}>
                                    <strong>{candidate.name}</strong>
                                    <span className="block text-ink-secondary">
                                        {candidate.email ?? candidate.phone}
                                    </span>
                                </li>
                            ))}
                        </ul>
                        {review.can_create_distinct ? (
                            <label className="flex items-start gap-2">
                                <input
                                    type="checkbox"
                                    checked={confirmedDistinct}
                                    onChange={(event) => setConfirmedDistinct(event.target.checked)}
                                    className="mt-1"
                                />
                                I checked these records and confirm this is a distinct person.
                            </label>
                        ) : (
                            <p className="font-medium">
                                This email already belongs to another member. Review that membership
                                or correct the email.
                            </p>
                        )}
                    </section>
                )}
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
                                ? ` – ${formatMemberDues(c.amount, c.currency)}`
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
