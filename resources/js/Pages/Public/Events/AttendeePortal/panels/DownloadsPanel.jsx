import { Download, FileText } from 'lucide-react';

export default function DownloadsPanel({ materials }) {
    if (materials.length === 0) {
        return (
            <p className="text-left text-sm text-ink-secondary">
                Materials released by the organizers will appear here.
            </p>
        );
    }

    return (
        <ul className="divide-y divide-border border border-border text-left">
            {materials.map((m) => (
                <li key={m.id} className="flex items-center justify-between gap-3 px-4 py-3">
                    <div className="flex min-w-0 items-center gap-2.5">
                        <FileText
                            className="h-4 w-4 shrink-0 text-ink-secondary"
                            strokeWidth={1.5}
                        />
                        <div className="min-w-0">
                            <div className="truncate text-[13.5px] text-ink">{m.title}</div>
                            <div className="text-xs text-ink-secondary">
                                {m.remaining_attempts} of your attempts left
                            </div>
                        </div>
                    </div>
                    {m.remaining_attempts > 0 ? (
                        <a
                            href={m.download_url}
                            className="flex shrink-0 items-center gap-1.5 border border-border px-3 py-1.5 text-xs text-ink hover:border-accent hover:text-accent"
                        >
                            <Download className="h-3.5 w-3.5" strokeWidth={1.75} />
                            Download
                        </a>
                    ) : (
                        <span className="shrink-0 text-xs text-ink-secondary">
                            No attempts left
                        </span>
                    )}
                </li>
            ))}
        </ul>
    );
}
