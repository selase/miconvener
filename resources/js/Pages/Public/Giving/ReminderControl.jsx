import { useEffect, useState } from 'react';
import VerifyPrompt from '@/Pages/Public/Events/AttendeePortal/VerifyPrompt';
import Input from '@/Components/Console/Input';
import Select from '@/Components/Console/Select';
import Button from '@/Components/Console/Button';
import { sendJson } from '@/lib/dues';

export default function GivingReminderControl({ slug }) {
    const [verified, setVerified] = useState(null);
    const [consent, setConsent] = useState(false);
    const [cadence, setCadence] = useState('monthly');
    const [anchor, setAnchor] = useState('1');
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState('');
    useEffect(() => {
        const clear = () => {
            setVerified(null);
            setConsent(false);
        };
        window.addEventListener('blur', clear);
        window.addEventListener('offline', clear);
        return () => {
            window.removeEventListener('blur', clear);
            window.removeEventListener('offline', clear);
        };
    }, []);
    const save = async (event) => {
        event.preventDefault();
        setBusy(true);
        try {
            const result = await sendJson(
                'POST',
                route('public.giving.reminders.subscribe', { slug }),
                { consent, cadence, anchor_day: cadence === 'monthly' ? Number(anchor) : null }
            );
            setMessage(
                result.body?.message ??
                    result.message ??
                    'We could not save your reminder. Please try again.'
            );
            if (result.status === 401) {
                setVerified(null);
                setConsent(false);
            }
            if (result.ok) setConsent(false);
        } finally {
            setBusy(false);
        }
    };
    return (
        <section className="mt-10 border-t border-border pt-6">
            <h2 className="text-xl font-semibold text-ink">An optional reminder to give</h2>
            <p className="mt-3 text-sm leading-relaxed text-ink-secondary">
                Choose a weekly or monthly email with a link to this fund. Nothing is charged
                automatically. You can stop at any time.
            </p>
            {!verified ? (
                <div className="mt-5">
                    <p className="mb-4 text-sm text-ink-secondary">
                        Verify an email address first. A receipt email entered above does not count
                        as verification.
                    </p>
                    <VerifyPrompt
                        allowPhone={false}
                        onVerified={setVerified}
                        sendUrl="/my/verify/send"
                        confirmUrl="/my/verify/confirm"
                    />
                </div>
            ) : (
                <form onSubmit={save} className="mt-5 space-y-4">
                    <p className="text-sm text-ink-secondary">Verified email · {verified}</p>
                    <Select
                        label="Remind me"
                        value={cadence}
                        onChange={(e) => setCadence(e.target.value)}
                    >
                        <option value="monthly">Monthly</option>
                        <option value="weekly">Weekly</option>
                    </Select>
                    {cadence === 'monthly' && (
                        <Input
                            label="Day of month"
                            type="number"
                            min="1"
                            max="31"
                            value={anchor}
                            onChange={(e) => setAnchor(e.target.value)}
                            hint="At 09:00 in the organisation’s timezone. Shorter months use their last day; weekly reminders use the organisation’s week start."
                            required
                        />
                    )}
                    <label className="flex gap-3 text-sm text-ink">
                        <input
                            type="checkbox"
                            checked={consent}
                            onChange={(e) => setConsent(e.target.checked)}
                        />
                        Email me optional giving reminders for this fund.
                    </label>
                    <Button type="submit" variant="primary" disabled={busy || !consent}>
                        {busy ? 'Saving…' : 'Save email reminders'}
                    </Button>
                </form>
            )}
            {message && (
                <p role="status" className="mt-4 text-sm text-ink">
                    {message}
                </p>
            )}
        </section>
    );
}
