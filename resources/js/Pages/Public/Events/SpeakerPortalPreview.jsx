import { useState } from 'react';
import PublicLayout from '@/Layouts/PublicLayout';
import PreviewBadge from '@/Components/Console/PreviewBadge';
import Button from '@/Components/Console/Button';
import { FileText, Check } from 'lucide-react';

const SESSIONS = [
    { when: 'Tue, 08:45', title: 'Hands-on: FHIR resource modelling', room: 'Breakout Room 1', note: '60 signed up, full' },
    { when: 'Tue, 11:15', title: 'Clinic: writing your first integration', room: 'Breakout Room 1', note: '44 signed up' },
];

export default function SpeakerPortalPreview({ event }) {
    const [confirmed, setConfirmed] = useState(false);

    return (
        <PublicLayout>
            <div className="mx-auto max-w-2xl px-6 py-8 sm:px-10">
                <div className="mb-5 flex items-center justify-between">
                    <div>
                        <div className="text-xs uppercase tracking-wide text-ink-secondary">{event.name}</div>
                        <h1 className="mt-1 text-xl font-medium text-ink">Speaker portal</h1>
                    </div>
                    <PreviewBadge />
                </div>

                {!confirmed ? (
                    <div className="mb-6 flex items-center justify-between gap-4 border border-warning-fg/40 bg-warning-bg px-4 py-3.5">
                        <div>
                            <b className="text-[13.5px] text-ink">Are you still able to speak?</b>
                            <p className="mt-0.5 text-xs text-ink-secondary">We have you down for two sessions. Let us know so we can print the programme.</p>
                        </div>
                        <div className="flex shrink-0 gap-2">
                            <Button disabled>Can't make it</Button>
                            <Button variant="primary" onClick={() => setConfirmed(true)}>I'll be there</Button>
                        </div>
                    </div>
                ) : (
                    <div className="mb-6 flex items-center gap-2.5 border border-accent bg-accent-soft px-4 py-3.5">
                        <Check className="h-4 w-4 text-accent" strokeWidth={2} />
                        <div>
                            <b className="text-[13.5px] text-ink">Thank you, you're confirmed</b>
                            <p className="text-xs text-ink-secondary">You now appear on the public programme.</p>
                        </div>
                    </div>
                )}

                <div className="grid gap-5 sm:grid-cols-2">
                    <div className="border border-border p-4">
                        <b className="text-sm font-medium text-ink">Your sessions</b>
                        <ul className="mt-3 space-y-3">
                            {SESSIONS.map((s) => (
                                <li key={s.title} className="border-b border-border pb-3 last:border-0 last:pb-0">
                                    <span className="font-mono text-[11px] text-ink-secondary">{s.when}</span>
                                    <div className="mt-0.5 text-[13.5px] text-ink">{s.title}</div>
                                    <div className="text-xs text-ink-secondary">{s.room} — {s.note}</div>
                                </li>
                            ))}
                        </ul>
                    </div>

                    <div className="border border-border p-4">
                        <div className="flex items-center justify-between">
                            <b className="text-sm font-medium text-ink">Your slides</b>
                            <span className="text-xs text-ink-secondary">Due soon</span>
                        </div>
                        <div className="mt-3 flex items-center gap-2.5 text-[13px] text-ink-secondary">
                            <FileText className="h-4 w-4 shrink-0" strokeWidth={1.5} />
                            No file uploaded yet
                        </div>
                        <Button disabled className="mt-3">Upload slides</Button>
                    </div>
                </div>
            </div>
        </PublicLayout>
    );
}
