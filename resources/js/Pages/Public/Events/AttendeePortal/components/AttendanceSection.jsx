import { CheckCircle2, Clock, Award, Building2, Calendar, Info, Sparkles, ExternalLink } from 'lucide-react';

function formatDateTime(isoString) {
    if (!isoString) return '';
    try {
        const date = new Date(isoString);
        return date.toLocaleDateString(undefined, {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    } catch {
        return isoString;
    }
}

function formatDate(isoString) {
    if (!isoString) return '';
    try {
        const date = new Date(isoString);
        return date.toLocaleDateString(undefined, {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });
    } catch {
        return isoString;
    }
}

/**
 * Attendance Section
 * Strictly maintains separation between "Sessions attended" and "Certified hours".
 *
 * CRITICAL INVARIANT (Spec §7.3, §7.4, §11.4):
 * Strictly NO manufactured sum / total of attendance hours or dwell-derived CPD hours.
 * Sessions attended and Certified hours must remain visibly separate.
 */
export default function AttendanceSection({ attendance = [], certificates = [] }) {
    // Extract certificates that carry formally certified CPD/CME hours
    const certifiedHoursRecords = (certificates || []).filter(
        (cert) => cert.cpd_hours && Number(cert.cpd_hours) > 0
    );

    const hasSessions = attendance.length > 0;
    const hasCertifiedHours = certifiedHoursRecords.length > 0;

    if (!hasSessions && !hasCertifiedHours) {
        return (
            <div className="rounded-xl border border-border bg-surface-subtle p-8 text-center space-y-2">
                <CheckCircle2 className="mx-auto h-8 w-8 text-ink-secondary/60" strokeWidth={1.5} />
                <h3 className="text-sm font-medium text-ink">No attendance records found</h3>
                <p className="text-xs text-ink-secondary max-w-sm mx-auto">
                    When you check into sessions on-site, your verified attendance records will appear here.
                </p>
            </div>
        );
    }

    return (
        <div className="space-y-8">
            {/* Accreditation explanatory notice */}
            <div className="rounded-xl border border-blue-200 bg-blue-50/50 p-4 dark:border-blue-900/40 dark:bg-blue-950/20 text-xs text-blue-900 dark:text-blue-300 flex items-start gap-2.5">
                <Info className="h-4 w-4 text-blue-600 dark:text-blue-400 mt-0.5 shrink-0" />
                <p className="leading-relaxed">
                    <strong>Accreditation standard:</strong> Session check-ins record verified presence at individual event sessions. Certified CPD/CME hours are formally evaluated and awarded on accredited certificates. Per professional credentialing standards, session attendance and certified hours are reported separately and are not combined.
                </p>
            </div>

            {/* Sub-Section 1: Sessions Attended */}
            <section aria-labelledby="sessions-attended-heading" className="space-y-4">
                <div className="flex items-center justify-between border-b border-border pb-3">
                    <div className="flex items-center gap-2">
                        <CheckCircle2 className="h-5 w-5 text-emerald-600 dark:text-emerald-400" />
                        <h2 id="sessions-attended-heading" className="text-base font-medium text-ink">
                            Sessions Attended
                        </h2>
                        <span className="rounded-full bg-surface-subtle border border-border px-2 py-0.5 text-xs font-semibold text-ink-secondary">
                            {attendance.length}
                        </span>
                    </div>
                    <span className="text-xs text-ink-secondary hidden sm:inline">
                        Verified on-site check-ins
                    </span>
                </div>

                {hasSessions ? (
                    <div className="grid grid-cols-1 gap-3">
                        {attendance.map((item) => (
                            <div
                                key={item.id}
                                className="rounded-xl border border-border bg-surface p-4 shadow-xs hover:border-border/80 transition-colors space-y-2"
                            >
                                <div className="flex flex-col sm:flex-row sm:items-start justify-between gap-2">
                                    <div className="space-y-1 min-w-0 flex-1">
                                        <h3 className="text-sm font-medium text-ink leading-snug">
                                            {item.session_title || 'General Session'}
                                        </h3>
                                        <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-ink-secondary">
                                            {item.event_name && (
                                                <span>{item.event_name}</span>
                                            )}
                                            {item.organiser_name && (
                                                <span className="inline-flex items-center gap-1">
                                                    <Building2 className="h-3 w-3" />
                                                    {item.organiser_name}
                                                </span>
                                            )}
                                        </div>
                                    </div>

                                    <div className="text-left sm:text-right shrink-0 space-y-0.5 pt-1 sm:pt-0">
                                        <div className="inline-flex items-center gap-1 text-xs font-medium text-emerald-700 dark:text-emerald-400">
                                            <Clock className="h-3.5 w-3.5" />
                                            <span>Checked in: {formatDateTime(item.checked_in_at)}</span>
                                        </div>
                                        {item.checked_out_at && (
                                            <div className="text-xs text-ink-secondary">
                                                Checked out: {formatDateTime(item.checked_out_at)}
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                ) : (
                    <div className="rounded-xl border border-border bg-surface-subtle p-6 text-center text-xs text-ink-secondary">
                        No session check-ins recorded for this account.
                    </div>
                )}
            </section>

            {/* Sub-Section 2: Formally Certified Hours */}
            <section aria-labelledby="certified-hours-heading" className="space-y-4">
                <div className="flex items-center justify-between border-b border-border pb-3">
                    <div className="flex items-center gap-2">
                        <Award className="h-5 w-5 text-accent" />
                        <h2 id="certified-hours-heading" className="text-base font-medium text-ink">
                            Certified Hours (Accredited Credentials)
                        </h2>
                        <span className="rounded-full bg-surface-subtle border border-border px-2 py-0.5 text-xs font-semibold text-ink-secondary">
                            {certifiedHoursRecords.length}
                        </span>
                    </div>
                    <span className="text-xs text-ink-secondary hidden sm:inline">
                        Formally verified CPD/CME credentials
                    </span>
                </div>

                {hasCertifiedHours ? (
                    <div className="grid grid-cols-1 gap-3">
                        {certifiedHoursRecords.map((cert) => (
                            <div
                                key={cert.uuid || cert.id}
                                className="rounded-xl border border-border bg-surface p-4 shadow-xs hover:border-border/80 transition-colors space-y-3"
                            >
                                <div className="flex flex-col sm:flex-row sm:items-start justify-between gap-3">
                                    <div className="space-y-1 min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="inline-flex items-center gap-1 rounded-md border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">
                                                <Sparkles className="h-3 w-3" />
                                                <span>{cert.cpd_hours} Certified CPD hours</span>
                                            </span>
                                            <span className="inline-flex items-center rounded-md bg-accent/10 px-2 py-0.5 text-xs font-medium text-accent">
                                                {cert.role || 'Attendee'}
                                            </span>
                                        </div>

                                        <h3 className="text-sm font-medium text-ink pt-1 leading-snug">
                                            {cert.event_name || 'Event Certificate'}
                                        </h3>

                                        <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-ink-secondary">
                                            {cert.organiser_name && (
                                                <span className="inline-flex items-center gap-1">
                                                    <Building2 className="h-3 w-3" />
                                                    {cert.organiser_name}
                                                </span>
                                            )}
                                            {cert.issued_at && (
                                                <span className="inline-flex items-center gap-1">
                                                    <Calendar className="h-3 w-3" />
                                                    Issued {formatDate(cert.issued_at)}
                                                </span>
                                            )}
                                        </div>
                                    </div>

                                    {cert.verification_url && (
                                        <div className="shrink-0 pt-1 sm:pt-0">
                                            <a
                                                href={cert.verification_url}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="inline-flex items-center gap-1 text-xs font-medium text-accent hover:underline"
                                            >
                                                <span>View certificate</span>
                                                <ExternalLink className="h-3 w-3" />
                                            </a>
                                        </div>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>
                ) : (
                    <div className="rounded-xl border border-border bg-surface-subtle p-6 text-center text-xs text-ink-secondary">
                        No certificates with accredited CPD/CME hours have been issued yet.
                    </div>
                )}
            </section>
        </div>
    );
}
