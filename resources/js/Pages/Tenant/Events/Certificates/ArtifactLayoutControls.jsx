import { useState } from 'react';
import Input from '@/Components/Console/Input';
import Select from '@/Components/Console/Select';
import Checkbox from '@/Components/Console/Checkbox';
import { DEFAULT_ARTIFACT_FONTS, artifactFontFamily } from './ArtifactFonts';

const labelFor = (key) =>
    key.replaceAll('_', ' ').replace(/^./, (character) => character.toUpperCase());

export default function ArtifactLayoutControls({
    layout,
    onChange,
    requiredFields = [],
    selectedField,
    onSelectField,
    allowUnderline = false,
    fonts = DEFAULT_ARTIFACT_FONTS,
    pageSize,
}) {
    const [internalSelected, setSelected] = useState(
        'recipient_name' in layout ? 'recipient_name' : Object.keys(layout)[0]
    );
    const selected = selectedField ?? internalSelected;
    const [fontSearch, setFontSearch] = useState('');
    const field = layout[selected];
    if (!field || field.removed) return null;
    const label = labelFor(selected);
    const graphic = ['qr', 'tenant_logo'].includes(selected);
    const update = (key, value) => {
        const changes = { [key]: value };
        if (selected === 'qr' && pageSize && ['width', 'height'].includes(key)) {
            changes.width = key === 'width' ? value : (value * pageSize.height) / pageSize.width;
            changes.height = (changes.width * pageSize.width) / pageSize.height;
            changes.x = Math.max(0, Math.min(field.x, 1 - changes.width));
            changes.y = Math.max(0, Math.min(field.y, 1 - changes.height));
        }
        onChange({ ...layout, [selected]: { ...field, ...changes } });
    };

    return (
        <section className="space-y-4 rounded-xl border border-border bg-surface p-4">
            <div>
                <h3 className="text-sm font-semibold text-ink">Dynamic fields</h3>
                <p className="mt-1 text-xs text-ink-secondary">
                    Positions and dimensions are fractions of the page, from 0 to 1.
                </p>
            </div>
            <Select
                label="Field to position"
                aria-label="Field to position"
                value={selected}
                onChange={(event) =>
                    onSelectField
                        ? onSelectField(event.target.value)
                        : setSelected(event.target.value)
                }
            >
                {Object.keys(layout)
                    .filter((key) => !layout[key].removed)
                    .map((key) => (
                        <option key={key} value={key}>
                            {labelFor(key)}
                        </option>
                    ))}
            </Select>
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                {['x', 'y', 'width', 'height'].map((key) => (
                    <Input
                        key={key}
                        label={labelFor(key)}
                        aria-label={`${label} ${labelFor(key)}`}
                        type="number"
                        min={0}
                        max={1}
                        step="any"
                        required
                        value={field[key]}
                        onChange={(event) => update(key, Number(event.target.value))}
                    />
                ))}
            </div>
            {!graphic && (
                <>
                    <Input
                        label="Search fonts"
                        aria-label="Search fonts"
                        value={fontSearch}
                        onChange={(event) => setFontSearch(event.target.value)}
                        placeholder="Find a font…"
                    />
                    <div className="grid grid-cols-2 gap-3">
                        <Select
                            label="Font"
                            aria-label={`${label} Font`}
                            value={field.font_family}
                            onChange={(event) => {
                                const family = event.target.value;
                                const font = fonts.find((item) => item.family === family);
                                onChange({
                                    ...layout,
                                    [selected]: {
                                        ...field,
                                        font_family: family,
                                        font_weight: font?.weights.includes(field.font_weight)
                                            ? field.font_weight
                                            : font?.weights[0] || 400,
                                    },
                                });
                            }}
                        >
                            {!fonts.some((font) => font.family === field.font_family) && (
                                <option value={field.font_family}>Saved font</option>
                            )}
                            {fonts
                                .filter(
                                    (font) =>
                                        (!font.archived || font.family === field.font_family) &&
                                        (font.family === field.font_family ||
                                            font.name
                                                .toLowerCase()
                                                .includes(fontSearch.toLowerCase()))
                                )
                                .map((font) => (
                                    <option
                                        key={font.family}
                                        value={font.family}
                                        style={{ fontFamily: artifactFontFamily(font.family) }}
                                    >
                                        {font.name}
                                    </option>
                                ))}
                        </Select>
                        <Input
                            label="Font size (pt)"
                            aria-label={`${label} Font size`}
                            type="number"
                            min={6}
                            max={96}
                            step="any"
                            required
                            value={field.font_size}
                            onChange={(event) => update('font_size', Number(event.target.value))}
                        />
                        <Select
                            label="Weight"
                            aria-label={`${label} Weight`}
                            value={field.font_weight}
                            onChange={(event) => update('font_weight', Number(event.target.value))}
                        >
                            {Array.from(
                                new Set([
                                    ...(fonts.find((font) => font.family === field.font_family)
                                        ?.weights || [400, 700]),
                                    field.font_weight || 400,
                                ])
                            )
                                .sort()
                                .map((weight) => (
                                    <option key={weight} value={weight}>
                                        {weight}
                                    </option>
                                ))}
                        </Select>
                        <Select
                            label="Alignment"
                            aria-label={`${label} Alignment`}
                            value={field.align}
                            onChange={(event) => update('align', event.target.value)}
                        >
                            {['left', 'center', 'right'].map((align) => (
                                <option key={align} value={align}>
                                    {labelFor(align)}
                                </option>
                            ))}
                        </Select>
                    </div>
                    <Input
                        label="Text color"
                        aria-label={`${label} Color`}
                        type="color"
                        value={field.color}
                        onChange={(event) => update('color', event.target.value)}
                    />
                </>
            )}
            {allowUnderline && !['qr', 'tenant_logo'].includes(selected) && (
                <Checkbox
                    label={`Underline ${label}`}
                    checked={field.underline === true}
                    onChange={(event) => update('underline', event.target.checked)}
                />
            )}
            {requiredFields.includes(selected) ? (
                <p className="text-xs text-ink-secondary">
                    This identity field always remains visible.
                </p>
            ) : (
                <Checkbox
                    label={`Show ${label}`}
                    checked={field.visible !== false}
                    onChange={(event) => update('visible', event.target.checked)}
                />
            )}
        </section>
    );
}
