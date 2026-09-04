export default function LabelValueRow({ label, value, link = false, numeric = false }) {
    return (
        <div className="flex items-center justify-between gap-6">
            <dt className="text-ink">{label}</dt>
            <dd className={`text-right ${numeric ? 'num' : ''} ${link ? 'text-accent' : 'text-ink'}`}>{value}</dd>
        </div>
    );
}
