import { useState } from 'react';
import Button from '@/Components/Console/Button';
import Select from '@/Components/Console/Select';
import { communityRequest } from '@/lib/community';

export default function JoinSettingsPanel({ settings, membershipTypes = [] }) {
    const [form, setForm] = useState(
        settings ?? {
            join_mode: 'approval_required',
            public_join_enabled: false,
            public_membership_type_id: null,
        }
    );
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState('');
    const [error, setError] = useState('');
    async function submit(event) {
        event.preventDefault();
        setBusy(true);
        setMessage('');
        setError('');
        try {
            await communityRequest(route('tenant.community.joining.settings'), 'PATCH', {
                ...form,
                public_join_enabled:
                    form.join_mode === 'invitation_only' ? false : form.public_join_enabled,
                public_membership_type_id: form.public_membership_type_id || null,
            });
            setMessage('Joining settings saved.');
        } catch (failure) {
            setError(failure.message);
        } finally {
            setBusy(false);
        }
    }
    return (
        <form onSubmit={submit} className="max-w-2xl space-y-5 px-4 py-6 sm:px-8">
            <div>
                <h2 className="text-lg font-semibold text-ink">Public joining</h2>
                <p className="mt-2 text-sm text-ink-secondary">
                    Choose how people request membership. Following remains a separate choice and
                    does not create a member.
                </p>
            </div>
            <Select
                label="How people join"
                value={form.join_mode}
                onChange={(event) => {
                    setForm({ ...form, join_mode: event.target.value });
                    setMessage('');
                }}
            >
                <option value="approval_required">Approval required · recommended</option>
                <option value="immediate">Immediate approval for new members</option>
                <option value="invitation_only">Invitation only · staff add members</option>
            </Select>
            <Select
                label="Public membership type"
                value={form.public_membership_type_id ?? ''}
                onChange={(event) => {
                    setForm({ ...form, public_membership_type_id: event.target.value });
                    setMessage('');
                }}
            >
                <option value="">No public membership type selected</option>
                {membershipTypes.map((type) => (
                    <option key={type.id} value={type.id}>
                        {type.name}
                        {type.amount != null
                            ? ` · ${type.currency} ${(type.amount / 100).toFixed(2)}`
                            : ' · No dues'}
                    </option>
                ))}
            </Select>
            <label className="flex items-start gap-2 text-sm text-ink">
                <input
                    className="mt-1"
                    type="checkbox"
                    disabled={!membershipTypes.length || form.join_mode === 'invitation_only'}
                    checked={form.public_join_enabled && form.join_mode !== 'invitation_only'}
                    onChange={(event) => {
                        setForm({ ...form, public_join_enabled: event.target.checked });
                        setMessage('');
                    }}
                />
                Open public membership requests
            </label>
            {!membershipTypes.length && (
                <p className="text-sm text-ink-secondary">
                    Create an active membership type before opening public joining.
                </p>
            )}
            <p className="text-xs text-ink-secondary">
                Pending requests count toward your member limit. Dues and member benefits begin only
                after approval. Contact collisions always need a separate review.
            </p>
            <Button
                type="submit"
                variant="primary"
                disabled={
                    busy ||
                    (form.public_join_enabled &&
                        form.join_mode !== 'invitation_only' &&
                        !form.public_membership_type_id)
                }
            >
                {busy ? 'Saving…' : 'Save joining settings'}
            </Button>
            {message && (
                <p role="status" className="text-sm text-success-fg">
                    {message}
                </p>
            )}
            {error && (
                <p role="alert" className="text-sm text-danger-fg">
                    {error}
                </p>
            )}
        </form>
    );
}
