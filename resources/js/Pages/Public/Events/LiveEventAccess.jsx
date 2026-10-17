import { useEffect } from 'react';
import { router } from '@inertiajs/react';
import { ArrowRight, BarChart2, MessageSquare, ClipboardList } from 'lucide-react';

export default function LiveEventAccess({ participation }) {
    useEffect(() => {
        if (!participation) return;
        const refresh = () => {
            if (!document.hidden && navigator.onLine) router.reload({ only: ['participation'] });
        };
        const interval = setInterval(refresh, 30000);
        document.addEventListener('visibilitychange', refresh);
        return () => {
            clearInterval(interval);
            document.removeEventListener('visibilitychange', refresh);
        };
    }, [participation?.entry_url]);

    if (!participation) return null;

    const href = (tab) => `${participation.entry_url}?tab=${tab}`;
    return (
        <section
            aria-label="Join the live event"
            className="mb-8 border-l-2 border-accent bg-accent/5 px-5 py-5 sm:px-6"
        >
            <div className="flex flex-wrap items-center justify-between gap-5">
                <div className="min-w-0">
                    <p className="mb-2 flex items-center gap-2 text-[11px] font-semibold uppercase tracking-[0.13em] text-accent">
                        {participation.is_live && (
                            <span className="h-1.5 w-1.5 bg-accent" aria-hidden="true" />
                        )}
                        {participation.is_live ? 'Happening now' : 'Your attendee area'}
                    </p>
                    <h2 className="text-[23px] font-medium tracking-tight text-ink">
                        Be part of the conversation.
                    </h2>
                    <p className="mt-2 text-sm leading-6 text-ink-secondary">
                        Follow the programme, answer polls, ask questions and share feedback as
                        activities open.
                    </p>
                </div>
                <a
                    href={href('agenda')}
                    className="inline-flex min-h-11 items-center gap-3 bg-accent px-5 py-3 text-sm font-medium text-accent-ink hover:opacity-90"
                >
                    {participation.is_live ? 'Join the live event' : 'Open attendee area'}
                    <ArrowRight className="h-4 w-4" aria-hidden="true" />
                </a>
            </div>
            <div className="mt-4 flex flex-wrap gap-x-6 gap-y-1 border-t border-border pt-2">
                <a
                    href={href('poll')}
                    className="inline-flex min-h-11 items-center gap-2 text-sm text-accent underline underline-offset-4"
                >
                    <BarChart2 className="h-4 w-4" aria-hidden="true" />
                    Live poll
                    {participation.has_live_poll && (
                        <span className="bg-surface px-2 py-0.5 text-[11px] text-ink no-underline">
                            Active
                        </span>
                    )}
                </a>
                <a
                    href={href('forum')}
                    className="inline-flex min-h-11 items-center gap-2 text-sm text-accent underline underline-offset-4"
                >
                    <MessageSquare className="h-4 w-4" aria-hidden="true" />
                    Ask a question
                </a>
                {participation.has_forms && (
                    <a
                        href={href('forms')}
                        className="inline-flex min-h-11 items-center gap-2 text-sm text-accent underline underline-offset-4"
                    >
                        <ClipboardList className="h-4 w-4" aria-hidden="true" />
                        Surveys & feedback
                    </a>
                )}
            </div>
            <p className="mt-2 text-xs leading-5 text-ink-secondary">
                Already registered? Use your ticket link or verify your registration email.
                Participation follows the event’s access rules.
            </p>
        </section>
    );
}
