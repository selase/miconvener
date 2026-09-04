import { Link } from '@inertiajs/react';

export default function ChecklistItem({ done, number, href, label, onClick }) {
    const badgeClasses = done
        ? 'bg-success-bg text-success-fg'
        : 'bg-accent/10 text-accent';

    const labelClasses = done
        ? 'text-ink-secondary line-through'
        : 'text-ink hover:text-accent';

    const content = (
        <>
            <span className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-semibold ${badgeClasses}`}>
                {done ? '✓' : number}
            </span>
            <span className={`text-sm font-semibold ${labelClasses}`}>{label}</span>
        </>
    );

    if (onClick) {
        return (
            <button type="button" onClick={onClick} className="flex items-center gap-3">
                {content}
            </button>
        );
    }

    return (
        <Link href={href} className="flex items-center gap-3">
            {content}
        </Link>
    );
}
