import { useState } from 'react';
import { router } from '@inertiajs/react';
import Input from '@/Components/Console/Input';
import Select from '@/Components/Console/Select';
import Button from '@/Components/Console/Button';
import csrfFetch from '@/lib/csrfFetch';

const LINKS = {
    website: 'Website',
    instagram: 'Instagram',
    x: 'X',
    facebook: 'Facebook',
    linkedin: 'LinkedIn',
    youtube: 'YouTube',
};

export default function EventPublicBrandingPanel({ event, canEdit }) {
    const [showManager, setShowManager] = useState(event.show_managing_organisation ?? true);
    const [mode, setMode] = useState(event.social_links_mode ?? 'organisation');
    const [links, setLinks] = useState(event.social_links ?? {});
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [saved, setSaved] = useState(false);
    if (!canEdit) return null;
    const change = (setter, value) => {
        setSaved(false);
        setter(value);
    };
    const save = async (e) => {
        e.preventDefault();
        setBusy(true);
        setError('');
        setSaved(false);
        try {
            const response = await csrfFetch(
                route('tenant.events.public-branding', { event: event.id }),
                {
                    method: 'PATCH',
                    body: JSON.stringify({
                        show_managing_organisation: showManager,
                        social_links_mode: mode,
                        social_links: links,
                    }),
                }
            );
            const json = await response.json().catch(() => ({}));
            if (!response.ok) {
                setError(
                    Object.values(json.errors ?? {})
                        .flat()
                        .join(' ') ||
                        json.message ||
                        'Could not save public page options.'
                );
                return;
            }
            setShowManager(json.show_managing_organisation);
            setMode(json.social_links_mode);
            setLinks(json.social_links ?? {});
            setSaved(true);
            router.reload({ only: ['event'] });
        } catch {
            setError('Could not reach the server. Please try again.');
        } finally {
            setBusy(false);
        }
    };
    return (
        <section
            className="border border-border bg-surface p-5"
            aria-label="Public page identity and links"
        >
            <h3 className="text-base font-semibold text-ink">Public page identity and links</h3>
            <p className="mt-2 text-sm leading-6 text-ink-secondary">
                Choose how this event credits your organisation and which social pages or posts
                visitors see. Event hosts are managed separately above.
            </p>
            <form onSubmit={save} className="mt-4 space-y-4">
                <fieldset disabled={busy} className="space-y-4">
                    <label className="flex items-start gap-3 text-sm text-ink">
                        <input
                            type="checkbox"
                            className="mt-1 h-4 w-4"
                            checked={showManager}
                            onChange={(e) => change(setShowManager, e.target.checked)}
                        />
                        <span>
                            Show managing organisation credit
                            <span className="mt-1 block text-xs text-ink-secondary">
                                Show your organisation in the footer and managing credits. Turn this
                                off when the event is hosted by someone else. The named hosts remain
                                visible.
                            </span>
                        </span>
                    </label>
                    <Select
                        label="Social links to display"
                        value={mode}
                        onChange={(e) => change(setMode, e.target.value)}
                    >
                        <option value="organisation">Organisation links</option>
                        <option value="event">Event-specific links</option>
                        <option value="hidden">Hide social links</option>
                    </Select>
                    {mode === 'event' && (
                        <div className="space-y-3">
                            <p className="text-xs text-ink-secondary">
                                Use full http:// or https:// URLs to a profile, event page or post.
                                Blank fields stay hidden; organisation links are not added.
                            </p>
                            <div className="grid min-w-0 gap-4 sm:grid-cols-2">
                                {Object.entries(LINKS).map(([key, label]) => (
                                    <Input
                                        key={key}
                                        label={`Event ${label} link`}
                                        type="url"
                                        maxLength={2048}
                                        value={links[key] ?? ''}
                                        placeholder="https://…"
                                        onChange={(e) =>
                                            change(setLinks, { ...links, [key]: e.target.value })
                                        }
                                    />
                                ))}
                            </div>
                        </div>
                    )}
                </fieldset>
                {error && (
                    <p role="alert" className="text-sm text-danger-fg">
                        {error}
                    </p>
                )}
                {saved && (
                    <p role="status" className="text-sm text-success-fg">
                        Public page options saved.
                    </p>
                )}
                <Button type="submit" disabled={busy}>
                    {busy ? 'Saving…' : 'Save public page options'}
                </Button>
            </form>
        </section>
    );
}
