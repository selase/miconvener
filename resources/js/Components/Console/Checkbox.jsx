export default function Checkbox({ label, className = '', labelClassName = 'flex items-center gap-2 text-sm text-ink', ...props }) {
    return (
        <label className={labelClassName}>
            <input type="checkbox" {...props} className={`rounded border-border text-accent focus:ring-accent ${className}`} />
            {label}
        </label>
    );
}
