import { useState } from 'react';
import { Head } from '@inertiajs/react';
import PollPanel from '@/Pages/Public/Events/AttendeePortal/panels/PollPanel';
import csrfFetch from '@/lib/csrfFetch';

export default function GuestPoll({ event, joined: initialJoined, joinUrl, pollUrl }) {
    const [joined, setJoined] = useState(initialJoined);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const join = async () => {
        setBusy(true);
        setError(null);
        try {
            const response = await csrfFetch(joinUrl, { method: 'POST', body: JSON.stringify({}) });
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || 'Unable to join. Please try again.');
            setJoined(true);
        } catch (problem) {
            setError(problem.message || 'Check your connection and try again.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <main className="min-h-screen bg-canvas px-4 py-8 text-ink sm:py-12">
            <Head title={`Live polls · ${event.name}`} />
            <div className="mx-auto max-w-xl space-y-6">
                <header className="space-y-2 border-b border-border pb-6">
                    <p className="text-xs font-semibold uppercase tracking-widest text-accent">MiConvener · Live participation</p>
                    <h1 className="break-words text-2xl font-semibold">{event.name}</h1>
                    <p className="text-sm text-ink-secondary">Answer the presenter’s questions without registering. Voting opens during the event, when a question is live.</p>
                </header>
                <p className="text-xs text-ink-secondary">One answer per question in this browser session. No name or email is required. Your answers are saved for the organiser; text answers may be shown to the room. This does not register or check you in.</p>
                {error && <p role="alert" className="text-sm text-red-600">{error}</p>}
                {joined ? <PollPanel pollUrl={pollUrl} /> : (
                    <button type="button" onClick={join} disabled={busy} className="w-full rounded-lg bg-accent px-5 py-3 font-medium text-accent-ink focus-visible:outline-2 focus-visible:outline-offset-2 disabled:opacity-50">
                        {busy ? 'Joining…' : 'Join live polls'}
                    </button>
                )}
                <footer className="border-t border-border pt-4 text-xs text-ink-secondary">Keep this page open to follow the next question. Internet access and cookies are required.</footer>
            </div>
        </main>
    );
}
