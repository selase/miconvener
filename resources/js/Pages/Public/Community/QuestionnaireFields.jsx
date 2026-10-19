import Input from '@/Components/Console/Input';
import Select from '@/Components/Console/Select';

export const QUESTION_TYPES = {
    multiple_choice: 'Choose one',
    yes_no: 'Yes or no',
    rating: 'Rating (1–5)',
    scale: 'Scale',
    number: 'Number',
    multi_select: 'Choose several',
    open: 'Written response',
    word_cloud: 'Up to three words',
    ranking: 'Rank choices',
    quiz: 'Knowledge question (no scoring)',
};

export default function QuestionnaireFields({ question, value = {}, onChange, disabled = false }) {
    const title = `${question.title}${question.required ? ' *' : ' (optional)'}`;
    const choices = question.options || [];
    if (['multiple_choice', 'yes_no', 'rating', 'quiz'].includes(question.type))
        return (
            <fieldset disabled={disabled} className="space-y-2 border border-border p-4">
                <legend className="px-1 font-medium text-ink">{title}</legend>
                {choices.map((option) => (
                    <label key={option.id} className="flex min-h-11 items-center gap-3 text-ink">
                        <input
                            type="radio"
                            name={question.id}
                            checked={value.option_id === option.id}
                            onChange={() => onChange({ option_id: option.id })}
                        />
                        {option.label}
                    </label>
                ))}
            </fieldset>
        );
    if (question.type === 'multi_select')
        return (
            <fieldset disabled={disabled} className="space-y-2 border border-border p-4">
                <legend className="px-1 font-medium text-ink">{title}</legend>
                {choices.map((option) => (
                    <label key={option.id} className="flex min-h-11 items-center gap-3 text-ink">
                        <input
                            type="checkbox"
                            checked={(value.option_ids || []).includes(option.id)}
                            onChange={(event) =>
                                onChange({
                                    option_ids: event.target.checked
                                        ? [...(value.option_ids || []), option.id]
                                        : (value.option_ids || []).filter((id) => id !== option.id),
                                })
                            }
                        />
                        {option.label}
                    </label>
                ))}
            </fieldset>
        );
    if (question.type === 'ranking')
        return (
            <fieldset disabled={disabled} className="space-y-3 border border-border p-4">
                <legend className="px-1 font-medium text-ink">{title}</legend>
                <p className="text-sm text-ink-secondary">
                    Choose each option once, from first to last.
                </p>
                {choices.map((option, index) => (
                    <Select
                        key={option.id}
                        label={`Position ${index + 1}`}
                        value={value.option_ids?.[index] || ''}
                        onChange={(event) => {
                            const ids = [...(value.option_ids || Array(choices.length).fill(''))];
                            ids[index] = event.target.value;
                            onChange({ option_ids: ids });
                        }}
                    >
                        <option value="">Choose</option>
                        {choices.map((choice) => (
                            <option key={choice.id} value={choice.id}>
                                {choice.label}
                            </option>
                        ))}
                    </Select>
                ))}
            </fieldset>
        );
    if (question.type === 'word_cloud')
        return (
            <fieldset disabled={disabled} className="space-y-3 border border-border p-4">
                <legend className="px-1 font-medium text-ink">{title}</legend>
                {[0, 1, 2].map((index) => (
                    <Input
                        key={index}
                        label={`Word ${index + 1}`}
                        maxLength={40}
                        value={value.words?.[index] || ''}
                        onChange={(event) => {
                            const words = [...(value.words || ['', '', ''])];
                            words[index] = event.target.value;
                            onChange({ words });
                        }}
                    />
                ))}
            </fieldset>
        );
    if (question.type === 'open')
        return (
            <label className="block font-medium text-ink">
                {title}
                <textarea
                    disabled={disabled}
                    className="mt-2 min-h-32 w-full border border-border bg-surface p-3 text-ink"
                    maxLength={1000}
                    value={value.response_text || ''}
                    onChange={(event) =>
                        onChange(event.target.value ? { response_text: event.target.value } : {})
                    }
                />
            </label>
        );
    return (
        <Input
            disabled={disabled}
            label={title}
            type="number"
            step={question.type === 'scale' ? 1 : '0.0001'}
            min={question.type === 'scale' ? (question.settings?.min ?? 1) : -1000000000}
            max={question.type === 'scale' ? (question.settings?.max ?? 10) : 1000000000}
            hint={
                question.type === 'number'
                    ? question.settings?.unit
                    : `${question.settings?.label_min || question.settings?.min || 1} to ${question.settings?.label_max || question.settings?.max || 10}`
            }
            value={value.response_number ?? ''}
            onChange={(event) =>
                onChange(event.target.value === '' ? {} : { response_number: event.target.value })
            }
        />
    );
}
