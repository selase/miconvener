import { useEffect, useRef, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import Button from '@/Components/Console/Button';
import Input from '@/Components/Console/Input';
import Select from '@/Components/Console/Select';
import Modal from '@/Components/Console/Modal';
import { sendJson } from '@/lib/dues';

function GroupChoices({ selected, onChange }) {
    const [rows, setRows] = useState([]);
    const [search, setSearch] = useState('');
    const [next, setNext] = useState(null);
    const [error, setError] = useState(null);
    const [loading, setLoading] = useState(true);
    const serial = useRef(0);
    const load = async (url, append = false) => {
        const request = ++serial.current;
        setLoading(true);
        const result = await sendJson('GET', url);
        if (request !== serial.current) return;
        setLoading(false);
        if (result.ok) {
            setRows((current) =>
                append ? [...current, ...result.body.groups.data] : result.body.groups.data
            );
            setNext(result.body.groups.next_page_url);
            setError(null);
        } else setError(result.message);
    };
    useEffect(() => {
        load(route('tenant.community.messages.groups'));
        return () => {
            serial.current++;
        };
    }, []);
    return (
        <fieldset className="space-y-3 border border-border p-4">
            <legend className="px-1 text-sm font-medium text-ink">Choose active groups</legend>
            <div className="flex items-end gap-2">
                <Input
                    label="Search groups"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    maxLength={100}
                />
                <Button
                    type="button"
                    onClick={() =>
                        load(
                            `${route('tenant.community.messages.groups')}?search=${encodeURIComponent(search)}`
                        )
                    }
                >
                    Search
                </Button>
            </div>
            <p className="text-xs text-ink-secondary">
                {selected.length} selected. People in several groups receive one announcement per
                address and channel.
            </p>
            {error && (
                <p role="alert">
                    {error}{' '}
                    <button
                        type="button"
                        onClick={() => load(route('tenant.community.messages.groups'))}
                    >
                        Retry
                    </button>
                </p>
            )}
            {rows.map((group) => (
                <label key={group.id} className="flex gap-2 text-sm text-ink">
                    <input
                        type="checkbox"
                        checked={selected.includes(group.id)}
                        onChange={(e) =>
                            onChange(
                                e.target.checked
                                    ? [...selected, group.id]
                                    : selected.filter((id) => id !== group.id)
                            )
                        }
                    />
                    {group.name}
                </label>
            ))}
            {loading && (
                <p role="status" className="text-sm text-ink-secondary">
                    Loading groups…
                </p>
            )}
            {!rows.length && !error && !loading && (
                <p className="text-sm text-ink-secondary">No active groups found.</p>
            )}
            {next && (
                <Button type="button" onClick={() => load(next, true)}>
                    More groups
                </Button>
            )}
        </fieldset>
    );
}

export default function MessageComposer({
    draft = null,
    onClose,
    onDone,
    capabilities,
    timezone,
    offsets,
}) {
    const { auth } = usePage().props;
    const scheduled = draft?.scheduled_at;
    const initialLocal = scheduled
        ? new Date(scheduled)
              .toLocaleString('sv-SE', { timeZone: timezone })
              .slice(0, 16)
              .replace(' ', 'T')
        : '';
    const [form, setForm] = useState({
        subject: draft?.subject ?? '',
        body: draft?.body ?? '',
        channels: draft?.channels ?? ['email'],
        audience: draft?.audience ?? { kind: 'all_members' },
        timing: scheduled ? 'schedule' : 'now',
        local: initialLocal,
        offset: offsets[0] ?? '+00:00',
    });
    const [key] = useState(() => crypto.randomUUID());
    const [estimate, setEstimate] = useState(null);
    const [authority, setAuthority] = useState(false);
    const [busy, setBusy] = useState(null);
    const [error, setError] = useState(null);
    const revision = useRef(0);
    const change = (patch) => {
        revision.current++;
        setForm((current) => ({ ...current, ...patch }));
        setEstimate(null);
        setAuthority(false);
        setError(null);
    };
    useEffect(
        () => () => {
            revision.current++;
        },
        []
    );
    const payload = () => ({
        subject: form.subject,
        body: form.body,
        channels: form.channels,
        audience: form.audience,
        scheduled_at: form.timing === 'schedule' ? `${form.local}:00${form.offset}` : null,
    });
    const preview = async () => {
        if (busy) return;
        const version = revision.current;
        setBusy('preview');
        setError(null);
        const result = await sendJson(
            'POST',
            route('tenant.community.messages.preview'),
            payload()
        );
        setBusy(null);
        if (version !== revision.current) return;
        if (result.ok) setEstimate(result.body);
        else setError(Object.values(result.errors ?? {}).join(' ') || result.message);
    };
    const submit = async (mode) => {
        if (mode === 'send' && (!estimate || !authority)) return;
        setBusy(mode);
        setError(null);
        const result = await sendJson(
            draft ? 'PATCH' : 'POST',
            route(
                draft ? 'tenant.community.messages.update' : 'tenant.community.messages.store',
                draft ? { message: draft.id } : {}
            ),
            {
                ...payload(),
                idempotency_key: key,
                mode,
                ...(mode === 'send' ? { confirm_authority: authority } : {}),
            }
        );
        setBusy(null);
        if (!result.ok) {
            setEstimate(null);
            setAuthority(false);
            setError(Object.values(result.errors ?? {}).join(' ') || result.message);
            return;
        }
        onDone(result.body.message);
    };
    const canPreview = Boolean(
        form.subject.trim() &&
        form.body.trim() &&
        form.channels.length &&
        (!('group_ids' in form.audience) || form.audience.group_ids.length)
    );
    return (
        <Modal
            open
            onClose={() => !busy && onClose()}
            title={draft ? 'Edit announcement draft' : 'New announcement'}
            className="max-w-4xl"
            fullScreenOnMobile
        >
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    preview();
                }}
                className="space-y-5"
            >
                <p className="text-sm text-ink-secondary">
                    Share news with your community. Preferences, membership and permissions are
                    checked again at send time. Use dues reminders for personal balances and payment
                    links.
                </p>
                <fieldset disabled={busy === 'send' || busy === 'draft'} className="space-y-5">
                    <Input
                        label="Subject"
                        maxLength={255}
                        required
                        value={form.subject}
                        onChange={(e) => change({ subject: e.target.value })}
                    />
                    <div>
                        <label
                            htmlFor="announcement-body"
                            className="mb-1.5 block text-sm font-medium text-ink"
                        >
                            Announcement
                        </label>
                        <textarea
                            id="announcement-body"
                            required
                            maxLength={10000}
                            rows={5}
                            className="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none"
                            value={form.body}
                            onChange={(e) => change({ body: e.target.value })}
                        />
                        <p className="mt-1 text-xs text-ink-secondary">
                            {form.body.length} characters. SMS also includes the subject and an
                            opt-out link, within a total of 459 characters.
                        </p>
                    </div>
                    <fieldset className="flex flex-wrap gap-5">
                        <legend className="mb-2 text-sm font-medium text-ink">Channels</legend>
                        {['email', 'sms'].map((channel) => (
                            <label
                                key={channel}
                                className="flex items-center gap-2 text-sm text-ink"
                            >
                                <input
                                    type="checkbox"
                                    checked={form.channels.includes(channel)}
                                    onChange={(e) =>
                                        change({
                                            channels: e.target.checked
                                                ? [...form.channels, channel]
                                                : form.channels.filter((c) => c !== channel),
                                        })
                                    }
                                />
                                {channel === 'sms' ? 'SMS' : 'Email'}
                            </label>
                        ))}
                    </fieldset>
                    <div className="grid gap-5 sm:grid-cols-2">
                        <div className="space-y-3">
                            <Select
                                label="Audience"
                                value={form.audience.kind}
                                onChange={(e) =>
                                    change({
                                        audience:
                                            e.target.value === 'groups'
                                                ? { kind: 'groups', group_ids: [] }
                                                : e.target.value === 'status'
                                                  ? { kind: 'status', status: 'active' }
                                                  : { kind: e.target.value },
                                    })
                                }
                            >
                                <option value="all_members">All active members</option>
                                <option value="followers" disabled={!capabilities.followers}>
                                    Consented followers
                                    {!capabilities.followers ? ' (Following is off)' : ''}
                                </option>
                                <option
                                    value="members_and_followers"
                                    disabled={!capabilities.followers}
                                >
                                    Active members and consented followers
                                    {!capabilities.followers ? ' (Following is off)' : ''}
                                </option>
                                <option value="status">Members by status</option>
                                <option value="groups" disabled={!capabilities.groups}>
                                    Selected groups · Growth
                                </option>
                                {capabilities.finance && (
                                    <option value="arrears">Members with overdue dues</option>
                                )}
                            </Select>
                            {form.audience.kind === 'status' && (
                                <Select
                                    label="Membership status"
                                    value={form.audience.status}
                                    onChange={(e) =>
                                        change({
                                            audience: { ...form.audience, status: e.target.value },
                                        })
                                    }
                                >
                                    {['active', 'pending', 'lapsed', 'exited'].map((status) => (
                                        <option key={status} value={status}>
                                            {status}
                                        </option>
                                    ))}
                                </Select>
                            )}
                            {!capabilities.groups && (
                                <p className="text-xs text-ink-secondary">
                                    Group announcements are available on Growth. Groups themselves
                                    are available on every plan.
                                </p>
                            )}
                        </div>
                        <div className="space-y-3">
                            <Select
                                label="When to send"
                                value={form.timing}
                                onChange={(e) => change({ timing: e.target.value })}
                            >
                                <option value="now">Send now</option>
                                <option value="schedule" disabled={!capabilities.schedule}>
                                    Schedule · Starter or above
                                </option>
                            </Select>
                            {!capabilities.schedule && (
                                <p className="text-xs text-ink-secondary">
                                    Scheduling is available on Starter or above.{' '}
                                    {auth?.can?.manage_billing ? (
                                        <Link className="underline" href={route('billing.index')}>
                                            Review plans
                                        </Link>
                                    ) : (
                                        'Ask your organisation owner about upgrading.'
                                    )}
                                </p>
                            )}
                        </div>
                    </div>
                    {form.audience.kind === 'groups' && capabilities.groups && (
                        <GroupChoices
                            selected={form.audience.group_ids ?? []}
                            onChange={(ids) =>
                                change({ audience: { kind: 'groups', group_ids: ids } })
                            }
                        />
                    )}
                    {form.timing === 'schedule' && (
                        <div className="grid gap-3 border border-border p-4 sm:grid-cols-2">
                            <Input
                                label={`Send date and time · ${timezone}`}
                                type="datetime-local"
                                required
                                value={form.local}
                                disabled={!capabilities.schedule}
                                onChange={(e) => change({ local: e.target.value })}
                            />
                            <Select
                                label="UTC offset at this time"
                                value={form.offset}
                                disabled={!capabilities.schedule}
                                onChange={(e) => change({ offset: e.target.value })}
                            >
                                {offsets.map((offset) => (
                                    <option key={offset}>{offset}</option>
                                ))}
                            </Select>
                            <p className="text-xs text-ink-secondary sm:col-span-2">
                                The server checks daylight-saving offsets. Recipients and credit
                                availability can change before this time.{' '}
                                {!capabilities.schedule &&
                                    'Choose Send now explicitly or ask your owner to restore scheduling.'}
                            </p>
                        </div>
                    )}
                </fieldset>
                {error && (
                    <p
                        role="alert"
                        className="rounded-md border border-danger-fg/30 bg-danger-bg p-3 text-sm text-danger-fg"
                    >
                        {error}
                    </p>
                )}
                <Button type="submit" disabled={Boolean(busy) || !canPreview}>
                    {busy === 'preview' ? 'Checking audience…' : 'Preview audience'}
                </Button>
                {estimate && (
                    <section
                        aria-label="Audience confirmation"
                        className="space-y-3 border border-border bg-surface-hover p-4"
                    >
                        <h3 className="font-semibold text-ink">Review this announcement</h3>
                        <p className="text-sm text-ink">
                            {Object.entries(estimate.counts)
                                .map(([channel, count]) => `${count} ${channel}`)
                                .join(' · ')}
                        </p>
                        <p className="text-xs text-ink-secondary">
                            Audience: {form.audience.kind.replaceAll('_', ' ')}.{' '}
                            {estimate.scheduled_at
                                ? `Scheduled ${new Date(estimate.scheduled_at).toLocaleString(undefined, { timeZone: timezone })} · ${timezone}`
                                : 'Send now'}
                            .
                        </p>
                        <p className="text-xs text-ink-secondary">
                            Email credits: {estimate.email_credits_remaining ?? 'unlimited'}. SMS
                            credits: {estimate.sms_credits_remaining ?? 'unlimited'}; monthly SMS
                            remaining: {estimate.sms_monthly_remaining ?? 'no limit'}. These are
                            estimates, checked again when sending.
                        </p>
                        <label className="flex items-start gap-2 text-sm text-ink">
                            <input
                                className="mt-1"
                                type="checkbox"
                                checked={authority}
                                onChange={(e) => setAuthority(e.target.checked)}
                            />
                            I am authorised to contact this audience and have reviewed the content,
                            channels and timing.
                        </label>
                    </section>
                )}
                <div className="flex flex-wrap gap-3 border-t border-border pt-4">
                    <Button
                        type="button"
                        variant="primary"
                        disabled={Boolean(busy) || !estimate || !authority}
                        onClick={() => submit('send')}
                    >
                        {busy === 'send'
                            ? 'Submitting…'
                            : form.timing === 'schedule'
                              ? 'Schedule announcement'
                              : 'Send announcement'}
                    </Button>
                    <Button
                        type="button"
                        disabled={Boolean(busy) || !canPreview}
                        onClick={() => submit('draft')}
                    >
                        {busy === 'draft' ? 'Saving…' : 'Save draft'}
                    </Button>
                    <Button type="button" disabled={Boolean(busy)} onClick={onClose}>
                        Cancel
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
