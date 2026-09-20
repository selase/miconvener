export default function Input({ label, error, hint, className = '', ...props }) {
    return (
        <div>
            {label && <label className="mb-1.5 block text-sm font-medium text-ink">{label}</label>}
            <input
                {...props}
                className={`w-full rounded-lg border border-border px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent ${className}`}
            />
            {/* An error replaces the hint rather than stacking under it: once a
                field is wrong, what to fix matters more than what it is for. */}
            {error ? (
                <p className="mt-1 text-sm text-danger-fg">{error}</p>
            ) : (
                hint && <p className="mt-1 text-xs text-ink-secondary">{hint}</p>
            )}
        </div>
    );
}
