import { Copy } from 'lucide-react';
import { useToast } from './Toast';

export default function CopyField({ label, value, display }) {
    const showToast = useToast();

    const copy = async () => {
        await navigator.clipboard.writeText(value);
        showToast?.('Copied to clipboard');
    };

    return (
        <div className="flex items-center justify-between gap-6">
            <dt className="text-ink">{label}</dt>
            <dd className="text-right">
                <button
                    type="button"
                    onClick={copy}
                    className="group inline-flex max-w-55 items-center gap-1.5 text-accent"
                    title="Copy to clipboard"
                >
                    <span className="truncate group-hover:underline">{display ?? value}</span>
                    <Copy className="h-3.5 w-3.5 shrink-0" strokeWidth={1.9} />
                </button>
            </dd>
        </div>
    );
}
