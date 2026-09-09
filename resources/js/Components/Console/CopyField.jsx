import { Copy, ExternalLink } from 'lucide-react';
import { useToast } from './Toast';

export default function CopyField({ label, value, display, href }) {
    const showToast = useToast();

    const copy = async () => {
        await navigator.clipboard.writeText(value);
        showToast?.('Copied to clipboard');
    };

    // A URL is more often wanted open than on the clipboard, so for one the text
    // becomes a real link and copying moves to its own button alongside. Every
    // other value keeps click-to-copy on the text itself.
    const target = href ?? (typeof value === 'string' && /^https?:\/\//.test(value) ? value : null);

    if (!target) {
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

    return (
        <div className="flex items-center justify-between gap-6">
            <dt className="text-ink">{label}</dt>
            <dd className="flex min-w-0 items-center justify-end gap-2 text-right">
                <a
                    href={target}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="group inline-flex min-w-0 items-center gap-1.5 text-accent"
                    title="Open in a new tab"
                >
                    <span className="truncate group-hover:underline">{display ?? value}</span>
                    <ExternalLink className="h-3.5 w-3.5 shrink-0" strokeWidth={1.9} />
                </a>
                <button
                    type="button"
                    onClick={copy}
                    className="shrink-0 text-ink-secondary hover:text-accent"
                    title="Copy to clipboard"
                    aria-label="Copy link to clipboard"
                >
                    <Copy className="h-3.5 w-3.5" strokeWidth={1.9} />
                </button>
            </dd>
        </div>
    );
}
