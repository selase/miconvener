import { useEffect } from 'react';
import { Link, router } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import CheckInPanel from './CheckInPanel';
import { sectionHref } from './Workspace/sections';

/**
 * Check-in with nothing else on screen.
 *
 * Door staff do one job, usually on a phone, often standing up: the console
 * menus are in the way. The count refreshes on its own so whoever is on the
 * door can see the room filling without touching anything.
 */
export default function CheckInDoor({ event, counts }) {
    useEffect(() => {
        const timer = setInterval(() => router.reload({ only: ['counts'] }), 30000);
        return () => clearInterval(timer);
    }, []);

    const percent =
        counts.expected > 0 ? Math.round((counts.checked_in / counts.expected) * 100) : 0;

    return (
        <div className="flex min-h-screen flex-col bg-canvas font-console text-ink">
            <header className="flex items-center justify-between gap-3 border-b border-border px-4 py-3 sm:px-6">
                <div className="min-w-0">
                    <div className="truncate text-sm font-semibold text-ink">{event.name}</div>
                    <div className="text-xs text-ink-secondary">Check-in</div>
                </div>
                <div className="flex shrink-0 items-center gap-4">
                    <div className="text-right">
                        <div className="num text-lg font-semibold leading-none text-ink">
                            {counts.checked_in.toLocaleString()}
                            <span className="text-sm font-normal text-ink-tertiary">
                                {' '}
                                / {counts.expected.toLocaleString()}
                            </span>
                        </div>
                        <div className="num text-[11px] text-ink-secondary">{percent}% in</div>
                    </div>
                    <Link
                        href={sectionHref(event.id, 'check-in')}
                        className="inline-flex items-center gap-1.5 rounded-md border border-border px-3 py-2 text-[13px] font-medium text-ink-secondary hover:bg-surface-hover hover:text-ink"
                    >
                        <ArrowLeft className="h-3.5 w-3.5" strokeWidth={1.75} />
                        Exit
                    </Link>
                </div>
            </header>

            <div className="h-1.5 bg-surface-sunken" aria-hidden="true">
                <div
                    className="h-full bg-accent transition-[width] duration-500 motion-reduce:transition-none"
                    style={{ width: `${percent}%` }}
                />
            </div>

            <main className="mx-auto w-full max-w-3xl flex-1 px-4 py-5 sm:px-6">
                <CheckInPanel
                    event={event}
                    sessions={event.sessions || []}
                    scanUrl={route('tenant.events.checkin.scan', { event: event.id })}
                    searchUrl={route('tenant.events.checkin.search', { event: event.id })}
                    checkInUrlFor={(registrationId) =>
                        route('tenant.events.checkin', {
                            event: event.id,
                            registration: registrationId,
                        })
                    }
                />
            </main>
        </div>
    );
}
