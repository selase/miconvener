import { Head } from '@inertiajs/react';
import { CalendarX } from 'lucide-react';

/**
 * Shown while a deleted event sits in its recovery window: the organizer can
 * still restore it, so attendees are told it is unavailable rather than gone.
 */
export default function Suspended({ event, org }) {
    const date = new Date(event.starts_at).toLocaleDateString('en-GB', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });

    return (
        <div className="flex min-h-screen items-center justify-center bg-slate-50 px-4 py-10 font-sans text-slate-900 dark:bg-slate-950 dark:text-slate-100">
            <Head title={`${event.name} is unavailable`} />

            <div className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-8 text-center shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <CalendarX className="mx-auto h-10 w-10 text-slate-400" strokeWidth={1.5} />
                <h1 className="mt-4 text-xl font-bold tracking-tight">{event.name}</h1>
                <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">{date}</p>
                <p className="mt-6 text-sm leading-relaxed text-slate-600 dark:text-slate-300">
                    This event is not available right now. If you registered or bought a ticket,
                    please contact {org.name} for an update.
                </p>
            </div>
        </div>
    );
}
