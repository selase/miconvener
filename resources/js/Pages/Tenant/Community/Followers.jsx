import { useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import PageHeader from '@/Components/Console/PageHeader';
import Button from '@/Components/Console/Button';
import Input from '@/Components/Console/Input';
import Select from '@/Components/Console/Select';
import Modal from '@/Components/Console/Modal';
import { communityRequest } from '@/lib/community';

function AnnouncementComposer({ events, canSchedule, timezone, offsets, onClose, onDone }) {
    const [form, setForm] = useState({
        source_event_id: '',
        subject: '',
        body: '',
        channels: ['email'],
        local: '',
        offset: offsets[0] ?? '+00:00',
    });
    const [choices, setChoices] = useState(events);
    const [eventSearch, setEventSearch] = useState('');
    const [nextEvents, setNextEvents] = useState(null);
    const [preview, setPreview] = useState(null);
    const [authority, setAuthority] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [discard, setDiscard] = useState(false);
    const [key] = useState(() => crypto.randomUUID());
    const revision = useRef(0);
    function change(values) {
        revision.current += 1;
        setForm({ ...form, ...values });
        setPreview(null);
        setAuthority(false);
        setError('');
    }
    function close() {
        if (busy) return;
        if (form.subject || form.body || form.source_event_id) setDiscard(true);
        else onClose();
    }
    const payload = () => ({
        source_event_id: form.source_event_id,
        subject: form.subject,
        body: form.body,
        channels: form.channels,
        scheduled_at: form.local ? `${form.local}${form.offset}` : null,
        idempotency_key: key,
        confirm_authority: authority,
    });
    const valid = Boolean(
        form.source_event_id && form.subject.trim() && form.body.trim() && form.channels.length
    );
    async function review() {
        setBusy(true);
        setError('');
        const current = revision.current;
        try {
            const data = await communityRequest(
                route('tenant.following.announcements.preview'),
                'POST',
                payload()
            );
            if (current === revision.current) setPreview(data);
        } catch (failure) {
            setError(failure.message);
        } finally {
            setBusy(false);
        }
    }
    async function send(event) {
        event.preventDefault();
        if (!preview || !authority) return;
        setBusy(true);
        setError('');
        try {
            await communityRequest(
                route('tenant.following.announcements.store'),
                'POST',
                payload()
            );
            onDone();
        } catch (failure) {
            setError(failure.message);
            setPreview(null);
            setAuthority(false);
        } finally {
            setBusy(false);
        }
    }
    async function findEvents(url) {
        setBusy(true);
        setError('');
        try {
            const data = await communityRequest(
                url ??
                    `${route('tenant.following.announcements.events')}?search=${encodeURIComponent(eventSearch)}`
            );
            setChoices(url ? [...choices, ...data.events.data] : data.events.data);
            setNextEvents(data.events.next_page_url);
        } catch (failure) {
            setError(failure.message);
        } finally {
            setBusy(false);
        }
    }
    return (
        <Modal
            open
            onClose={close}
            title={discard ? 'Discard unsaved announcement?' : 'Announce a published event'}
            className="max-w-2xl"
        >
            {discard ? (
                <div className="space-y-4">
                    <p className="text-sm text-ink-secondary">Nothing has been sent.</p>
                    <div className="flex flex-wrap gap-3">
                        <Button type="button" onClick={() => setDiscard(false)}>
                            Continue editing
                        </Button>
                        <Button type="button" onClick={onClose}>
                            Discard announcement
                        </Button>
                    </div>
                </div>
            ) : (
                <form onSubmit={send} className="space-y-5">
                    <p className="text-sm text-ink-secondary">
                        Invite consented followers to a published upcoming event. Publishing an
                        event does not send an announcement automatically.
                    </p>
                    <fieldset disabled={busy} className="space-y-4">
                        <div className="flex flex-wrap items-end gap-2">
                            <Input
                                label="Find a published event"
                                value={eventSearch}
                                onChange={(event) => setEventSearch(event.target.value)}
                            />
                            <Button type="button" onClick={() => findEvents()}>
                                Find events
                            </Button>
                        </div>
                        <Select
                            label="Published event"
                            required
                            value={form.source_event_id}
                            onChange={(event) => change({ source_event_id: event.target.value })}
                        >
                            <option value="">Choose an upcoming published event</option>
                            {choices.map((event) => (
                                <option key={event.id} value={event.id}>
                                    {event.name}
                                    {event.visibility === 'private' ? ' · Private event' : ''}
                                </option>
                            ))}
                        </Select>
                        {nextEvents && (
                            <Button type="button" onClick={() => findEvents(nextEvents)}>
                                More events
                            </Button>
                        )}
                        <Input
                            label="Subject"
                            required
                            maxLength={255}
                            value={form.subject}
                            onChange={(event) => change({ subject: event.target.value })}
                        />
                        <label className="block text-sm font-medium text-ink">
                            Announcement
                            <textarea
                                required
                                rows={4}
                                maxLength={10000}
                                value={form.body}
                                onChange={(event) => change({ body: event.target.value })}
                                className="mt-2 block w-full rounded-md border border-border bg-surface px-3 py-2 text-sm text-ink"
                            />
                        </label>
                        <fieldset className="flex flex-wrap gap-4">
                            <legend className="mb-2 text-sm font-medium text-ink">Channels</legend>
                            {['email', 'sms'].map((channel) => (
                                <label
                                    key={channel}
                                    className="flex items-center gap-2 text-sm text-ink"
                                >
                                    <input
                                        type="checkbox"
                                        checked={form.channels.includes(channel)}
                                        onChange={(event) =>
                                            change({
                                                channels: event.target.checked
                                                    ? [...form.channels, channel]
                                                    : form.channels.filter(
                                                          (value) => value !== channel
                                                      ),
                                            })
                                        }
                                    />
                                    {channel === 'email' ? 'Email' : 'SMS'}
                                </label>
                            ))}
                        </fieldset>
                        {canSchedule ? (
                            <div className="grid gap-3 sm:grid-cols-2">
                                <Input
                                    label={`Schedule in ${timezone} (optional)`}
                                    type="datetime-local"
                                    value={form.local}
                                    onChange={(event) => change({ local: event.target.value })}
                                />
                                <Select
                                    label="UTC offset for scheduled date"
                                    value={form.offset}
                                    onChange={(event) => change({ offset: event.target.value })}
                                >
                                    {offsets.map((offset) => (
                                        <option key={offset} value={offset}>
                                            {offset}
                                        </option>
                                    ))}
                                </Select>
                            </div>
                        ) : (
                            <p className="text-xs text-ink-secondary">
                                Send now is available on Free. Scheduling requires Starter or above.
                            </p>
                        )}
                    </fieldset>
                    <Button type="button" disabled={busy || !valid} onClick={review}>
                        Review audience and cost
                    </Button>
                    {preview && (
                        <section
                            aria-label="Announcement review"
                            className="space-y-3 rounded-lg border border-border bg-surface-hover p-4 text-sm text-ink"
                        >
                            <p className="font-semibold">{preview.event.name}</p>
                            {preview.event.visibility === 'private' && (
                                <p className="text-xs text-warning-fg">
                                    This is a private event. The announcement goes to your consented
                                    followers; the event’s existing registration and access rules
                                    still apply.
                                </p>
                            )}
                            <a
                                href={preview.event.url}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="break-all text-accent underline"
                            >
                                {preview.event.url}
                            </a>
                            <p className="whitespace-pre-wrap break-words">{preview.body}</p>
                            <div className="flex flex-wrap gap-4">
                                <span>Email: {preview.counts.email ?? 0}</span>
                                <span>SMS: {preview.counts.sms ?? 0}</span>
                            </div>
                            <p className="text-xs text-ink-secondary">
                                Email credits remaining:{' '}
                                {preview.email_credits_remaining ?? 'Unlimited'} · SMS credits
                                remaining: {preview.sms_credits_remaining ?? 'Unlimited'} · Monthly
                                SMS allowance remaining:{' '}
                                {preview.sms_monthly_remaining ?? 'Unlimited'}
                            </p>
                            <p className="text-xs text-ink-secondary">
                                Only currently consented addresses are eligible. Unfollows and
                                access changes are checked again before delivery. New follows after
                                sending starts are for your next announcement.
                            </p>
                            <label className="flex items-start gap-2">
                                <input
                                    type="checkbox"
                                    className="mt-1"
                                    checked={authority}
                                    onChange={(event) => setAuthority(event.target.checked)}
                                />
                                I am authorised to contact these consented followers.
                            </label>
                        </section>
                    )}
                    {error && (
                        <p role="alert" className="text-sm text-danger-fg">
                            {error}
                        </p>
                    )}
                    <div className="flex flex-wrap gap-3">
                        <Button
                            type="submit"
                            variant="primary"
                            disabled={busy || !preview || !authority}
                        >
                            {busy
                                ? 'Working…'
                                : form.local
                                  ? 'Schedule announcement'
                                  : 'Send announcement'}
                        </Button>
                        <Button type="button" disabled={busy} onClick={close}>
                            Cancel
                        </Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}

export default function Followers({
    followers,
    messages,
    events = [],
    canManage = false,
    canSend = false,
    communityEnabled = false,
    canSchedule = false,
    timezone = 'Africa/Accra',
    offsets = ['+00:00'],
    activeTab = 'followers',
}) {
    const [compose, setCompose] = useState(false);
    const [selected, setSelected] = useState(null);
    const [cancel, setCancel] = useState(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const tab = followers === null ? 'announcements' : activeTab;
    async function detail(message) {
        setBusy(true);
        setError('');
        try {
            setSelected(
                await communityRequest(
                    route('tenant.following.announcements.show', { message: message.id })
                )
            );
        } catch (failure) {
            setError(failure.message);
        } finally {
            setBusy(false);
        }
    }
    async function cancelMessage() {
        setBusy(true);
        setError('');
        try {
            await communityRequest(
                route('tenant.following.announcements.cancel', { message: cancel.id }),
                'POST'
            );
            setCancel(null);
            setSelected(null);
            router.reload();
        } catch (failure) {
            setError(failure.message);
        } finally {
            setBusy(false);
        }
    }
    return (
        <ConsoleLayout>
            <PageHeader
                title="Followers"
                activeTab={tab}
                tabs={[
                    ...(canManage
                        ? [
                              {
                                  key: 'followers',
                                  label: 'Followers',
                                  href: route('tenant.community.followers.index'),
                              },
                          ]
                        : []),
                    ...(canSend
                        ? [
                              {
                                  key: 'announcements',
                                  label: 'Event announcements',
                                  href: route('tenant.following.announcements.index'),
                              },
                          ]
                        : []),
                ]}
                actions={
                    <>
                        {communityEnabled && canManage && (
                            <Button href={route('tenant.community.people.index')}>People</Button>
                        )}
                        {canSend && (
                            <Button
                                type="button"
                                variant="primary"
                                onClick={() => setCompose(true)}
                            >
                                Announce event
                            </Button>
                        )}
                    </>
                }
            />
            <main className="space-y-6 px-4 py-6 sm:px-8">
                <p className="max-w-3xl text-sm text-ink-secondary">
                    Followers choose an address for announcements. Following works independently of
                    membership and does not create a member or imply consent at another address.
                </p>
                {error && (
                    <p role="alert" className="text-sm text-danger-fg">
                        {error}
                    </p>
                )}
                {tab === 'followers' && followers && (
                    <section className="space-y-4" aria-label="Consented followers">
                        <h2 className="text-lg font-semibold text-ink">Consented addresses</h2>
                        {followers.data.length ? (
                            followers.data.map((row) => (
                                <article
                                    key={row.id}
                                    className="flex flex-wrap justify-between gap-3 rounded-lg border border-border bg-surface p-4 text-sm text-ink"
                                >
                                    <span className="break-all font-medium">
                                        {row.address}
                                        <span className="block text-xs text-ink-secondary">
                                            {row.channel === 'sms' ? 'SMS' : 'Email'} ·{' '}
                                            {row.source === 'event'
                                                ? 'Followed from an event'
                                                : 'Followed the organisation'}
                                        </span>
                                    </span>
                                    <span className="text-xs text-ink-secondary">
                                        Consented {new Date(row.consented_at).toLocaleDateString()}
                                    </span>
                                </article>
                            ))
                        ) : (
                            <p className="rounded-lg border border-border p-5 text-sm text-ink-secondary">
                                No followers yet. Share your public organisation page so people can
                                choose to follow.
                            </p>
                        )}
                        <div className="flex justify-end gap-3">
                            {followers.prev_page_url && (
                                <Button href={followers.prev_page_url}>Previous</Button>
                            )}
                            {followers.next_page_url && (
                                <Button href={followers.next_page_url}>More followers</Button>
                            )}
                        </div>
                    </section>
                )}
                {canSend && (tab === 'announcements' || tab === 'followers') && (
                    <section className="space-y-4" aria-label="Follower announcement history">
                        <h2 className="text-lg font-semibold text-ink">
                            Event announcement history
                        </h2>
                        {messages?.data?.length ? (
                            messages.data.map((message) => (
                                <article
                                    key={message.id}
                                    className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-surface p-4"
                                >
                                    <div className="min-w-0 flex-1">
                                        <h3 className="break-words text-sm font-medium text-ink">
                                            {message.subject}
                                        </h3>
                                        <p className="text-xs text-ink-secondary">
                                            {message.status}
                                            {message.scheduled_at
                                                ? ` · ${new Date(message.scheduled_at).toLocaleString()}`
                                                : ''}
                                        </p>
                                    </div>
                                    <Button
                                        type="button"
                                        disabled={busy}
                                        onClick={() => detail(message)}
                                    >
                                        View announcement
                                    </Button>
                                </article>
                            ))
                        ) : (
                            <p className="rounded-lg border border-border p-5 text-sm text-ink-secondary">
                                No event announcements yet. Review an event and its audience before
                                sending.
                            </p>
                        )}
                        <div className="flex justify-end gap-3">
                            {messages?.prev_page_url && (
                                <Button href={messages.prev_page_url}>
                                    Previous announcements
                                </Button>
                            )}
                            {messages?.next_page_url && (
                                <Button href={messages.next_page_url}>More announcements</Button>
                            )}
                        </div>
                    </section>
                )}
            </main>
            {compose && (
                <AnnouncementComposer
                    events={events}
                    canSchedule={canSchedule}
                    timezone={timezone}
                    offsets={offsets}
                    onClose={() => setCompose(false)}
                    onDone={() => {
                        setCompose(false);
                        router.reload();
                    }}
                />
            )}
            {selected && (
                <Modal
                    open
                    onClose={() => setSelected(null)}
                    title={selected.message.subject}
                    className="max-w-2xl"
                >
                    <div className="space-y-4 text-sm text-ink">
                        <p>{selected.message.status}</p>
                        <p className="whitespace-pre-wrap break-words">{selected.message.body}</p>
                        <p className="text-ink-secondary">
                            {selected.deliveries?.total ?? 0} selected deliveries. Accepted messages
                            cannot be recalled; uncertain provider outcomes are retained for review.
                        </p>
                        {selected.deliveries?.data?.map((row) => (
                            <p key={row.id} className="border-t border-border pt-2">
                                {row.channel} · {row.status}
                                {row.reason ? ` · ${row.reason.replaceAll('_', ' ')}` : ''}
                            </p>
                        ))}
                        <div className="flex flex-wrap gap-3">
                            {selected.deliveries?.prev_page_url && (
                                <Button
                                    type="button"
                                    onClick={async () => {
                                        try {
                                            setSelected(
                                                await communityRequest(
                                                    selected.deliveries.prev_page_url
                                                )
                                            );
                                        } catch (failure) {
                                            setError(failure.message);
                                        }
                                    }}
                                >
                                    Previous deliveries
                                </Button>
                            )}
                            {selected.deliveries?.next_page_url && (
                                <Button
                                    type="button"
                                    onClick={async () => {
                                        try {
                                            setSelected(
                                                await communityRequest(
                                                    selected.deliveries.next_page_url
                                                )
                                            );
                                        } catch (failure) {
                                            setError(failure.message);
                                        }
                                    }}
                                >
                                    More deliveries
                                </Button>
                            )}
                        </div>
                        {!['completed', 'partial', 'cancelled'].includes(
                            selected.message.status
                        ) && (
                            <Button
                                type="button"
                                onClick={() => {
                                    setCancel(selected.message);
                                    setSelected(null);
                                }}
                            >
                                Cancel unsent announcement
                            </Button>
                        )}
                    </div>
                </Modal>
            )}
            {cancel && (
                <Modal
                    open
                    onClose={() => {
                        if (!busy) setCancel(null);
                    }}
                    title="Cancel unsent announcement"
                >
                    <div className="space-y-4">
                        <p className="text-sm text-ink-secondary">
                            Stop remaining deliveries for “{cancel.subject}”. Messages already
                            submitted to a provider cannot be recalled.
                        </p>
                        <div className="flex flex-wrap gap-3">
                            <Button type="button" disabled={busy} onClick={cancelMessage}>
                                Confirm cancellation
                            </Button>
                            <Button type="button" disabled={busy} onClick={() => setCancel(null)}>
                                Keep announcement
                            </Button>
                        </div>
                        {error && (
                            <p role="alert" className="text-sm text-danger-fg">
                                {error}
                            </p>
                        )}
                    </div>
                </Modal>
            )}
        </ConsoleLayout>
    );
}
