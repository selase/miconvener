import { useState } from 'react';
import Modal from '@/Components/Console/Modal';
import Input from '@/Components/Console/Input';
import Button from '@/Components/Console/Button';
import csrfFetch from '@/lib/csrfFetch';

/**
 * Corrects a registration's name, email or phone. An attendee's history is
 * keyed to their email, so fixing a mistyped address is also how someone gets
 * back to it.
 */
export default function EditRegistrationModal({ event, registration, onClose, onSaved }) {
    const [form, setForm] = useState({
        full_name: registration.full_name ?? '',
        email: registration.email ?? '',
        phone: registration.phone ?? '',
    });
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);
    const [formError, setFormError] = useState(null);

    const set = (field) => (e) => setForm({ ...form, [field]: e.target.value });

    const submit = async (e) => {
        e.preventDefault();
        setSaving(true);
        setFormError(null);

        try {
            const response = await csrfFetch(
                route('tenant.events.registrations.update', {
                    event: event.id,
                    registration: registration.id,
                }),
                { method: 'PATCH', body: JSON.stringify({ ...form, phone: form.phone || null }) }
            );

            if (response.ok) {
                onSaved();
                return;
            }

            const json = await response.json().catch(() => ({}));
            setErrors(
                Object.fromEntries(
                    Object.entries(json.errors ?? {}).map(([field, messages]) => [field, messages[0]])
                )
            );
        } catch {
            // The request never reached the server -- offline, DNS failure, an
            // aborted connection. There is nothing field-specific to blame, so
            // this is a form-level error rather than one attached to an input.
            setFormError("Couldn't reach the server. Check your connection and try again.");
        } finally {
            setSaving(false);
        }
    };

    return (
        <Modal open onClose={onClose} title="Edit registration">
            <form onSubmit={submit} className="space-y-4">
                <Input
                    label="Name"
                    value={form.full_name}
                    onChange={set('full_name')}
                    error={errors.full_name}
                    required
                />
                <Input
                    label="Email"
                    type="email"
                    value={form.email}
                    onChange={set('email')}
                    error={errors.email}
                    hint="Their tickets, certificates and history follow this address."
                    required
                />
                <Input label="Phone" value={form.phone} onChange={set('phone')} error={errors.phone} />
                {formError && <p className="text-sm text-danger-fg">{formError}</p>}
                <div className="flex justify-end gap-2">
                    <Button type="button" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" variant="primary" disabled={saving}>
                        {saving ? 'Saving…' : 'Save'}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
