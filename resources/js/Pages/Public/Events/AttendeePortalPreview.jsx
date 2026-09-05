import { useState } from 'react';
import PublicLayout from '@/Layouts/PublicLayout';
import PreviewBadge from '@/Components/Console/PreviewBadge';
import Button from '@/Components/Console/Button';
import { Download, Plus, Check } from 'lucide-react';

const TABS = [
    ['ticket', 'My ticket'],
    ['agenda', 'My day'],
    ['downloads', 'Downloads'],
];

const AGENDA = [
    { time: '08:45', title: 'Opening plenary', room: 'Main Hall', added: true },
    { time: '11:15', title: 'Hands-on: FHIR resource modelling', room: 'Breakout Room 1', added: false },
    { time: '14:00', title: 'Clinic: writing your first integration', room: 'Breakout Room 1', added: true },
];

export default function AttendeePortalPreview({ event }) {
    const [tab, setTab] = useState('ticket');
    const [agenda, setAgenda] = useState(AGENDA);

    const toggle = (title) => setAgenda((a) => a.map((s) => (s.title === title ? { ...s, added: !s.added } : s)));

    return (
        <PublicLayout>
            <div className="mx-auto max-w-2xl px-6 py-8 sm:px-10">
                <div className="mb-5 flex items-center justify-between">
                    <div>
                        <div className="text-xs uppercase tracking-wide text-ink-secondary">{event.name}</div>
                        <h1 className="mt-1 text-xl font-medium text-ink">Attendee portal</h1>
                    </div>
                    <PreviewBadge />
                </div>

                <nav className="mb-6 flex gap-1 border-b border-border">
                    {TABS.map(([key, label]) => (
                        <button
                            key={key}
                            onClick={() => setTab(key)}
                            className={`-mb-px border-b px-3.5 py-2.5 text-[13px] ${tab === key ? 'border-accent text-accent' : 'border-transparent text-ink-secondary hover:text-ink'}`}
                        >
                            {label}
                        </button>
                    ))}
                </nav>

                {tab === 'ticket' && (
                    <div className="flex flex-col gap-6 sm:flex-row">
                        <div className="mx-auto h-40 w-40 shrink-0 border border-border bg-surface-sunken p-3 sm:mx-0">
                            <div className="grid h-full w-full grid-cols-6 grid-rows-6 gap-0.5">
                                {Array.from({ length: 36 }).map((_, i) => (
                                    <span key={i} className={(i * 7) % 3 === 0 || i % 5 === 0 ? 'bg-ink' : ''} />
                                ))}
                            </div>
                        </div>
                        <div className="min-w-0 flex-1">
                            <h2 className="text-lg font-medium text-ink">Jane Attendee</h2>
                            <p className="text-sm text-ink-secondary">Example Organization</p>
                            <dl className="mt-4 space-y-2 text-[13px]">
                                {[
                                    ['Entry code', 'EVT-XXXX-XXX'],
                                    ['Ticket', 'Standard'],
                                    ['Status', 'Confirmed'],
                                ].map(([k, v]) => (
                                    <div key={k} className="flex justify-between border-b border-border pb-2">
                                        <dt className="text-ink-secondary">{k}</dt>
                                        <dd className="font-mono text-ink">{v}</dd>
                                    </div>
                                ))}
                            </dl>
                            <div className="mt-4 flex gap-2">
                                <Button icon={Download} disabled>Add to wallet</Button>
                                <Button disabled>Transfer</Button>
                            </div>
                        </div>
                    </div>
                )}

                {tab === 'agenda' && (
                    <ul className="space-y-2">
                        {agenda.map((s) => (
                            <li key={s.title} className="flex items-center gap-4 border border-border px-4 py-3">
                                <span className="w-14 shrink-0 font-mono text-[12px] text-ink-secondary">{s.time}</span>
                                <div className="min-w-0 flex-1">
                                    <div className="text-[13.5px] text-ink">{s.title}</div>
                                    <div className="text-xs text-ink-secondary">{s.room}</div>
                                </div>
                                <button onClick={() => toggle(s.title)} className={`flex items-center gap-1 border px-2.5 py-1 text-xs ${s.added ? 'border-accent text-accent' : 'border-border text-ink-secondary'}`}>
                                    {s.added ? <Check className="h-3 w-3" strokeWidth={2} /> : <Plus className="h-3 w-3" strokeWidth={2} />}
                                    {s.added ? 'In my day' : 'Add'}
                                </button>
                            </li>
                        ))}
                    </ul>
                )}

                {tab === 'downloads' && (
                    <p className="text-sm text-ink-secondary">Materials released by the organizers will appear here once you've checked in.</p>
                )}
            </div>
        </PublicLayout>
    );
}
