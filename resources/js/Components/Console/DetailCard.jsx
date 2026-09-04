export default function DetailCard({ title, children }) {
    return (
        <div className="rounded-lg border border-border p-5 sm:p-6">
            {title && <h2 className="mb-4 text-base font-semibold text-ink">{title}</h2>}
            <dl className="space-y-3.5">{children}</dl>
        </div>
    );
}
