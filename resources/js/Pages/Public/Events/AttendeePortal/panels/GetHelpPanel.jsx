import { useState } from 'react';
import { Check } from 'lucide-react';
import csrfFetch from '@/lib/csrfFetch';

const HELP_TYPES = [
    ['refreshment', 'Water'],
    ['assistance', 'Help finding my seat'],
    ['technical', 'Technical'],
    ['accessibility', 'Step-free access'],
    ['medical', 'First aid'],
    ['other', 'Something else'],
];

export default function GetHelpPanel({ event, registration }) {
    const [type, setType] = useState('refreshment');
    const [location, setLocation] = useState(
        registration.seat_label ? `${registration.seat_label}, ${registration.room_name}` : ''
    );
    const [note, setNote] = useState('');
    const [saving, setSaving] = useState(false);
    const [sent, setSent] = useState(null);

    const submit = async (e) => {
        e.preventDefault();
        setSaving(true);
        const response = await csrfFetch(
            route('public.events.service-requests.store', {
                event: event.slug,
                registration: registration.id,
            }),
            {
                method: 'POST',
                body: JSON.stringify({ type, location: location || null, note: note || null }),
            }
        );
        const json = await response.json();
        setSaving(false);
        setSent(json);
    };

    if (sent) {
        return (
            <div className="border border-border p-5 text-left">
                <div className="flex items-center gap-2 text-ink">
                    <Check className="h-4 w-4 text-accent" strokeWidth={2} />
                    <b>Someone is coming</b>
                </div>
                <p className="mt-2 text-[13px] text-ink-secondary">
                    Raised at{' '}
                    {new Date(sent.created_at).toLocaleTimeString(undefined, {
                        hour: 'numeric',
                        minute: '2-digit',
                    })}
                    {location ? ` for ${location}` : ''}. Usually under five minutes.
                </p>
                <button
                    onClick={() => setSent(null)}
                    className="mt-3 text-xs text-ink-secondary underline hover:text-accent"
                >
                    Raise another request
                </button>
            </div>
        );
    }

    return (
        <form onSubmit={submit} className="space-y-4 text-left">
            <p className="text-sm text-ink-secondary">Goes straight to the floor team.</p>

            <div>
                <label className="mb-1.5 block text-sm font-medium text-ink">
                    What do you need
                </label>
                <div className="flex flex-wrap gap-2">
                    {HELP_TYPES.map(([value, label]) => (
                        <button
                            type="button"
                            key={value}
                            onClick={() => setType(value)}
                            className={`border px-3 py-1.5 text-xs font-medium ${type === value ? 'border-accent bg-accent-soft text-accent' : 'border-border text-ink-secondary'}`}
                        >
                            {label}
                        </button>
                    ))}
                </div>
            </div>

            <div>
                <label className="mb-1.5 block text-sm font-medium text-ink">Where you are</label>
                <input
                    value={location}
                    onChange={(e) => setLocation(e.target.value)}
                    placeholder="e.g. Grand Ballroom, near the entrance"
                    className="w-full border border-border bg-surface px-3 py-2 text-[13px] text-ink focus:border-accent focus:outline-none"
                />
            </div>

            <div>
                <label className="mb-1.5 block text-sm font-medium text-ink">Anything else</label>
                <textarea
                    value={note}
                    onChange={(e) => setNote(e.target.value)}
                    rows={2}
                    placeholder="Optional"
                    className="w-full border border-border bg-surface px-3 py-2 text-[13px] text-ink focus:border-accent focus:outline-none"
                />
            </div>

            <button
                type="submit"
                disabled={saving}
                className="inline-flex h-control items-center bg-accent px-4 text-sm text-accent-ink"
            >
                {saving ? 'Sending…' : 'Ask for help'}
            </button>
        </form>
    );
}
