import { useEffect, useState } from 'react';
import { Send } from 'lucide-react';
import Select from '@/Components/Console/Select';
import Input from '@/Components/Console/Input';
import Button from '@/Components/Console/Button';
import StatusPill from '@/Components/Console/StatusPill';
import { Table, Thead, Th, Tr, Td, TableEmpty } from '@/Components/Console/Table';
import { useToast } from '@/Components/Console/Toast';
import csrfFetch from '@/lib/csrfFetch';

function formatDateTime(iso) {
    return new Date(iso).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
}

const STATUS_VARIANT = { scheduled: 'pending', sent: 'success', cancelled: 'failed' };

export default function BlastsPanel({ event }) {
    const [data, setData] = useState(null);
    const [form, setForm] = useState({ subject: '', body: '', audience: 'all', when: 'now', scheduled_at: '' });
    const [sending, setSending] = useState(false);
    const toast = useToast();

    const load = () => {
        csrfFetch(route('tenant.events.blasts.index', { event: event.id }))
            .then((r) => r.json())
            .then(setData);
    };

    useEffect(load, [event.id]);

    const send = async (e) => {
        e.preventDefault();
        setSending(true);

        const response = await csrfFetch(route('tenant.events.blasts.store', { event: event.id }), {
            method: 'POST',
            body: JSON.stringify({
                subject: form.subject,
                body: form.body,
                audience: form.audience,
                scheduled_at: form.when === 'later' && form.scheduled_at ? form.scheduled_at : null,
            }),
        });
        const json = await response.json();

        setSending(false);
        toast?.(json.message ?? 'Blast sent.');

        if (response.ok) {
            setForm({ subject: '', body: '', audience: 'all', when: 'now', scheduled_at: '' });
            load();
        }
    };

    const cancel = async (blast) => {
        const response = await csrfFetch(route('tenant.events.blasts.cancel', { event: event.id, blast: blast.id }), { method: 'PATCH' });
        const json = await response.json();
        toast?.(json.message);
        load();
    };

    if (!data) {
        return <p className="text-sm text-ink-secondary">Loading…</p>;
    }

    const generalOptions = data.audience_options.filter((o) => !o.key.includes(':'));
    const ticketOptions = data.audience_options.filter((o) => o.key.startsWith('ticket_type:'));
    const sessionOptions = data.audience_options.filter((o) => o.key.startsWith('session:'));

    return (
        <div className="max-w-3xl">
            <form onSubmit={send} className="mb-6 border border-border p-4">
                <b className="text-sm font-medium text-ink">Send a message</b>
                <div className="mt-3.5 grid grid-cols-2 gap-3.5">
                    <Select label="Who gets it" value={form.audience} onChange={(e) => setForm({ ...form, audience: e.target.value })}>
                        <optgroup label="General">
                            {generalOptions.map((o) => (
                                <option key={o.key} value={o.key}>{o.label}</option>
                            ))}
                        </optgroup>
                        {ticketOptions.length > 0 && (
                            <optgroup label="By ticket type">
                                {ticketOptions.map((o) => (
                                    <option key={o.key} value={o.key}>{o.label}</option>
                                ))}
                            </optgroup>
                        )}
                        {sessionOptions.length > 0 && (
                            <optgroup label="By session in their day">
                                {sessionOptions.map((o) => (
                                    <option key={o.key} value={o.key}>{o.label}</option>
                                ))}
                            </optgroup>
                        )}
                    </Select>
                    <Select label="When" value={form.when} onChange={(e) => setForm({ ...form, when: e.target.value })}>
                        <option value="now">Send now</option>
                        <option value="later">Schedule for later</option>
                    </Select>
                </div>

                {form.when === 'later' && (
                    <div className="mt-3.5">
                        <Input label="Send at" type="datetime-local" value={form.scheduled_at} onChange={(e) => setForm({ ...form, scheduled_at: e.target.value })} required />
                    </div>
                )}

                <div className="mt-3.5">
                    <label className="mb-1.5 block text-sm font-medium text-ink">Subject</label>
                    <input
                        type="text"
                        value={form.subject}
                        onChange={(e) => setForm({ ...form, subject: e.target.value })}
                        required
                        className="w-full border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none"
                    />
                </div>
                <div className="mt-3.5">
                    <label className="mb-1.5 block text-sm font-medium text-ink">Message</label>
                    <textarea
                        value={form.body}
                        onChange={(e) => setForm({ ...form, body: e.target.value })}
                        rows={4}
                        required
                        className="w-full border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none"
                    />
                </div>
                <Button type="submit" icon={Send} variant="primary" className="mt-3.5" disabled={sending}>
                    {sending ? 'Sending…' : form.when === 'later' ? 'Schedule' : 'Send now'}
                </Button>
            </form>

            <Table>
                <Thead>
                    <Th>Subject</Th>
                    <Th>Who</Th>
                    <Th>Opened</Th>
                    <Th>Status</Th>
                    <Th>When</Th>
                    <Th />
                </Thead>
                <tbody>
                    {data.blasts.length === 0 ? (
                        <tr>
                            <td colSpan={6}>
                                <TableEmpty title="No messages yet" description="Write one above once you're ready to reach your attendees." />
                            </td>
                        </tr>
                    ) : (
                        data.blasts.map((blast) => (
                            <Tr key={blast.id}>
                                <Td>{blast.subject}</Td>
                                <Td muted>{blast.audience_label ?? blast.audience}</Td>
                                <Td numeric>{blast.status === 'sent' ? `${blast.opened_count} of ${blast.recipients_count}` : '—'}</Td>
                                <Td><StatusPill status={STATUS_VARIANT[blast.status]}>{blast.status}</StatusPill></Td>
                                <Td muted>{formatDateTime(blast.sent_at ?? blast.scheduled_at ?? blast.created_at)}</Td>
                                <Td>
                                    {blast.status === 'scheduled' && blast.scheduled_at && (
                                        <Button onClick={() => cancel(blast)}>Cancel</Button>
                                    )}
                                </Td>
                            </Tr>
                        ))
                    )}
                </tbody>
            </Table>
        </div>
    );
}
