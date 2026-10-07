import { useState } from 'react';
import Input from '@/Components/Console/Input';
import Select from '@/Components/Console/Select';
import Checkbox from '@/Components/Console/Checkbox';

const labelFor = (key) =>
    key.replaceAll('_', ' ').replace(/^./, (character) => character.toUpperCase());

export default function ArtifactLayoutControls({ layout, onChange, requiredFields = [] }) {
    const [selected, setSelected] = useState(
        'recipient_name' in layout ? 'recipient_name' : Object.keys(layout)[0]
    );
    const field = layout[selected];
    if (!field) return null;
    const label = labelFor(selected);
    const update = (key, value) => onChange({ ...layout, [selected]: { ...field, [key]: value } });

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
                onChange={(event) => setSelected(event.target.value)}
            >
                {Object.keys(layout).map((key) => (
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
                        min={key === 'width' || key === 'height' ? 0.01 : 0}
                        max={1}
                        step={0.01}
                        required
                        value={field[key]}
                        onChange={(event) => update(key, Number(event.target.value))}
                    />
                ))}
            </div>
            <div className="grid grid-cols-2 gap-3">
                <Select
                    label="Font"
                    aria-label={`${label} Font`}
                    value={field.font_family}
                    onChange={(event) => update('font_family', event.target.value)}
                >
                    {['Helvetica', 'Times', 'Courier', 'DejaVu Sans'].map((font) => (
                        <option key={font}>{font}</option>
                    ))}
                </Select>
                <Input
                    label="Font size (pt)"
                    aria-label={`${label} Font size`}
                    type="number"
                    min={6}
                    max={96}
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
                    {[400, 500, 600, 700, 800].map((weight) => (
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
