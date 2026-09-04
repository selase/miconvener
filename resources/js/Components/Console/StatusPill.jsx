const VARIANTS = {
    success: 'bg-success-bg text-success-fg',
    pending: 'bg-warning-bg text-warning-fg',
    failed: 'bg-danger-bg text-danger-fg',
    neutral: 'bg-neutral-bg text-neutral-fg',
};

export default function StatusPill({ status = 'neutral', children }) {
    return (
        <span className={`inline-block rounded-full px-2.5 py-0.5 text-xs font-medium ${VARIANTS[status] ?? VARIANTS.neutral}`}>
            {children}
        </span>
    );
}
