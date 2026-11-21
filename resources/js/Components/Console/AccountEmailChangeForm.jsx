import { useState } from 'react';
import axios from 'axios';
import Input from '@/Components/Console/Input';
import Button from '@/Components/Console/Button';

export default function AccountEmailChangeForm({ account }) {
    const [data, setData] = useState({ email: '', password: '', code: '' });
    const [errors, setErrors] = useState({});
    const [pending, setPending] = useState(false);
    const [message, setMessage] = useState('');
    const [failure, setFailure] = useState('');
    const update = (field) => (event) =>
        setData((current) => ({ ...current, [field]: event.target.value }));

    const submit = async (event) => {
        event.preventDefault();
        setPending(true);
        setErrors({});
        setMessage('');
        setFailure('');
        try {
            const response = await axios.post(
                route('account-identity.email-changes.store', {}, false),
                data,
                {
                    headers: { Accept: 'application/json' },
                }
            );
            setMessage(response.data.message);
        } catch (error) {
            if (error.response?.status === 422) {
                setErrors(
                    Object.fromEntries(
                        Object.entries(error.response.data.errors ?? {}).map(([key, values]) => [
                            key,
                            values[0],
                        ])
                    )
                );
            } else {
                setFailure(
                    error.response?.status === 429
                        ? 'Too many attempts. Please wait a minute before trying again.'
                        : 'Unable to send the verification link. Check your connection and try again.'
                );
            }
        } finally {
            setData((current) => ({ ...current, password: '', code: '' }));
            setPending(false);
        }
    };

    return (
        <section
            aria-labelledby="account-email-change-title"
            className="rounded-lg border border-border bg-surface p-6"
        >
            <h2 id="account-email-change-title" className="text-base font-semibold text-ink">
                Change your email
            </h2>
            <p className="mt-2 text-sm text-ink-secondary">
                Your current email is {account.email}. Verify the new address before it changes.
                Organisation access stays with your account. Receipts and attendee history keep
                their original email ownership.
            </p>
            {message && (
                <p role="status" className="mt-4 text-sm text-success-fg">
                    {message}
                </p>
            )}
            {failure && (
                <p role="alert" className="mt-4 text-sm text-danger-fg">
                    {failure}
                </p>
            )}
            <form onSubmit={submit} className="mt-5 space-y-4">
                <Input
                    label="New email address"
                    type="email"
                    autoComplete="email"
                    required
                    maxLength={255}
                    value={data.email}
                    onChange={update('email')}
                    error={errors.email}
                    disabled={pending}
                />
                <Input
                    label="Current password"
                    type="password"
                    autoComplete="current-password"
                    required
                    value={data.password}
                    onChange={update('password')}
                    error={errors.password}
                    disabled={pending}
                />
                {account.two_factor_enabled && (
                    <Input
                        label="Authenticator code"
                        inputMode="numeric"
                        pattern="[0-9]{6}"
                        maxLength={6}
                        autoComplete="one-time-code"
                        required
                        value={data.code}
                        onChange={update('code')}
                        error={errors.code}
                        disabled={pending}
                    />
                )}
                <p className="text-xs text-ink-secondary">
                    The verification link expires in one hour. You will confirm your password again
                    before saving.
                </p>
                <Button type="submit" variant="primary" disabled={pending}>
                    {pending ? 'Sending…' : 'Send verification link'}
                </Button>
            </form>
        </section>
    );
}
