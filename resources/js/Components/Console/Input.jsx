export default function Input({ label, error, className = '', ...props }) {
    return (
        <div>
            {label && <label className="mb-1.5 block text-sm font-medium text-ink">{label}</label>}
            <input
                {...props}
                className={`w-full rounded-lg border border-border px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent ${className}`}
            />
            {error && <p className="mt-1 text-sm text-danger-fg">{error}</p>}
        </div>
    );
}
