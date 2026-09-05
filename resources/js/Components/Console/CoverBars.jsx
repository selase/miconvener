// Deterministic (not Math.random()) so server and client render the same
// bars — a seeded pattern avoids a hydration mismatch.
function barHeight(i) {
    const wave = Math.sin(i * 0.7) * 0.5 + Math.sin(i * 1.9) * 0.3;
    return Math.round(30 + ((wave + 1) / 2) * 65);
}

export default function CoverBars({ count = 48, height = 220, className = '' }) {
    const bars = Array.from({ length: count }, (_, i) => barHeight(i));

    return (
        <div
            className={`relative overflow-hidden border-b border-border bg-surface ${className}`}
            style={{ height }}
            aria-hidden="true"
        >
            <div className="absolute inset-0 flex items-end gap-0.5 px-1">
                {bars.map((h, i) => (
                    <span
                        key={i}
                        className="flex-1 bg-accent"
                        style={{ height: `${h}%`, opacity: i % 4 === 0 ? 0.62 : i % 3 === 0 ? 0.2 : 0.42 }}
                    />
                ))}
            </div>
        </div>
    );
}
