import { useEffect, useRef, useState } from 'react';
import { ImagePlus, RotateCcw } from 'lucide-react';
import Button from '@/Components/Console/Button';
import Checkbox from '@/Components/Console/Checkbox';
import Input from '@/Components/Console/Input';
import BadgeLayoutPreview from './BadgeLayoutPreview';
import BadgeSheetSettings from './BadgeSheetSettings';

const PRESETS = [
    { label: 'Standard landscape', width: 100, height: 70 },
    { label: 'Compact', width: 90, height: 55 },
    { label: 'Portrait', width: 70, height: 100 },
];

export default function BadgeDesignEditor({
    form,
    setForm,
    existingBackground,
    saving,
    onSave,
    onCancel,
}) {
    const [backgroundUrl, setBackgroundUrl] = useState(null);
    const initialLayout = useRef(form.layout);
    const update = (key, value) => setForm((current) => ({ ...current, [key]: value }));
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
        <form
            onSubmit={onSave}
            className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(320px,0.9fr)]"
        >
            <div className="space-y-4">
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
                            min="40"
                            max="210"
                            value={form.width_mm}
                            onChange={(event) => update('width_mm', Number(event.target.value))}
                        />
                    </label>
                    <label className="text-xs font-semibold text-slate-700 dark:text-slate-300">
                        Height (mm)
                        <Input
                            type="number"
                            min="40"
                            max="210"
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
                <div>
                    <p className="text-xs font-semibold text-slate-700 dark:text-slate-300">
                        Dynamic fields
                    </p>
                    <div className="mt-2 space-y-2">
                        {Object.entries(form.layout || {}).map(([key, item]) => (
                            <div
                                key={key}
                                className="grid grid-cols-[1fr_72px_72px] items-center gap-2 rounded-md border border-slate-200 p-2 dark:border-slate-700"
                            >
                                <label className="flex items-center gap-2 text-xs capitalize">
                                    <Checkbox
                                        checked={item.visible !== false}
                                        onChange={(checked) =>
                                            update('layout', {
                                                ...form.layout,
                                                [key]: { ...item, visible: checked },
                                            })
                                        }
                                    />
                                    {key.replaceAll('_', ' ')}
                                </label>
                                <Input
                                    aria-label={`${key} horizontal position`}
                                    type="number"
                                    min="0"
                                    max="1"
                                    step="0.01"
                                    value={item.x}
                                    onChange={(event) =>
                                        update('layout', {
                                            ...form.layout,
                                            [key]: { ...item, x: Number(event.target.value) },
                                        })
                                    }
                                />
                                <Input
                                    aria-label={`${key} vertical position`}
                                    type="number"
                                    min="0"
                                    max="1"
                                    step="0.01"
                                    value={item.y}
                                    onChange={(event) =>
                                        update('layout', {
                                            ...form.layout,
                                            [key]: { ...item, y: Number(event.target.value) },
                                        })
                                    }
                                />
                            </div>
                        ))}
                    </div>
                </div>
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
            <aside className="space-y-3 lg:sticky lg:top-0 lg:self-start">
                <BadgeLayoutPreview template={form} backgroundUrl={backgroundUrl} />
                <div className="flex justify-between border-t border-slate-200 pt-3 dark:border-slate-700">
                    <Button
                        type="button"
                        onClick={() => update('layout', structuredClone(initialLayout.current))}
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
            </aside>
        </form>
    );
}
