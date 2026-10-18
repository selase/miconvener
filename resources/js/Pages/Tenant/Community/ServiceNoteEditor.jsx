import { useEffect, useState } from 'react';
import Modal from '@/Components/Console/Modal';
import Button from '@/Components/Console/Button';
import Input from '@/Components/Console/Input';
import Select from '@/Components/Console/Select';
import { communityRequest } from '@/lib/community';
import { csrfFetchFormData } from '@/lib/csrfFetch';
import LibraryItemFields from './LibraryItemFields';
import { serviceDate } from '@/Pages/Public/Community/ServicesPanel';

export default function ServiceNoteEditor({ initial = {}, groups, onClose, onSaved }) {
    const [eventId, setEventId] = useState(initial.event_id || '');
    const [sessionId, setSessionId] = useState(initial.session_id || '');
    const [search, setSearch] = useState('');
    const [query, setQuery] = useState('');
    const [options, setOptions] = useState(null);
    const [eventPage, setEventPage] = useState(1);
    const [occurrencePage, setOccurrencePage] = useState(1);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [file, setFile] = useState(null);
    const [form, setForm] = useState({
        title: '',
        description: '',
        plain_text: '',
        audience: 'all_members',
        group_ids: [],
        state: 'draft',
        edition: '',
        release_at: '',
    });
    useEffect(() => {
        let disposed = false;
        const params = new URLSearchParams({
            search: query,
            page: String(eventPage),
            occurrence_page: String(occurrencePage),
        });
        if (eventId) params.set('event', eventId);
        if (initial.session_id && eventId === initial.event_id)
            params.set('session', initial.session_id);
        communityRequest(`${route('tenant.community.service-notes.options')}?${params}`)
            .then((data) => {
                if (!disposed) setOptions(data);
            })
            .catch((failure) => {
                if (!disposed) setError(failure.message);
            });
        return () => {
            disposed = true;
        };
    }, [eventId, query, eventPage, occurrencePage, initial.session_id, initial.event_id]);
    const save = async (event) => {
        event.preventDefault();
        setBusy(true);
        setError('');
        const data = new FormData();
        ['title', 'description', 'plain_text', 'state', 'edition'].forEach((key) =>
            data.append(key, form[key])
        );
        data.append('release_at', form.release_at ? new Date(form.release_at).toISOString() : '');
        data.append('audience[kind]', form.audience);
        if (form.audience === 'groups')
            form.group_ids.forEach((id) => data.append('audience[group_ids][]', id));
        data.append('event_id', eventId);
        data.append('session_id', sessionId);
        if (file) data.append('file', file);
        try {
            const response = await csrfFetchFormData(
                route('tenant.community.service-notes.store'),
                data
            );
            const body = await response.json();
            if (!response.ok)
                throw new Error(
                    Object.values(body.errors || {}).flat()[0] ||
                        body.message ||
                        'The service notes could not be saved.'
                );
            onSaved();
        } catch (failure) {
            setError(failure.message);
        } finally {
            setBusy(false);
        }
    };
    const events = [...(options?.events?.data || [])];
    if (options?.selected_event && !events.some((event) => event.id === options.selected_event.id))
        events.unshift(options.selected_event);
    const occurrences = [...(options?.occurrences?.data || [])];
    if (
        options?.selected_occurrence &&
        !occurrences.some((service) => service.id === options.selected_occurrence.id)
    )
        occurrences.unshift(options.selected_occurrence);
    return (
        <Modal
            open
            title="Add service notes"
            onClose={() => {
                if (!busy) onClose();
            }}
        >
            <div className="mb-5 border-b border-border pb-5">
                <p className="text-sm text-ink-secondary">
                    Publish notes for one service. Existing programme notes are kept separate.
                </p>
                <div className="mt-3 flex gap-2">
                    <Input
                        label="Find a recurring series"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                    />
                    <Button
                        onClick={() => {
                            setQuery(search);
                            setEventPage(1);
                        }}
                    >
                        Search
                    </Button>
                </div>
            </div>
            <form className="space-y-4" onSubmit={save}>
                {error && (
                    <p role="alert" className="text-danger-fg">
                        {error}
                    </p>
                )}
                {!options && <p role="status">Loading services…</p>}
                <Select
                    label="Series"
                    required
                    value={eventId}
                    onChange={(event) => {
                        setEventId(event.target.value);
                        setSessionId('');
                        setOptions((old) =>
                            old ? { ...old, occurrences: null, selected_occurrence: null } : null
                        );
                        setOccurrencePage(1);
                    }}
                >
                    <option value="">Choose a recurring event</option>
                    {events.map((event) => (
                        <option key={event.id} value={event.id}>
                            {event.title}
                        </option>
                    ))}
                </Select>
                <div className="flex gap-3">
                    {options?.events?.prev_page_url && (
                        <button
                            type="button"
                            onClick={() => setEventPage((n) => n - 1)}
                            className="min-h-11 text-sm text-accent underline"
                        >
                            Previous series
                        </button>
                    )}
                    {options?.events?.next_page_url && (
                        <button
                            type="button"
                            onClick={() => setEventPage((n) => n + 1)}
                            className="min-h-11 text-sm text-accent underline"
                        >
                            More series
                        </button>
                    )}
                </div>
                {eventId && (
                    <>
                        <Select
                            label="Service occurrence"
                            required
                            value={sessionId}
                            onChange={(event) => setSessionId(event.target.value)}
                        >
                            <option value="">Choose the date and service</option>
                            {occurrences.map((service) => (
                                <option key={service.id} value={service.id}>
                                    {service.title} ·{' '}
                                    {serviceDate(service.starts_at, options.timezone)}
                                    {service.occurrence_status === 'cancelled'
                                        ? ' · Cancelled'
                                        : ''}
                                </option>
                            ))}
                        </Select>
                        <div className="flex gap-3">
                            {options?.occurrences?.prev_page_url && (
                                <button
                                    type="button"
                                    className="min-h-11 text-sm text-accent underline"
                                    onClick={() => setOccurrencePage((n) => n - 1)}
                                >
                                    Newer occurrences
                                </button>
                            )}
                            {options?.occurrences?.next_page_url && (
                                <button
                                    type="button"
                                    className="min-h-11 text-sm text-accent underline"
                                    onClick={() => setOccurrencePage((n) => n + 1)}
                                >
                                    Earlier occurrences
                                </button>
                            )}
                        </div>
                    </>
                )}
                <LibraryItemFields
                    form={form}
                    setForm={setForm}
                    setFile={setFile}
                    groups={groups}
                />
                <p className="text-xs text-ink-secondary">
                    Notes are readable only after publication and release, while the service is
                    available. Cancellation hides them. No event ticket or dues payment is required.
                </p>
                <Button type="submit" disabled={busy || !eventId || !sessionId}>
                    Save service notes
                </Button>
            </form>
        </Modal>
    );
}
