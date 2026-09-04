export function Table({ children }) {
    return (
        <table className="w-full border-collapse text-left text-sm">{children}</table>
    );
}

export function Thead({ children }) {
    return (
        <thead>
            <tr className="bg-surface-sunken">{children}</tr>
        </thead>
    );
}

export function Th({ children, align = 'left' }) {
    return (
        <th
            className={`px-5 py-2 text-[11px] font-semibold uppercase tracking-wide text-ink-secondary first:rounded-l-sm last:rounded-r-sm ${
                align === 'right' ? 'text-right' : 'text-left'
            }`}
        >
            {children}
        </th>
    );
}

export function Tr({ children, onClick, selected = false, flagged = false }) {
    return (
        <tr
            onClick={onClick}
            aria-selected={selected}
            className={`border-b border-border transition-colors duration-120 ease-out last:border-0 ${
                onClick ? 'cursor-pointer' : ''
            } ${selected ? 'bg-surface-hover' : ''} ${flagged ? 'bg-danger-bg/40' : ''} ${
                onClick && !selected ? 'hover:bg-surface-hover' : ''
            }`}
        >
            {children}
        </tr>
    );
}

export function Td({ children, muted = false, align = 'left', numeric = false }) {
    return (
        <td
            className={`h-16 px-5 py-4 align-middle ${muted ? 'text-ink-secondary' : 'text-ink'} ${
                align === 'right' ? 'text-right' : 'text-left'
            } ${numeric ? 'num' : ''}`}
        >
            {children}
        </td>
    );
}

export function TableSkeleton({ columns = 5, rows = 5 }) {
    return (
        <>
            {Array.from({ length: rows }).map((_, rowIndex) => (
                <tr key={rowIndex} className="border-b border-border last:border-0">
                    {Array.from({ length: columns }).map((_, colIndex) => (
                        <td key={colIndex} className="h-16 px-5 py-4">
                            <div className="h-3 w-full max-w-32 rounded-sm bg-surface-sunken" />
                        </td>
                    ))}
                </tr>
            ))}
        </>
    );
}

export function TableEmpty({ title, description, action }) {
    return (
        <div className="border-b border-border py-14 px-5">
            <p className="text-[15px] font-semibold text-ink">{title}</p>
            {description && <span className="text-ink-secondary">{description}</span>}
            {action && <div className="mt-3.5">{action}</div>}
        </div>
    );
}
