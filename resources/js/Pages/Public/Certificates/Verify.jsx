import { Head } from '@inertiajs/react';
import { 
    CheckCircle2, 
    XCircle, 
    Award, 
    Calendar, 
    Building2, 
    Download, 
    ShieldCheck, 
    Clock, 
    User,
    ExternalLink
} from 'lucide-react';

export default function Verify({ isValid, certificate, event }) {
    return (
        <div className="min-h-screen bg-slate-50 dark:bg-slate-950 flex flex-col justify-between font-sans antialiased text-slate-900 dark:text-slate-100">
            <Head title={isValid ? `Verified: ${certificate.recipient_name} — ${certificate.title}` : 'Certificate Verification'} />

            {/* Top Navigation / Brand */}
            <header className="border-b border-slate-200 dark:border-slate-800 bg-white/80 dark:bg-slate-900/80 backdrop-blur-md sticky top-0 z-10">
                <div className="max-w-4xl mx-auto px-4 py-3.5 flex items-center justify-between">
                    <div className="flex items-center gap-2">
                        <div className="w-8 h-8 rounded-lg bg-indigo-600 flex items-center justify-center text-white font-black text-sm shadow-sm">
                            MC
                        </div>
                        <span className="font-bold text-sm tracking-tight text-slate-900 dark:text-white">
                            MiConvener <span className="text-indigo-600 dark:text-indigo-400 font-normal">Accreditation</span>
                        </span>
                    </div>
                    <div className="flex items-center gap-1.5 text-xs text-emerald-600 dark:text-emerald-400 font-semibold bg-emerald-50 dark:bg-emerald-950/50 px-2.5 py-1 rounded-full border border-emerald-200 dark:border-emerald-800">
                        <ShieldCheck className="w-3.5 h-3.5" />
                        Official Credential Registry
                    </div>
                </div>
            </header>

            {/* Main Content Area */}
            <main className="max-w-2xl w-full mx-auto px-4 py-10 flex-1 flex flex-col justify-center">
                {isValid ? (
                    <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xl overflow-hidden">
                        {/* Status Header */}
                        <div className="bg-gradient-to-r from-emerald-600 to-teal-600 p-6 text-white text-center relative">
                            <div className="w-16 h-16 bg-white/20 backdrop-blur-md rounded-full flex items-center justify-center mx-auto mb-3 shadow-inner">
                                <CheckCircle2 className="w-10 h-10 text-white" />
                            </div>
                            <h1 className="text-xl font-extrabold tracking-tight">
                                Official Credential Verified
                            </h1>
                            <p className="text-xs text-emerald-100 mt-1 font-medium">
                                Institutional Record Authenticated via MiConvener Ledger
                            </p>
                        </div>

                        {/* Certificate Details */}
                        <div className="p-6 sm:p-8 space-y-6">
                            <div className="text-center space-y-1">
                                <span className="text-xs font-bold tracking-widest text-indigo-600 dark:text-indigo-400 uppercase">
                                    {certificate.title}
                                </span>
                                <h2 className="text-2xl sm:text-3xl font-black text-slate-900 dark:text-white">
                                    {certificate.recipient_name}
                                </h2>
                                <p className="text-xs text-slate-500 font-mono">
                                    {certificate.recipient_email_masked}
                                </p>
                            </div>

                            {/* Meta Grid */}
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-4 border-t border-slate-100 dark:border-slate-800 text-xs">
                                <div className="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-100 dark:border-slate-800 space-y-1">
                                    <div className="flex items-center gap-1.5 text-slate-500 font-medium">
                                        <Building2 className="w-3.5 h-3.5 text-indigo-500" />
                                        Conferring Event
                                    </div>
                                    <div className="font-bold text-slate-900 dark:text-white text-sm">
                                        {event.name}
                                    </div>
                                    <div className="text-[11px] text-slate-500">
                                        Organized by {event.tenant_name}
                                    </div>
                                </div>

                                <div className="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-100 dark:border-slate-800 space-y-1">
                                    <div className="flex items-center gap-1.5 text-slate-500 font-medium">
                                        <Award className="w-3.5 h-3.5 text-amber-500" />
                                        Participant Role
                                    </div>
                                    <div className="font-bold text-slate-900 dark:text-white text-sm capitalize">
                                        {certificate.role}
                                    </div>
                                    {certificate.cpd_hours > 0 ? (
                                        <div className="text-[11px] font-semibold text-amber-600 dark:text-amber-400 flex items-center gap-1">
                                            <Clock className="w-3 h-3" />
                                            {certificate.cpd_hours.toFixed(1)} CPD / CME Contact Hours
                                        </div>
                                    ) : (
                                        <div className="text-[11px] text-slate-500">Accredited Participant</div>
                                    )}
                                </div>

                                <div className="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-100 dark:border-slate-800 space-y-1">
                                    <div className="flex items-center gap-1.5 text-slate-500 font-medium">
                                        <Calendar className="w-3.5 h-3.5 text-blue-500" />
                                        Date & Issue
                                    </div>
                                    <div className="font-semibold text-slate-900 dark:text-white">
                                        Issued {certificate.issued_at}
                                    </div>
                                    <div className="text-[11px] text-slate-500">
                                        Event Date: {event.starts_at}
                                    </div>
                                </div>

                                <div className="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-100 dark:border-slate-800 space-y-1">
                                    <div className="flex items-center gap-1.5 text-slate-500 font-medium">
                                        <ShieldCheck className="w-3.5 h-3.5 text-emerald-500" />
                                        Credential Verification Code
                                    </div>
                                    <div className="font-mono font-bold text-slate-900 dark:text-white tracking-wider">
                                        {certificate.verification_code}
                                    </div>
                                    <div className="text-[11px] text-emerald-600 dark:text-emerald-400 font-semibold">
                                        Immutable QR Anchor
                                    </div>
                                </div>
                            </div>

                            {/* Signatory Note */}
                            {certificate.issuer_name && (
                                <div className="text-center pt-2 text-xs text-slate-500">
                                    Conferred under the authority of <span className="font-bold text-slate-800 dark:text-slate-200">{certificate.issuer_name}</span>
                                    {certificate.issuer_title && ` (${certificate.issuer_title})`}.
                                </div>
                            )}

                            {/* Download Action */}
                            <div className="pt-2">
                                <a
                                    href={`/verify/cert/${certificate.uuid}/download`}
                                    className="w-full flex items-center justify-center gap-2 py-3 px-4 rounded-xl font-bold text-sm bg-indigo-600 hover:bg-indigo-700 text-white shadow-md hover:shadow-lg transition"
                                >
                                    <Download className="w-4 h-4" />
                                    Download Official Vector PDF Certificate
                                </a>
                            </div>
                        </div>
                    </div>
                ) : (
                    <div className="bg-white dark:bg-slate-900 rounded-2xl border border-rose-200 dark:border-rose-900/60 p-8 text-center shadow-xl space-y-4">
                        <div className="w-16 h-16 bg-rose-50 dark:bg-rose-950/50 rounded-full flex items-center justify-center mx-auto text-rose-600 dark:text-rose-400">
                            <XCircle className="w-10 h-10" />
                        </div>
                        <h1 className="text-xl font-bold text-slate-900 dark:text-white">
                            Credential Not Found or Revoked
                        </h1>
                        <p className="text-sm text-slate-500 max-w-md mx-auto">
                            The certificate credential ID or QR verification token you scanned could not be validated against the active conference registry.
                        </p>
                        <div className="pt-2">
                            <a
                                href="/"
                                className="inline-flex items-center gap-1.5 text-xs font-semibold text-indigo-600 hover:underline"
                            >
                                Return to MiConvener Home
                            </a>
                        </div>
                    </div>
                )}
            </main>

            {/* Footer */}
            <footer className="border-t border-slate-200 dark:border-slate-800 py-6 text-center text-xs text-slate-400">
                <p>&copy; {new Date().getFullYear()} MiConvener Academic & Conference Operations. All rights reserved.</p>
            </footer>
        </div>
    );
}
