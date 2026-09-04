import { CheckCircle2, AlertCircle, Clock } from 'lucide-react';

const VARIANTS = {
    success: { bg: 'bg-success-bg', fg: 'text-success-fg', icon: CheckCircle2 },
    pending: { bg: 'bg-warning-bg', fg: 'text-warning-fg', icon: Clock },
    failed: { bg: 'bg-danger-bg', fg: 'text-danger-fg', icon: AlertCircle },
    neutral: { bg: 'bg-neutral-bg', fg: 'text-neutral-fg', icon: CheckCircle2 },
};

export default function StatusBanner({ status = 'neutral', title, code, description }) {
    const variant = VARIANTS[status] ?? VARIANTS.neutral;
    const Icon = variant.icon;

    return (
        <div className={`flex gap-3 rounded-lg p-4 ${variant.bg}`}>
            <Icon className={`mt-0.5 h-5 w-5 shrink-0 ${variant.fg}`} strokeWidth={1.9} />
            <div>
                <div className="text-[15px] font-semibold text-ink">
                    {title} {code && <span className="font-normal text-ink-secondary">({code})</span>}
                </div>
                {description && <div className="mt-0.5 text-sm text-ink-secondary">{description}</div>}
            </div>
        </div>
    );
}
