import { useEffect, useMemo, useRef, useState } from 'react';
import Button from '@/Components/Console/Button';
import SearchInput from '@/Components/Console/SearchInput';
import Select from '@/Components/Console/Select';
import Modal from '@/Components/Console/Modal';
import BadgeLayoutPreview from '@/Pages/Tenant/Events/Badges/BadgeLayoutPreview';
import BadgeDesignEditor from '@/Pages/Tenant/Events/Badges/BadgeDesignEditor';
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
    const [batch, setBatch] = useState(0);
    const [layoutDefaults, setLayoutDefaults] = useState({});
    const [tenantLogo, setTenantLogo] = useState(null);
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

    const load = () => {
        csrfFetch(route('tenant.events.badges.index', { event: event.id }))
            .then((r) => r.json())
            .then((data) => {
                setBadges(data.badges);
                setLayoutDefaults(data.layout_defaults || data.template?.layout || {});
                setTenantLogo(data.tenant_logo || null);
                setRecentPrints(data.recent_prints);
                setTemplate(data.template);
            });
    };

    useEffect(load, [event.id]);

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
            if (!response.ok) {
                const data = await response.json();
                throw new Error(data.message || 'The badge PDF could not be generated.');
            }
            const url = URL.createObjectURL(await response.blob());
            const link = document.createElement('a');
            link.href = url;
            link.download = `${event.name || 'event'}-badges.pdf`;
            link.click();
            URL.revokeObjectURL(url);
            if (exportAttempt.current?.reference === reference) exportAttempt.current = null;
            setRetryAvailable(false);
            load();
        } catch (reason) {
            setError(reason.message);
            setRetryAvailable(true);
        } finally {
            downloadPending.current = false;
            setPrinting(false);
        }
    };

    const openDesigner = () => {
        setDesignForm({
            ...template,
            background: null,
            remove_background: false,
            layout: template?.layout || {},
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
        setError(null);
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
                    'design_version',
                ].includes(key)
            )
                return;
            if (['layout', 'tier_styles', 'sheet_settings'].includes(key))
                payload.append(key, JSON.stringify(value));
            else if (typeof value === 'boolean') payload.append(key, value ? '1' : '0');
            else payload.append(key, value);
        });
        try {
            const response = await csrfFetchFormData(
                route('tenant.events.badges.template.update', { event: event.id }),
                payload
            );
            const data = await response.json();
            if (!response.ok)
                throw new Error(data.message || 'The badge design could not be saved.');
            setTemplate(data.template);
            setDesignerOpen(false);
        } catch (reason) {
            setError(reason.message);
        } finally {
            setSavingDesign(false);
        }
    };

    return (
        <div>
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
                    <Button icon={Palette} onClick={openDesigner} disabled={!template}>
                        Design badges
                    </Button>
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
                </div>
            </div>

            {error && (
                <div className="no-print mb-4 rounded-md border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-200">
                    {error}
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

            {badges === null && <p className="text-sm text-ink-secondary">Loading badges…</p>}

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
                            tenantLogo={tenantLogo}
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
                className="max-w-6xl"
            >
                {designForm && (
                    <BadgeDesignEditor
                        form={designForm}
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
