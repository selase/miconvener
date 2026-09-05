import { Link } from '@inertiajs/react';

const VARIANTS = {
    default: 'border-border bg-surface text-ink hover:bg-surface-hover hover:border-border-strong',
    primary: 'border-accent bg-accent text-accent-ink hover:opacity-90',
    active: 'border-accent bg-accent-soft text-accent hover:border-accent',
};

export default function Button({ children, icon: Icon, href, disabled = false, variant = 'default', className = '', ...props }) {
    const classes = `inline-flex h-control items-center gap-2 rounded-md border px-4 text-sm font-medium transition-colors duration-120 ease-out disabled:pointer-events-none disabled:opacity-50 ${VARIANTS[variant] ?? VARIANTS.default} ${
        disabled ? 'pointer-events-none opacity-50' : ''
    } ${className}`;
    const content = (
        <>
            {Icon && <Icon className="h-4 w-4" strokeWidth={1.75} />}
            {children}
        </>
    );

    if (href) {
        if (disabled) {
            return (
                <span className={classes} aria-disabled="true">
                    {content}
                </span>
            );
        }

        return (
            <Link href={href} className={classes} {...props}>
                {content}
            </Link>
        );
    }

    return (
        <button {...props} disabled={disabled} className={classes}>
            {content}
        </button>
    );
}
