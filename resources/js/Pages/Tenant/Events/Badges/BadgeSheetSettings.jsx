import Checkbox from '@/Components/Console/Checkbox';
import Input from '@/Components/Console/Input';
import Select from '@/Components/Console/Select';

export default function BadgeSheetSettings({ value, onChange }) {
    const update = (key, next) => onChange({ ...value, [key]: next });
    return (
        <div className="grid gap-3 sm:grid-cols-2">
            <label className="text-xs font-semibold text-slate-700 dark:text-slate-300">
                Paper
                <Select
                    value={value.paper}
                    onChange={(event) => update('paper', event.target.value)}
                >
                    <option value="a4">A4</option>
                    <option value="letter">Letter</option>
                </Select>
            </label>
            <label className="text-xs font-semibold text-slate-700 dark:text-slate-300">
                Margin (mm)
                <Input
                    type="number"
                    min="3"
                    max="30"
                    value={value.margin_mm}
                    onChange={(event) => update('margin_mm', Number(event.target.value))}
                />
            </label>
            <label className="text-xs font-semibold text-slate-700 dark:text-slate-300">
                Gap (mm)
                <Input
                    type="number"
                    min="0"
                    max="20"
                    value={value.gap_mm}
                    onChange={(event) => update('gap_mm', Number(event.target.value))}
                />
            </label>
            <label className="flex items-center gap-2 self-end pb-2 text-xs">
                <Checkbox
                    checked={value.crop_marks}
                    onChange={(checked) => update('crop_marks', checked)}
                />
                Crop marks
            </label>
        </div>
    );
}
