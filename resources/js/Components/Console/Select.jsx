import { useId } from 'react';

export default function Select({ label, error, className = '', children, ...props }) {
    const generatedId = useId();
    const id = props.id ?? generatedId;
    const descriptionId = `${id}-description`;
    const describedBy =
        [props['aria-describedby'], error ? descriptionId : null].filter(Boolean).join(' ') ||
        undefined;

    return (
        <div>
            {label && (
                <label htmlFor={id} className="mb-1.5 block text-sm font-medium text-ink">
                    {label}
                </label>
            )}
            <select
                {...props}
                id={id}
                aria-describedby={describedBy}
                aria-invalid={error ? true : props['aria-invalid']}
                className={`w-full rounded-lg border border-border px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent ${className}`}
            >
                {children}
            </select>
            {error && (
                <p id={descriptionId} className="mt-1 text-sm text-danger-fg">
                    {error}
                </p>
            )}
        </div>
    );
}
