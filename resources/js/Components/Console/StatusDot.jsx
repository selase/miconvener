const VARIANTS = {
    success: 'bg-success-fg',
    pending: 'bg-warning-fg',
    failed: 'bg-danger-fg',
    neutral: 'bg-ink-tertiary',
};

export default function StatusDot({ status = 'neutral' }) {
    return <span className={`inline-block h-2 w-2 rounded-full ${VARIANTS[status] ?? VARIANTS.neutral}`} />;
}
