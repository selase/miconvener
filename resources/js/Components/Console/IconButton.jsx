export default function IconButton({ icon: Icon, label, className = '', ...props }) {
    return (
        <button
            {...props}
            aria-label={label}
            className={`grid h-control w-control shrink-0 place-items-center rounded-md border border-border text-ink transition-colors duration-120 ease-out hover:bg-surface-hover disabled:pointer-events-none disabled:opacity-50 ${className}`}
        >
            <Icon className="h-4.5 w-4.5" strokeWidth={1.75} />
        </button>
    );
}
