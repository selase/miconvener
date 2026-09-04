import { Link } from '@inertiajs/react';

export default function Button({ children, icon: Icon, href, disabled = false, className = '', ...props }) {
    const classes = `inline-flex h-control items-center gap-2 rounded-md border border-border bg-surface px-4 text-sm font-medium text-ink transition-colors duration-120 ease-out hover:bg-surface-hover hover:border-border-strong disabled:pointer-events-none disabled:opacity-50 ${
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
