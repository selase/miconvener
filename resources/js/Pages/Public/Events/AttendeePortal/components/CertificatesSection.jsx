import { Award, Download, ExternalLink, ShieldCheck, Sparkles, Building2 } from 'lucide-react';

/**
 * Formats ISO date string to readable format.
 */
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
 * Certificates Section
 * Displays certificates earned across all organisers with separate Verify and Download actions,
 * CPD hours badges when present, and role badges.
 */
export default function CertificatesSection({ certificates = [] }) {
    if (!certificates || certificates.length === 0) {
        return (
            <div className="rounded-xl border border-border bg-surface-subtle p-8 text-center space-y-2">
                <Award className="mx-auto h-8 w-8 text-ink-secondary/60" strokeWidth={1.5} />
                <h3 className="text-sm font-medium text-ink">No certificates found</h3>
                <p className="text-xs text-ink-secondary max-w-sm mx-auto">
                    Certificates issued for your attendance, speaking sessions, or contributions will appear here.
                </p>
            </div>
        );
    }

    return (
        <section aria-labelledby="certificates-heading" className="space-y-4">
            <div className="flex items-center justify-between border-b border-border pb-3">
                <div className="flex items-center gap-2">
                    <Award className="h-5 w-5 text-accent" />
                    <h2 id="certificates-heading" className="text-base font-medium text-ink">
                        Certificates
                    </h2>
                    <span className="rounded-full bg-surface-subtle border border-border px-2 py-0.5 text-xs font-semibold text-ink-secondary">
                        {certificates.length}
                    </span>
                </div>
                <p className="text-xs text-ink-secondary hidden sm:block">
                    Verified credentials across all your registered events
                </p>
            </div>

            <div className="grid grid-cols-1 gap-4">
                {certificates.map((cert) => {
                    const hasCpdHours = Boolean(cert.cpd_hours && Number(cert.cpd_hours) > 0);

                    return (
                        <div
                            key={cert.uuid || cert.id}
                            className="rounded-xl border border-border bg-surface p-5 shadow-xs hover:border-border/80 transition-colors space-y-4"
                        >
                            <div className="flex flex-col sm:flex-row sm:items-start justify-between gap-3">
                                <div className="space-y-1 min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="inline-flex items-center rounded-md bg-accent/10 px-2 py-0.5 text-xs font-medium text-accent">
                                            {cert.role || 'Attendee'}
                                        </span>
                                        {hasCpdHours && (
                                            <span className="inline-flex items-center gap-1 rounded-md border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">
                                                <Sparkles className="h-3 w-3" />
                                                <span>{cert.cpd_hours} CPD hrs</span>
                                            </span>
                                        )}
                                        {cert.issued_at && (
                                            <span className="text-xs text-ink-secondary">
                                                Issued {formatDate(cert.issued_at)}
                                            </span>
                                        )}
                                    </div>

                                    <h3 className="text-base font-medium text-ink pt-1 leading-snug">
                                        {cert.event_name || 'Event Certificate'}
                                    </h3>

                                    <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-ink-secondary">
                                        {cert.organiser_name && (
                                            <span className="inline-flex items-center gap-1">
                                                <Building2 className="h-3 w-3" />
                                                {cert.organiser_name}
                                            </span>
                                        )}
                                        {cert.recipient_name && (
                                            <span>
                                                Awarded to: <strong className="font-medium text-ink">{cert.recipient_name}</strong>
                                            </span>
                                        )}
                                    </div>
                                </div>

                                <div className="flex flex-wrap sm:flex-nowrap items-center gap-2 pt-2 sm:pt-0 shrink-0">
                                    {cert.download_url && (
                                        <a
                                            href={cert.download_url}
                                            download
                                            className="inline-flex items-center gap-1.5 rounded-lg border border-border bg-surface px-3 py-1.5 text-xs font-medium text-ink shadow-2xs hover:bg-surface-subtle min-h-[38px] transition-colors"
                                        >
                                            <Download className="h-3.5 w-3.5" />
                                            <span>Download PDF</span>
                                        </a>
                                    )}

                                    {cert.verification_url && (
                                        <a
                                            href={cert.verification_url}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="inline-flex items-center gap-1.5 rounded-lg border border-accent/30 bg-accent/5 px-3 py-1.5 text-xs font-medium text-accent hover:bg-accent/10 min-h-[38px] transition-colors"
                                            title="Verify certificate authenticity"
                                        >
                                            <ShieldCheck className="h-3.5 w-3.5" />
                                            <span>Verify</span>
                                            <ExternalLink className="h-3 w-3 opacity-70" />
                                        </a>
                                    )}
                                </div>
                            </div>
                        </div>
                    );
                })}
            </div>
        </section>
    );
}
