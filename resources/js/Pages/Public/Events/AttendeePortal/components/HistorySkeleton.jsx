export default function HistorySkeleton() {
    return (
        <div className="space-y-6 animate-pulse" aria-busy="true" aria-label="Loading your events">
            <div className="rounded-xl border border-border bg-surface p-6 space-y-4">
                <div className="flex items-center justify-between">
                    <div className="h-5 w-48 rounded bg-border" />
                    <div className="h-6 w-20 rounded-full bg-border" />
                </div>
                <div className="space-y-2">
                    <div className="h-4 w-3/4 rounded bg-border" />
                    <div className="h-4 w-1/2 rounded bg-border" />
                </div>
                <div className="pt-2 flex gap-3">
                    <div className="h-9 w-32 rounded-lg bg-border" />
                    <div className="h-9 w-24 rounded-lg bg-border" />
                </div>
            </div>

            <div className="rounded-xl border border-border bg-surface p-6 space-y-4">
                <div className="flex items-center justify-between">
                    <div className="h-5 w-40 rounded bg-border" />
                    <div className="h-6 w-16 rounded-full bg-border" />
                </div>
                <div className="space-y-2">
                    <div className="h-4 w-2/3 rounded bg-border" />
                </div>
                <div className="pt-2">
                    <div className="h-9 w-36 rounded-lg bg-border" />
                </div>
            </div>
        </div>
    );
}
