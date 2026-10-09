import { usePage } from '@inertiajs/react';
import ArtifactErrors from '../Certificates/ArtifactErrors';
import { artifactJson, artifactPdf, downloadArtifact } from '@/lib/artifactResponse';
import { useEffect, useMemo, useRef, useState } from 'react';
import Button from '@/Components/Console/Button';
import SearchInput from '@/Components/Console/SearchInput';
import Select from '@/Components/Console/Select';
import Modal from '@/Components/Console/Modal';
import BadgeLayoutPreview from '@/Pages/Tenant/Events/Badges/BadgeLayoutPreview';
import BadgeDesignEditor from '@/Pages/Tenant/Events/Badges/BadgeDesignEditor';
import { ArtifactFontStyles, DEFAULT_ARTIFACT_FONTS } from '../Certificates/ArtifactFonts';
import { Download, History, Palette, Printer } from 'lucide-react';
import csrfFetch, { csrfFetchFormData } from '@/lib/csrfFetch';

const exportReference = () => {
    if (crypto.randomUUID) return crypto.randomUUID();
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
};

export default function BadgesPanel({ event }) {
    const can = usePage().props.auth?.can || {};
    const [loadError, setLoadError] = useState(null);
    const [designError, setDesignError] = useState(null);
    const [loading, setLoading] = useState(true);
    const loadSequence = useRef(0);
    const [batch, setBatch] = useState(0);
    const [layoutDefaults, setLayoutDefaults] = useState({});
    const [tenantLogo, setTenantLogo] = useState(null);
    const [customLogo, setCustomLogo] = useState(null);
    const [badgeLogo, setBadgeLogo] = useState(null);
    const [fonts, setFonts] = useState(DEFAULT_ARTIFACT_FONTS);
    const exportAttempt = useRef(null);
    const downloadPending = useRef(false);
    const [retryAvailable, setRetryAvailable] = useState(false);
    const [badges, setBadges] = useState(null);
    const [recentPrints, setRecentPrints] = useState([]);
    const [query, setQuery] = useState('');
    const [printing, setPrinting] = useState(false);
    const [template, setTemplate] = useState(null);
    const [designerOpen, setDesignerOpen] = useState(false);
    const [savingDesign, setSavingDesign] = useState(false);
    const [error, setError] = useState(null);
    const [designForm, setDesignForm] = useState(null);

    const load = async () => {
        const sequence = ++loadSequence.current;
        setLoading(true);
        setLoadError(null);
        try {
            const data = await artifactJson(
                await csrfFetch(route('tenant.events.badges.index', { event: event.id })),
                'Badges could not be loaded. Please try again.'
            );
            if (sequence !== loadSequence.current) return;
            setBadges(data.badges || []);
            setLayoutDefaults(data.layout_defaults || data.template?.layout || {});
            setTenantLogo(data.tenant_logo || null);
            setBadgeLogo(Object.hasOwn(data, 'badge_logo') ? data.badge_logo : data.tenant_logo || null);
            setCustomLogo(data.custom_logo || null);
            setFonts(data.fonts || DEFAULT_ARTIFACT_FONTS);
            setRecentPrints(data.recent_prints || []);
            setTemplate(data.template || null);
        } catch (reason) {
            if (sequence === loadSequence.current) setLoadError(reason);
        } finally {
            if (sequence === loadSequence.current) setLoading(false);
        }
    };

    useEffect(() => {
        load();
        return () => {
            loadSequence.current += 1;
        };
    }, [event.id]);

    const filtered = useMemo(() => {
        if (!badges) {
            return [];
        }
        const q = query.trim().toLowerCase();
        if (!q) {
            return badges;
        }
        return badges.filter(
            (b) => b.full_name.toLowerCase().includes(q) || b.ticket_code?.toLowerCase().includes(q)
        );
    }, [badges, query]);

    const resultIds = filtered.map((badge) => badge.id).join(',');
    useEffect(() => {
        setBatch(0);
        setRetryAvailable(false);
    }, [query, resultIds]);
    const batchCount = Math.ceil(filtered.length / 100);
    const selectedBadges = filtered.slice(batch * 100, (batch + 1) * 100);

    const downloadPdf = async () => {
        if (downloadPending.current || selectedBadges.length === 0) return;
        downloadPending.current = true;
        const ids = selectedBadges.map((badge) => badge.id);
        const selection = JSON.stringify([event.id, template?.design_version, ids]);
        if (exportAttempt.current?.selection !== selection) {
            exportAttempt.current = { selection, reference: exportReference() };
        }
        const reference = exportAttempt.current.reference;
        setPrinting(true);
        setError(null);
        try {
            const response = await csrfFetch(
                route('tenant.events.badges.sheet', { event: event.id }),
                {
                    method: 'POST',
                    body: JSON.stringify({ registration_ids: ids, export_reference: reference }),
                }
            );
            downloadArtifact(
                await artifactPdf(
                    response,
                    'The badge PDF could not be generated. Please try again.'
                ),
                `${event.name || 'event'}-badges.pdf`
            );
            if (exportAttempt.current?.reference === reference) exportAttempt.current = null;
            setRetryAvailable(false);
            load();
        } catch (reason) {
            setError(reason);
            setRetryAvailable(true);
        } finally {
            downloadPending.current = false;
            setPrinting(false);
        }
    };

    const openDesigner = () => {
        setDesignError(null);
        setDesignForm({
            ...template,
            background: null,
            logo: null,
            remove_logo: false,
            remove_background: false,
            layout: template?.layout || {},
            background_settings: template?.background_settings || {
                fit: 'stretch',
                position: 'center',
            },
            tier_styles: template?.tier_styles || {},
            sheet_settings: template?.sheet_settings || {
                paper: 'a4',
                margin_mm: 8,
                gap_mm: 3,
                crop_marks: true,
            },
        });
        setDesignerOpen(true);
    };

    const saveDesign = async (eventObject) => {
        eventObject.preventDefault();
        setSavingDesign(true);
        setDesignError(null);
        const payload = new FormData();
        Object.entries(designForm).forEach(([key, value]) => {
            if (
                value === null ||
                value === undefined ||
                [
                    'id',
                    'tenant_id',
                    'event_id',
                    'created_at',
                    'updated_at',
                    'background_path',
                    'background_disk',
                    'logo_disk',
                    'logo_path',
                    'design_version',
                ].includes(key)
            )
                return;
            if (['layout', 'tier_styles', 'sheet_settings', 'background_settings'].includes(key))
                payload.append(key, JSON.stringify(value));
            else if (typeof value === 'boolean') payload.append(key, value ? '1' : '0');
            else payload.append(key, value);
        });
        try {
            const response = await csrfFetchFormData(
                route('tenant.events.badges.template.update', { event: event.id }),
                payload
            );
            const data = await artifactJson(
                response,
                'The badge design could not be saved. Please try again.'
            );
            setTemplate(data.template);
            await load();
            setDesignerOpen(false);
        } catch (reason) {
            setDesignError(reason);
        } finally {
            setSavingDesign(false);
        }
    };

    return (
        <div>
            <ArtifactFontStyles fonts={fonts} />
            <div className="no-print mb-5 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p className="text-sm text-ink-secondary">
                        Design once, print for everyone, reprint at the desk.
                    </p>
                    <p className="mt-1 text-xs text-ink-tertiary">
                        Search for a name to reprint just one badge, or leave it blank and print the
                        selected batch (up to 100 badges).
                    </p>
                </div>
                <div className="flex gap-2">
                    {can.update_badge_template && (
                        <Button icon={Palette} onClick={openDesigner} disabled={!template}>
                            Design badges
                        </Button>
                    )}
                    {can.update_event && (
                        <Button
                            icon={Download}
                            variant="primary"
                            onClick={downloadPdf}
                            disabled={selectedBadges.length === 0 || printing}
                        >
                            {printing
                                ? 'Generating…'
                                : `${retryAvailable ? 'Retry download' : 'Download'} ${selectedBadges.length || ''} badge${selectedBadges.length === 1 ? '' : 's'} PDF`}
                        </Button>
                    )}
                </div>
            </div>

            <ArtifactErrors error={error} />
            {loadError && (
                <div className="mb-4">
                    <ArtifactErrors error={loadError} />
                    <Button onClick={load}>Retry loading badges</Button>
                </div>
            )}

            <div className="no-print mb-5 flex flex-wrap items-end gap-4">
                <div>
                    <p className="mb-2 text-xs text-ink-secondary">
                        {filtered.length} matching badges · {batchCount} batch
                        {batchCount === 1 ? '' : 'es'}
                    </p>
                    {batchCount > 1 && (
                        <Select
                            label="Badge batch"
                            aria-label="Badge batch"
                            value={batch}
                            onChange={(e) => {
                                setBatch(Number(e.target.value));
                                setRetryAvailable(false);
                            }}
                        >
                            {Array.from({ length: batchCount }, (_, index) => (
                                <option key={index} value={index}>
                                    Batch {index + 1} · {index * 100 + 1}–
                                    {Math.min((index + 1) * 100, filtered.length)}
                                </option>
                            ))}
                        </Select>
                    )}
                </div>
                <SearchInput
                    placeholder="Search by name or entry code"
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    className="w-full max-w-sm"
                />
            </div>

            {loading && badges === null && (
                <p className="text-sm text-ink-secondary">Loading badges…</p>
            )}

            {badges !== null && badges.length === 0 && (
                <p className="text-sm text-ink-secondary">
                    No confirmed registrations yet — badges appear here once someone checks out.
                </p>
            )}

            {badges !== null && badges.length > 0 && filtered.length === 0 && (
                <p className="text-sm text-ink-secondary">No badge matches "{query}".</p>
            )}

            {filtered.length > 0 && (
                <div className="no-print mb-6 grid grid-cols-1 gap-1.5 text-xs text-ink-tertiary sm:grid-cols-2">
                    {filtered.map(
                        (b) =>
                            b.print_count > 0 && (
                                <div key={b.id} className="flex items-center gap-1.5">
                                    <Printer className="h-3 w-3" strokeWidth={1.75} />
                                    {b.full_name} — printed {b.print_count}×
                                </div>
                            )
                    )}
                </div>
            )}

            {filtered.length > 0 && (
                <div className="print-area grid grid-cols-1 gap-4 sm:grid-cols-2 print:grid-cols-2">
                    {selectedBadges.map((b) => (
                        <BadgeLayoutPreview
                            key={b.id}
                            event={event}
                            badge={b}
                            template={template || {}}
                            tenantLogo={badgeLogo}
                            backgroundUrl={
                                template?.background_path
                                    ? route('tenant.events.badges.template.artwork', {
                                          event: event.id,
                                      })
                                    : null
                            }
                        />
                    ))}
                </div>
            )}

            {recentPrints.length > 0 && (
                <div className="no-print mt-8 border border-border p-4">
                    <b className="mb-2 flex items-center gap-1.5 text-sm font-medium text-ink">
                        <History className="h-3.5 w-3.5" strokeWidth={1.75} /> Print history
                    </b>
                    <ul className="divide-y divide-border">
                        {recentPrints.map((p, i) => (
                            <li
                                key={i}
                                className="flex items-center justify-between py-1.5 text-[13px]"
                            >
                                <span className="text-ink">{p.registrant_name}</span>
                                <span className="text-ink-secondary">
                                    {p.printed_by} · {new Date(p.printed_at).toLocaleString()}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            <Modal
                open={designerOpen}
                onClose={() => setDesignerOpen(false)}
                title="Badge print studio"
                className="max-w-[1600px]"
                fullScreenOnMobile
            >
                <ArtifactErrors error={designError} />
                {designForm && (
                    <BadgeDesignEditor
                        fieldErrors={designError?.fields}
                        form={designForm}
                        eventId={event.id}
                        initialFonts={fonts}
                        onFontsChange={setFonts}
                        existingLogo={customLogo}
                        layoutDefaults={layoutDefaults}
                        tenantLogo={tenantLogo}
                        setForm={setDesignForm}
                        existingBackground={template?.background_path}
                        existingBackgroundUrl={
                            template?.background_path
                                ? route('tenant.events.badges.template.artwork', {
                                      event: event.id,
                                  })
                                : null
                        }
                        saving={savingDesign}
                        onSave={saveDesign}
                        onCancel={() => setDesignerOpen(false)}
                    />
                )}
            </Modal>
        </div>
    );
}
