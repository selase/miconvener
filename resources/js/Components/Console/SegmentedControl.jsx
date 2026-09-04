export default function SegmentedControl({ options, value, onChange }) {
    return (
        <div className="inline-flex rounded-lg bg-surface-sunken p-1">
            {options.map((option) => (
                <button
                    key={option.value}
                    type="button"
                    aria-selected={option.value === value}
                    onClick={() => onChange(option.value)}
                    className={`rounded-md px-4 py-2 text-[15px] font-medium transition-colors duration-120 ease-out ${
                        option.value === value ? 'bg-surface text-ink shadow-raised' : 'text-ink-secondary'
                    }`}
                >
                    {option.label}
                </button>
            ))}
        </div>
    );
}
