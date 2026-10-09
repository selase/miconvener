import { useEffect, useState } from 'react';
import { ImagePlus, RotateCcw } from 'lucide-react';
import Button from '@/Components/Console/Button';
import ArtifactLayoutControls from '../Certificates/ArtifactLayoutControls';
import Input from '@/Components/Console/Input';
import Select from '@/Components/Console/Select';
import BadgeLayoutPreview from './BadgeLayoutPreview';
import BadgeSheetSettings from './BadgeSheetSettings';

const PRESETS = [
    { label: 'Standard landscape', width: 100, height: 70 },
    { label: 'Compact', width: 90, height: 55 },
    { label: 'Portrait', width: 70, height: 100 },
];

export default function BadgeDesignEditor({
    form,
    layoutDefaults,
    tenantLogo,
    setForm,
    existingBackground,
    existingBackgroundUrl,
    saving,
    onSave,
    onCancel,
    fieldErrors = {},
}) {
    const [backgroundUrl, setBackgroundUrl] = useState(null);
    const [selectedField, setSelectedField] = useState(
        'attendee_name' in layoutDefaults ? 'attendee_name' : Object.keys(layoutDefaults)[0]
    );
    const [mobileView, setMobileView] = useState('properties');
    const selectField = (key) => {
        setSelectedField(key);
        setMobileView('properties');
    };
    const backgroundSettings = form.background_settings || { fit: 'stretch', position: 'center' };

    const update = (key, value) =>
        setForm((current) => {
            const next = { ...current, [key]: value };
            if (['width_mm', 'height_mm'].includes(key))
                next.orientation = next.width_mm > next.height_mm ? 'landscape' : 'portrait';
            return next;
        });
    useEffect(() => {
        if (!form.background) {
            setBackgroundUrl(null);
            return undefined;
        }
        const url = URL.createObjectURL(form.background);
        setBackgroundUrl(url);
        return () => URL.revokeObjectURL(url);
    }, [form.background]);

    return (
        <form onSubmit={onSave} className="grid gap-5 lg:grid-cols-[360px_minmax(0,1fr)]">
            <div className="flex gap-2 lg:hidden col-span-full">
                {['properties', 'canvas'].map((view) => (
                    <Button
                        key={view}
                        type="button"
                        aria-pressed={mobileView === view}
                        onClick={() => setMobileView(view)}
                    >
                        {view === 'canvas' ? 'Canvas' : 'Properties'}
                    </Button>
                ))}
            </div>
            <div
                data-mobile-hidden={mobileView !== 'properties'}
                className={`${mobileView === 'properties' ? 'block' : 'hidden'} space-y-4 lg:block lg:max-h-[65vh] lg:overflow-y-auto lg:pr-2`}
            >
                <div>
                    <p className="text-xs font-semibold text-slate-700 dark:text-slate-300">
                        Badge size
                    </p>
                    <div className="mt-2 flex flex-wrap gap-2">
                        {PRESETS.map((preset) => (
                            <button
                                type="button"
                                key={preset.label}
                                onClick={() =>
                                    setForm((current) => ({
                                        ...current,
                                        width_mm: preset.width,
                                        height_mm: preset.height,
                                        orientation:
                                            preset.width > preset.height ? 'landscape' : 'portrait',
                                    }))
                                }
                                className="rounded-md border border-slate-300 px-3 py-2 text-xs font-semibold hover:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:border-slate-700"
                            >
                                {preset.label}
                            </button>
                        ))}
                    </div>
                </div>
                <div className="grid grid-cols-2 gap-3">
                    <label className="text-xs font-semibold text-slate-700 dark:text-slate-300">
                        Width (mm)
                        <Input
                            type="number"
                            aria-label="Width (mm)"
                            step="any"
                            min="40"
                            max="210"
                            error={fieldErrors.width_mm?.join(' ')}
                            value={form.width_mm}
                            onChange={(event) => update('width_mm', Number(event.target.value))}
                        />
                    </label>
                    <label className="text-xs font-semibold text-slate-700 dark:text-slate-300">
                        Height (mm)
                        <Input
                            type="number"
                            aria-label="Height (mm)"
                            step="any"
                            min="40"
                            max="210"
                            error={fieldErrors.height_mm?.join(' ')}
                            value={form.height_mm}
                            onChange={(event) => update('height_mm', Number(event.target.value))}
                        />
                    </label>
                </div>
                <div className="rounded-lg border border-dashed border-slate-300 p-3 dark:border-slate-700">
                    <label className="flex cursor-pointer items-center justify-center gap-2 rounded-md bg-slate-50 px-3 py-3 text-xs font-semibold hover:bg-indigo-50 focus-within:ring-2 focus-within:ring-indigo-500 dark:bg-slate-800">
                        <ImagePlus className="h-4 w-4" />
                        {form.background
                            ? form.background.name
                            : existingBackground && !form.remove_background
                              ? 'Replace background artwork'
                              : 'Upload background artwork'}
                        <input
                            type="file"
                            accept="image/png,image/jpeg"
                            className="sr-only"
                            onChange={(event) =>
                                setForm((current) => ({
                                    ...current,
                                    background: event.target.files?.[0] || null,
                                    remove_background: false,
                                }))
                            }
                        />
                    </label>
                    {(form.background || (existingBackground && !form.remove_background)) && (
                        <button
                            type="button"
                            onClick={() =>
                                setForm((current) => ({
                                    ...current,
                                    background: null,
                                    remove_background: true,
                                }))
                            }
                            className="mt-2 text-xs font-semibold text-rose-600"
                        >
                            Remove artwork
                        </button>
                    )}
                </div>
                <div className="grid grid-cols-2 gap-3">
                    <Select
                        label="Background fit"
                        aria-label="Background fit"
                        value={backgroundSettings.fit}
                        onChange={(event) =>
                            update('background_settings', {
                                ...backgroundSettings,
                                fit: event.target.value,
                            })
                        }
                    >
                        <option value="stretch">Stretch</option>
                        <option value="contain">Contain</option>
                        <option value="cover">Cover</option>
                    </Select>
                    <Select
                        label="Background position"
                        aria-label="Background position"
                        value={backgroundSettings.position}
                        onChange={(event) =>
                            update('background_settings', {
                                ...backgroundSettings,
                                position: event.target.value,
                            })
                        }
                    >
                        {[
                            'top-left',
                            'top',
                            'top-right',
                            'left',
                            'center',
                            'right',
                            'bottom-left',
                            'bottom',
                            'bottom-right',
                        ].map((position) => (
                            <option key={position} value={position}>
                                {position.replaceAll('-', ' ')}
                            </option>
                        ))}
                    </Select>
                </div>
                <p className="text-xs text-ink-secondary">
                    Contain shows the whole image. Cover fills the badge and crops its edges.
                    Stretch fills it without preserving proportions.
                </p>
                <ArtifactLayoutControls
                    selectedField={selectedField}
                    onSelectField={selectField}
                    allowUnderline
                    layout={{ ...layoutDefaults, ...form.layout }}
                    onChange={(layout) => update('layout', layout)}
                />
                <BadgeSheetSettings
                    value={form.sheet_settings}
                    onChange={(value) => update('sheet_settings', value)}
                />
                <div>
                    <p className="text-xs font-semibold text-slate-700 dark:text-slate-300">
                        Tier colours
                    </p>
                    <div className="mt-2 grid grid-cols-2 gap-3">
                        {['vip', 'speaker', 'staff'].map((tier) => (
                            <label
                                key={tier}
                                className="flex items-center justify-between rounded-md border border-slate-200 p-2 text-xs capitalize dark:border-slate-700"
                            >
                                {tier}
                                <input
                                    type="color"
                                    value={form.tier_styles?.[tier]?.background_color || '#4F46E5'}
                                    onChange={(event) =>
                                        update('tier_styles', {
                                            ...form.tier_styles,
                                            [tier]: {
                                                ...(form.tier_styles?.[tier] || {}),
                                                background_color: event.target.value.toUpperCase(),
                                                text_color: '#FFFFFF',
                                            },
                                        })
                                    }
                                />
                            </label>
                        ))}
                    </div>
                </div>
            </div>
            <aside
                data-mobile-hidden={mobileView !== 'canvas'}
                className={`${mobileView === 'canvas' ? 'block' : 'hidden'} space-y-3 lg:block lg:self-start`}
            >
                <BadgeLayoutPreview
                    selectedField={selectedField}
                    onSelectField={selectField}
                    template={{ ...form, layout: { ...layoutDefaults, ...form.layout } }}
                    tenantLogo={tenantLogo}
                    backgroundUrl={
                        backgroundUrl || (!form.remove_background ? existingBackgroundUrl : null)
                    }
                />
            </aside>
            <div className="sticky bottom-0 col-span-full flex flex-wrap gap-3 justify-between border-t border-slate-200 bg-surface py-3 dark:border-slate-700">
                <Button
                    type="button"
                    onClick={() => update('layout', structuredClone(layoutDefaults))}
                >
                    <RotateCcw className="mr-1.5 h-4 w-4" />
                    Reset fields
                </Button>
                <div className="flex gap-2">
                    <Button type="button" onClick={onCancel}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" disabled={saving}>
                        {saving ? 'Saving…' : 'Save design'}
                    </Button>
                </div>
            </div>
        </form>
    );
}
