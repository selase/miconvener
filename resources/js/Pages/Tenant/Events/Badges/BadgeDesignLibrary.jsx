import { useEffect, useRef, useState } from 'react';
import { BookOpen, Save, RotateCcw } from 'lucide-react';
import Button from '@/Components/Console/Button';
import Input from '@/Components/Console/Input';
import Select from '@/Components/Console/Select';
import SearchInput from '@/Components/Console/SearchInput';
import ArtifactErrors from '../Certificates/ArtifactErrors';
import BadgeLayoutPreview from './BadgeLayoutPreview';
import csrfFetch from '@/lib/csrfFetch';
import { artifactJson } from '@/lib/artifactResponse';

export default function BadgeDesignLibrary({
    event,
    template,
    ticketTypes = [],
    badges = [],
    onApplied,
    currentBackground,
    currentLogo,
}) {
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState('');
    const [query, setQuery] = useState('');
    const [orientation, setOrientation] = useState('all');
    const [source, setSource] = useState('all');
    const [priceFilter, setPriceFilter] = useState('all');
    const [name, setName] = useState('');
    const [scope, setScope] = useState('event');
    const [saveInto, setSaveInto] = useState('');
    const [selected, setSelected] = useState(null);
    const [preview, setPreview] = useState(null);
    const comparisonHeading = useRef(null);
    useEffect(() => {
        if (preview) {
            comparisonHeading.current?.focus();
            comparisonHeading.current?.scrollIntoView?.({ block: 'start' });
        }
    }, [preview]);
    const [mapping, setMapping] = useState({});
    const [size, setSize] = useState({ width_mm: 54, height_mm: 85 });
    const [restore, setRestore] = useState('');
    const [attendee, setAttendee] = useState('');
    const [rights, setRights] = useState(false);
    const [premium, setPremium] = useState(false);
    const [composition, setComposition] = useState('portrait');
    const [previewFace, setPreviewFace] = useState('front');
    const call = async (action, payload, extra = {}) =>
        artifactJson(
            await csrfFetch(
                route(`tenant.events.badges.library.${action}`, { event: event.id, ...extra }),
                payload ? { method: 'POST', body: JSON.stringify(payload) } : undefined
            ),
            'The design library could not be updated. Please try again.'
        );
    const load = async () => {
        setError(null);
        try {
            setData(await call('index'));
        } catch (reason) {
            setError(reason);
        }
    };
    useEffect(() => {
        load();
    }, [event.id]);
    const run = async (work) => {
        if (busy) return;
        setBusy(true);
        setError(null);
        setMessage('');
        try {
            await work();
        } catch (reason) {
            setError(reason);
        } finally {
            setBusy(false);
        }
    };
    const choose = (design, nextComposition = design.versions[0]?.orientation || 'portrait') =>
        run(async () => {
            const version = design.versions[0];
            const result = await call('preview', null, {
                version: version.id,
                composition: nextComposition,
            });
            setSelected(design);
            setPreview(result);
            setRestore('');
            setComposition(nextComposition);
            setPreviewFace('front');
            setSize({
                width_mm: result.template?.width_mm || version.width_mm,
                height_mm: result.template?.height_mm || version.height_mm,
            });
            const labels = {};
            for (const ticket of ticketTypes) {
                if (template.sheet_settings?.category_labels?.[ticket.id])
                    labels[ticket.id] = template.sheet_settings.category_labels[ticket.id];
                const matches = (result.template?.categories || []).filter(
                    (category) => category.ticket_name === ticket.name
                );
                if (matches.length === 1) labels[ticket.id] = matches[0].label;
            }
            setMapping(labels);
        });
    const save = () =>
        run(async () => {
            await call('store', {
                name,
                scope,
                expected_version: template.design_version,
                ...(saveInto ? { design_id: saveInto } : {}),
                ...(scope === 'platform'
                    ? { asset_rights_confirmed: rights, price_pesewas: premium ? 5000 : 0 }
                    : {}),
            });
            setMessage('Saved to the design library.');
            setName('');
            await load();
        });
    const replace = () =>
        run(async () => {
            await call('apply', {
                version_id: selected.versions[0].id,
                expected_version: template.design_version,
                category_labels: mapping,
                composition,
                ...size,
            });
            setMessage('Design applied. The previous design is available in Saved revisions.');
            setSelected(null);
            setPreview(null);
            await onApplied();
            await load();
        });
    const restoreDesign = () =>
        run(async () => {
            await call('restore', {
                revision_id: restore,
                expected_version: template.design_version,
            });
            setRestore('');
            await onApplied();
            await load();
            setMessage('Previous design restored.');
        });
    const shown = (data?.designs || []).filter(
        (design) =>
            design.name.toLowerCase().includes(query.toLowerCase()) &&
            (priceFilter === 'all' ||
                (priceFilter === 'paid' ? design.price_pesewas > 0 : design.price_pesewas === 0)) &&
            (source === 'all' ||
                (source === 'platform'
                    ? design.scope === 'platform'
                    : design.scope !== 'platform')) &&
            (orientation === 'all' ||
                design.versions[0]?.orientation === orientation ||
                design.versions[0]?.compositions?.includes(orientation))
    );
    const previewBadge = badges.find((badge) => badge.id === attendee) || badges[0];
    const displayTemplate = preview?.template
        ? {
              ...preview.template,
              ...size,
              background_settings: {
                  ...preview.template.background_settings,
                  fit:
                      size.width_mm !== preview.template.width_mm ||
                      size.height_mm !== preview.template.height_mm
                          ? 'contain'
                          : preview.template.background_settings?.fit || 'contain',
              },
              sheet_settings: { ...preview.template.sheet_settings, category_labels: mapping },
          }
        : null;
    return (
        <div className="space-y-6">
            <ArtifactErrors error={error} />
            {error && !data && <Button onClick={load}>Retry loading designs</Button>}
            {message && (
                <p role="status" className="text-sm text-accent">
                    {message}
                </p>
            )}
            <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_300px]">
                <div className="min-w-0 space-y-4">
                    <div className="flex flex-wrap gap-3">
                        <SearchInput
                            placeholder="Search designs"
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            className="min-w-0 flex-1"
                        />
                        <Select
                            aria-label="Design collection"
                            value={source}
                            onChange={(e) => setSource(e.target.value)}
                        >
                            <option value="all">All collections</option>
                            <option value="platform">MiConvener designs</option>
                            <option value="organisation">My organisation’s designs</option>
                        </Select>
                        <Select
                            aria-label="Design price"
                            value={priceFilter}
                            onChange={(e) => setPriceFilter(e.target.value)}
                        >
                            <option value="all">Free and paid</option>
                            <option value="free">Free designs</option>
                            <option value="paid">GH₵50 previews</option>
                        </Select>
                        <Select
                            aria-label="Design orientation"
                            value={orientation}
                            onChange={(e) => setOrientation(e.target.value)}
                        >
                            <option value="all">All sizes</option>
                            <option value="portrait">Portrait</option>
                            <option value="landscape">Landscape</option>
                        </Select>
                    </div>
                    {!data && !error && <p role="status">Loading designs…</p>}
                    {data && shown.length === 0 && (
                        <div className="border border-dashed border-border p-8 text-center text-ink-secondary">
                            <BookOpen className="mx-auto mb-3 h-6 w-6" />
                            <p>
                                No matching designs. Save your current event design to start your
                                collection.
                            </p>
                        </div>
                    )}
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        {shown.map((design) => (
                            <article
                                key={design.id}
                                className="flex flex-col border border-border bg-surface p-4"
                            >
                                <div
                                    className="mb-4 flex h-36 items-center justify-center bg-surface-subtle"
                                    aria-hidden="true"
                                >
                                    <img
                                        src={route('tenant.events.badges.library.thumbnail', {
                                            event: event.id,
                                            version: design.versions[0]?.id,
                                        })}
                                        loading="lazy"
                                        alt=""
                                        className="max-h-32 max-w-full border border-border object-contain shadow-sm"
                                    />
                                </div>
                                <h3 className="font-semibold text-ink">{design.name}</h3>
                                <p className="mt-1 text-xs text-ink-secondary">
                                    {design.versions[0]?.width_mm} × {design.versions[0]?.height_mm}{' '}
                                    mm · Version {design.versions[0]?.version}
                                </p>
                                <p className="mb-4 mt-1 text-xs text-ink-secondary">
                                    {design.scope === 'platform'
                                        ? 'MiConvener'
                                        : design.scope === 'event'
                                          ? 'This event only'
                                          : 'Organisation'}{' '}
                                    · {design.price_pesewas ? 'GH₵50 · Preview only' : 'Free'}
                                </p>
                                <Button
                                    className="mt-auto justify-center"
                                    disabled={busy}
                                    onClick={() => choose(design)}
                                    aria-label={`Preview ${design.name}`}
                                >
                                    Preview design
                                </Button>
                            </article>
                        ))}
                    </div>
                </div>
                <aside className="space-y-4 border border-border p-4">
                    <h3 className="font-semibold text-ink">Save current design</h3>
                    <label className="block text-sm">
                        Design name
                        <Input
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            maxLength={120}
                        />
                    </label>
                    <label className="block text-sm">
                        Who can use it?
                        <Select
                            value={scope}
                            onChange={(e) => {
                                setScope(e.target.value);
                                setSaveInto('');
                            }}
                        >
                            <option value="event">This event only</option>
                            {data?.can_share && (
                                <option value="organisation">My organisation</option>
                            )}
                            {data?.can_publish && (
                                <option value="platform">MiConvener catalogue</option>
                            )}
                        </Select>
                    </label>
                    <label className="block text-sm">
                        Save as
                        <Select value={saveInto} onChange={(e) => setSaveInto(e.target.value)}>
                            <option value="">New design</option>
                            {(data?.designs || [])
                                .filter((design) => design.scope === scope)
                                .map((design) => (
                                    <option key={design.id} value={design.id}>
                                        New version of {design.name}
                                    </option>
                                ))}
                        </Select>
                    </label>
                    {scope === 'platform' && (
                        <>
                            <label className="flex items-start gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={rights}
                                    onChange={(e) => setRights(e.target.checked)}
                                />
                                I have permission to publish this artwork for other organisations.
                            </label>
                            <label className="flex items-start gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={premium}
                                    onChange={(e) => setPremium(e.target.checked)}
                                />
                                GH₵50 premium design, preview only
                            </label>
                        </>
                    )}
                    <Button
                        icon={Save}
                        disabled={busy || !name.trim() || (scope === 'platform' && !rights)}
                        onClick={save}
                    >
                        Save current design
                    </Button>
                    <p className="text-xs text-ink-secondary">
                        Uses the last saved event design. Save changes in the studio first. Existing
                        library versions stay unchanged.
                    </p>
                    {(data?.revisions || []).length > 0 && (
                        <div className="space-y-3 border-t border-border pt-4">
                            <h3 className="font-semibold text-ink">Saved revisions</h3>
                            <label className="block text-sm">
                                Previous event design
                                <Select
                                    value={restore}
                                    onChange={(e) => {
                                        setRestore(e.target.value);
                                        setSelected(null);
                                    }}
                                >
                                    <option value="">Choose a revision</option>
                                    {data.revisions.map((revision) => (
                                        <option key={revision.id} value={revision.id}>
                                            Design {revision.design_version} ·{' '}
                                            {new Date(revision.created_at).toLocaleString()}
                                        </option>
                                    ))}
                                </Select>
                            </label>
                            {restore && (
                                <>
                                    <p className="text-xs text-ink-secondary">
                                        Replace the current saved design with this revision? Your
                                        current design will also be preserved.
                                    </p>
                                    <Button
                                        icon={RotateCcw}
                                        disabled={busy}
                                        onClick={restoreDesign}
                                    >
                                        Restore selected revision
                                    </Button>
                                </>
                            )}
                        </div>
                    )}
                </aside>
            </div>
            {selected && preview && (
                <section
                    className="space-y-4 border-t border-border pt-5"
                    aria-label="Design comparison"
                >
                    <h3
                        ref={comparisonHeading}
                        tabIndex={-1}
                        className="text-lg font-semibold focus:outline-none"
                    >
                        {selected.name}
                    </h3>
                    {preview.preview_image ? (
                        <>
                            <img
                                src={preview.preview_image}
                                alt="Watermarked paid design preview"
                                className="mx-auto max-h-96"
                            />
                            <p className="text-sm text-ink-secondary">
                                GH₵50 per event with unlimited reprints. Purchasing will be
                                available in the next stage.
                            </p>
                        </>
                    ) : (
                        <>
                            <div className="flex flex-wrap gap-3">
                                {(selected.versions[0]?.compositions || []).map((option) => (
                                    <Button
                                        key={option}
                                        disabled={busy}
                                        aria-pressed={composition === option}
                                        onClick={() => choose(selected, option)}
                                    >
                                        {option === 'portrait'
                                            ? 'Portrait composition'
                                            : 'Landscape composition'}
                                    </Button>
                                ))}
                                {displayTemplate?.back && (
                                    <Select
                                        aria-label="Preview face"
                                        value={previewFace}
                                        onChange={(e) => setPreviewFace(e.target.value)}
                                    >
                                        <option value="front">Front</option>
                                        <option value="back">Back</option>
                                    </Select>
                                )}
                            </div>
                            {badges.length > 0 && (
                                <label className="block max-w-sm text-sm">
                                    Preview attendee
                                    <Select
                                        value={attendee}
                                        onChange={(e) => setAttendee(e.target.value)}
                                    >
                                        {badges.map((badge) => (
                                            <option value={badge.id} key={badge.id}>
                                                {badge.full_name}
                                            </option>
                                        ))}
                                    </Select>
                                </label>
                            )}
                            <div className="grid gap-5 sm:grid-cols-2">
                                <div className="mx-auto w-full max-w-[240px]">
                                    <p className="mb-2 text-sm text-ink-secondary">
                                        Current event design
                                    </p>
                                    <BadgeLayoutPreview
                                        template={template}
                                        backgroundUrl={currentBackground}
                                        tenantLogo={currentLogo}
                                        badge={previewBadge}
                                        event={event}
                                    />
                                </div>
                                <div className="mx-auto w-full max-w-[240px]">
                                    <p className="mb-2 text-sm text-ink-secondary">
                                        Selected design · preview
                                    </p>
                                    <BadgeLayoutPreview
                                        template={
                                            previewFace === 'back' && displayTemplate?.back
                                                ? { ...displayTemplate, ...displayTemplate.back }
                                                : displayTemplate
                                        }
                                        backgroundUrl={
                                            previewFace === 'back'
                                                ? displayTemplate?.back_background
                                                : preview.background
                                        }
                                        tenantLogo={preview.logo}
                                        badge={previewBadge}
                                        event={event}
                                    />
                                </div>
                            </div>
                            <div className="grid max-w-sm grid-cols-2 gap-3">
                                {['width_mm', 'height_mm'].map((key) => (
                                    <label className="text-sm" key={key}>
                                        {key === 'width_mm' ? 'Width (mm)' : 'Height (mm)'}
                                        <Input
                                            type="number"
                                            min={40}
                                            max={210}
                                            value={size[key]}
                                            onChange={(e) =>
                                                setSize((value) => ({
                                                    ...value,
                                                    [key]: Number(e.target.value),
                                                }))
                                            }
                                        />
                                    </label>
                                ))}
                            </div>
                            <p className="text-xs text-ink-secondary">
                                Custom sizes support 40–210 mm per side and must fit the selected
                                paper. Background artwork uses Contain when resizing. Review the fit
                                before applying.
                            </p>
                            {ticketTypes.length > 0 && (
                                <fieldset className="grid gap-3 sm:grid-cols-2">
                                    <legend className="mb-2 text-sm font-semibold">
                                        Printed category labels for this event
                                    </legend>
                                    {ticketTypes.map((ticket) => (
                                        <label key={ticket.id} className="text-sm">
                                            {ticket.name}
                                            <Input
                                                value={mapping[ticket.id] || ''}
                                                placeholder={ticket.name}
                                                maxLength={80}
                                                onChange={(e) =>
                                                    setMapping((value) => ({
                                                        ...value,
                                                        [ticket.id]: e.target.value,
                                                    }))
                                                }
                                            />
                                        </label>
                                    ))}
                                </fieldset>
                            )}
                            <p className="text-sm text-ink-secondary">
                                Replace this event’s saved design? The current design will remain
                                available in Saved revisions.
                            </p>
                            <div className="flex flex-wrap gap-3">
                                <Button variant="primary" disabled={busy} onClick={replace}>
                                    Replace event design
                                </Button>
                                <Button
                                    disabled={busy}
                                    onClick={() => {
                                        setSelected(null);
                                        setPreview(null);
                                    }}
                                >
                                    Keep current design
                                </Button>
                                {(selected.scope === 'event' ||
                                    (selected.scope === 'organisation' && data?.can_share) ||
                                    (selected.scope === 'platform' && data?.can_publish)) && (
                                    <Button
                                        disabled={busy}
                                        onClick={() =>
                                            run(async () => {
                                                await call('withdraw', {}, { design: selected.id });
                                                setSelected(null);
                                                await load();
                                            })
                                        }
                                    >
                                        Withdraw from library
                                    </Button>
                                )}
                            </div>
                        </>
                    )}
                </section>
            )}
            {busy && (
                <p role="status" className="text-sm text-ink-secondary">
                    Updating design library…
                </p>
            )}
        </div>
    );
}
