import { useState } from 'react';
import { router } from '@inertiajs/react';
import Input from '@/Components/Console/Input';
import Select from '@/Components/Console/Select';
import Button from '@/Components/Console/Button';
import { csrfFetchFormData } from '@/lib/csrfFetch';

export default function EventHostsPanel({ event, canEdit }) {
    const [hosts, setHosts] = useState(event.hosts ?? []);
    const [version, setVersion] = useState(event.hosts_version ?? 0);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const [stale, setStale] = useState(false);
    const [saved, setSaved] = useState(false);
    const change = (index, values) => {
        setSaved(false);
        setHosts((rows) => rows.map((row, i) => (i === index ? { ...row, ...values } : row)));
    };
    const move = (index, offset) => {
        setSaved(false);
        setHosts((rows) => {
            const next = [...rows];
            [next[index], next[index + offset]] = [next[index + offset], next[index]];
            return next;
        });
    };
    const save = async (e) => {
        e.preventDefault();
        if (busy || stale) return;
        setBusy(true);
        setError(null);
        setSaved(false);
        const data = new FormData();
        data.set('version', String(version));
        // Multipart needs a present empty array when returning to the tenant fallback.
        if (hosts.length === 0) data.set('hosts', '');
        hosts.forEach((host, i) => {
            ['id', 'name', 'type', 'website_url'].forEach((key) => {
                if (host[key]) data.set(`hosts[${i}][${key}]`, host[key]);
            });
            if (host.logo) data.set(`hosts[${i}][logo]`, host.logo);
            data.set(`hosts[${i}][remove_logo]`, host.remove_logo ? '1' : '0');
        });
        try {
            const response = await csrfFetchFormData(
                route('tenant.events.hosts', { event: event.id }),
                data
            );
            const json = await response.json();
            if (!response.ok) {
                setStale(response.status === 409);
                setError(
                    Object.values(json.errors ?? {}).flat()[0] ??
                        json.message ??
                        'Could not save hosts.'
                );
                return;
            }
            setHosts(json.hosts);
            setVersion(json.version);
            setSaved(true);
        } catch {
            setError('Could not reach the server. Please try again.');
        } finally {
            setBusy(false);
        }
    };
    return (
        <section className="border border-border bg-surface p-5" aria-label="Public hosts">
            <h3 className="text-base font-semibold text-ink">Public hosts</h3>
            <p className="mt-2 text-sm leading-6 text-ink-secondary">
                Credit up to ten people or organisations. Your managing organisation keeps its
                support, following and payment responsibilities. Private event hosts remain hidden
                until attendee access is granted.
            </p>
            {hosts.length === 0 && (
                <p className="mt-3 text-sm text-ink-secondary">
                    Your managing organisation appears as the host when this list is empty.
                </p>
            )}
            {canEdit ? (
                <form onSubmit={save} className="mt-5 space-y-5">
                    <fieldset disabled={busy || stale} className="min-w-0 space-y-5">
                        {hosts.map((host, i) => (
                            <div
                                key={host.id ?? host.key}
                                className="space-y-3 border border-border p-4"
                            >
                                <Input
                                    label={`Host ${i + 1} name`}
                                    value={host.name}
                                    required
                                    maxLength={160}
                                    onChange={(e) => change(i, { name: e.target.value })}
                                />
                                <Select
                                    label={`Host ${i + 1} type`}
                                    value={host.type}
                                    onChange={(e) => change(i, { type: e.target.value })}
                                >
                                    <option value="organisation">Organisation</option>
                                    <option value="individual">Individual</option>
                                </Select>
                                <Input
                                    label={`Host ${i + 1} website`}
                                    type="url"
                                    placeholder="https://example.com"
                                    maxLength={2048}
                                    value={host.website_url ?? ''}
                                    onChange={(e) => change(i, { website_url: e.target.value })}
                                />
                                {host.logo_url && !host.remove_logo && (
                                    <img
                                        src={host.logo_url}
                                        alt={`${host.name} logo`}
                                        className="h-14 w-14 object-contain"
                                    />
                                )}
                                <Input
                                    label={`Host ${i + 1} logo`}
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp"
                                    hint="JPEG, PNG or WebP, up to 3 MiB and 4096 × 4096 pixels."
                                    onChange={(e) =>
                                        change(i, {
                                            logo: e.target.files[0] ?? null,
                                            remove_logo: false,
                                        })
                                    }
                                />
                                {host.logo_url && (
                                    <label className="flex items-center gap-2 text-sm text-ink">
                                        <input
                                            type="checkbox"
                                            checked={Boolean(host.remove_logo)}
                                            onChange={(e) =>
                                                change(i, {
                                                    remove_logo: e.target.checked,
                                                    logo: null,
                                                })
                                            }
                                        />
                                        Remove host {i + 1} logo
                                    </label>
                                )}
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        type="button"
                                        aria-label={`Move host ${i + 1} up`}
                                        disabled={i === 0}
                                        onClick={() => move(i, -1)}
                                    >
                                        Move up
                                    </Button>
                                    <Button
                                        type="button"
                                        aria-label={`Move host ${i + 1} down`}
                                        disabled={i === hosts.length - 1}
                                        onClick={() => move(i, 1)}
                                    >
                                        Move down
                                    </Button>
                                    <Button
                                        type="button"
                                        aria-label={`Remove host ${i + 1}`}
                                        onClick={() => {
                                            setHosts((rows) =>
                                                rows.filter((_, index) => index !== i)
                                            );
                                            setSaved(false);
                                        }}
                                    >
                                        Remove
                                    </Button>
                                </div>
                            </div>
                        ))}
                        <Button
                            type="button"
                            disabled={hosts.length >= 10}
                            onClick={() => {
                                setHosts((rows) => [
                                    ...rows,
                                    {
                                        key: crypto.randomUUID(),
                                        name: '',
                                        type: 'organisation',
                                        website_url: '',
                                    },
                                ]);
                                setSaved(false);
                            }}
                        >
                            Add host
                        </Button>
                    </fieldset>
                    {error && (
                        <p role="alert" className="text-sm text-danger-fg">
                            {error}
                        </p>
                    )}
                    {saved && (
                        <p role="status" className="text-sm text-ink-secondary">
                            Hosts saved.
                        </p>
                    )}
                    <div className="flex flex-wrap gap-2">
                        <Button type="submit" variant="primary" disabled={busy || stale}>
                            {busy ? 'Saving…' : 'Save hosts'}
                        </Button>
                        {stale && (
                            <Button
                                type="button"
                                onClick={() => router.reload({ only: ['event'] })}
                            >
                                Reload hosts
                            </Button>
                        )}
                    </div>
                </form>
            ) : (
                hosts.length > 0 && (
                    <ul className="mt-4 space-y-2 text-sm text-ink">
                        {hosts.map((host) => (
                            <li key={host.id}>{host.name}</li>
                        ))}
                    </ul>
                )
            )}
        </section>
    );
}
