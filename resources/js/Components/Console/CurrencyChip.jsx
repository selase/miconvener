export default function CurrencyChip({ children }) {
    return (
        <span className="inline-block rounded-sm border border-border px-1.5 py-0.5 text-[11px] font-semibold uppercase leading-none text-ink-secondary">
            {children}
        </span>
    );
}
