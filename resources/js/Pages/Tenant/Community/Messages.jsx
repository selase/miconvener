import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Plus, Mail } from 'lucide-react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import PageHeader from '@/Components/Console/PageHeader';
import Button from '@/Components/Console/Button';
import MessageComposer from './MessageComposer';
import MessageDetail, { messageStatus, messageTime } from './MessageDetail';

export default function Messages({
    messages,
    capabilities,
    timezone,
    offsets,
    reconciliationUrl = null,
}) {
    const [composer, setComposer] = useState(null);
    const [selected, setSelected] = useState(null);
    const refresh = () => router.reload({ only: ['messages'] });
    return (
        <ConsoleLayout>
            <PageHeader
                title="Messages"
                actions={
                    capabilities.enabled !== false && <Button icon={Plus} variant="primary" onClick={() => setComposer({})}>
                        New announcement
                    </Button>
                }
            />
            <div className="space-y-6 px-4 py-6 sm:px-8">
                <p className="max-w-3xl text-sm text-ink-secondary">
                    {capabilities.enabled === false && <span>Sending is paused. You can review and withdraw existing in-app announcements. </span>}
                    Keep your community informed by email or SMS. Send to active members, choose a
                    status or reach a group. Announcements respect each address’s preferences;
                    personal dues notices also appear in the member’s private inbox when enabled.
                </p>
                <div className="flex flex-wrap gap-x-6 gap-y-2 border-y border-border py-3 text-xs text-ink-secondary">
                    <span>Organisation time: {timezone}</span>
                    <span>
                        Scheduling: {capabilities.schedule ? 'available' : 'Starter or above'}
                    </span>
                    <span>
                        Group announcements: {capabilities.groups ? 'available' : 'Growth or above'}
                    </span>
                </div>
                {!messages.data.length ? (
                    <div className="border border-dashed border-border p-8">
                        <Mail className="mb-4 size-7 text-ink-secondary" aria-hidden="true" />
                        <h2 className="text-lg font-semibold text-ink">
                            Your community’s news starts here
                        </h2>
                        <p className="mt-2 max-w-lg text-sm text-ink-secondary">
                            Create an announcement, review the audience and send when you are ready.
                            You can save a draft first.
                        </p>
                        <div className="mt-5">
                            {capabilities.enabled !== false && <Button onClick={() => setComposer({})}>Write an announcement</Button>}
                        </div>
                    </div>
                ) : (
                    <ul className="divide-y divide-border border-y border-border">
                        {messages.data.map((message) => (
                            <li key={message.id}>
                                <button
                                    className="flex w-full flex-wrap items-start justify-between gap-3 py-5 text-left hover:bg-surface-hover focus-visible:outline-accent"
                                    onClick={() => setSelected(message.id)}
                                >
                                    <span className="min-w-0 flex-1">
                                        <span className="block break-words font-semibold text-ink">
                                            {message.subject}
                                        </span>
                                        <span className="mt-1 block text-xs text-ink-secondary">
                                            {message.channels.join(' · ')} ·{' '}
                                            {messageTime(
                                                message.scheduled_at ?? message.created_at,
                                                timezone
                                            )}
                                        </span>
                                    </span>
                                    <span className="text-right">
                                        <span className="block text-sm font-medium text-ink">
                                            {messageStatus(message.status)}
                                        </span>
                                        <span className="mt-1 block text-xs text-ink-secondary">
                                            {message.accepted_count ?? 0} accepted ·{' '}
                                            {message.unknown_count ?? 0} uncertain
                                        </span>
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
                <div className="flex gap-3">
                    {messages.prev_page_url && (
                        <Button href={messages.prev_page_url}>Previous</Button>
                    )}
                    {messages.next_page_url && <Button href={messages.next_page_url}>Next</Button>}
                </div>
            </div>
            {composer && (
                <MessageComposer
                    draft={composer.id ? composer : null}
                    capabilities={capabilities}
                    timezone={timezone}
                    offsets={offsets}
                    onClose={() => setComposer(null)}
                    onDone={(message) => {
                        setComposer(null);
                        setSelected(message.id);
                        refresh();
                    }}
                />
            )}
            {selected && !composer && (
                <MessageDetail
                    id={selected}
                    reconciliationUrl={reconciliationUrl}
                    timezone={timezone}
                    onClose={() => setSelected(null)}
                    onChanged={refresh}
                    onEdit={(message) => {
                        setSelected(null);
                        setComposer(message);
                    }}
                />
            )}
        </ConsoleLayout>
    );
}
