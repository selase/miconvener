import { useState } from 'react';
import { Head } from '@inertiajs/react';
import { 
    CheckCircle2, 
    Calendar, 
    FileText, 
    AlertCircle, 
    Star, 
    Send, 
    Clock, 
    ShieldCheck, 
    Sparkles, 
    ArrowRight 
} from 'lucide-react';

export default function Show({ event, form }) {
    // Read any pre-filled query params
    const searchParams = typeof window !== 'undefined' ? new URLSearchParams(window.location.search) : new URLSearchParams();
    const initialTicket = searchParams.get('ticket') || searchParams.get('ticket_code') || '';
    const initialEmail = searchParams.get('email') || '';

    const [respondentName, setRespondentName] = useState('');
    const [respondentEmail, setRespondentEmail] = useState(initialEmail);
    const [ticketCode, setTicketCode] = useState(initialTicket);
    const [answers, setAnswers] = useState({});
    const [errors, setErrors] = useState({});
    const [submitting, setSubmitting] = useState(false);
    const [submitted, setSubmitted] = useState(false);
    const [serverMessage, setServerMessage] = useState('');

    const schema = form.schema || [];

    const handleAnswerChange = (key, value) => {
        setAnswers(prev => ({
            ...prev,
            [key]: value
        }));
        if (errors[`answers.${key}`]) {
            setErrors(prev => {
                const next = { ...prev };
                delete next[`answers.${key}`];
                return next;
            });
        }
    };

    const handleMultiSelectToggle = (key, option) => {
        const current = answers[key] || [];
        const next = current.includes(option)
            ? current.filter(item => item !== option)
            : [...current, option];
        handleAnswerChange(key, next);
    };

    const handleSubmit = async (e) => {
        e.preventDefault();
        setSubmitting(true);
        setErrors({});
        setServerMessage('');

        // Basic client-side validation
        const localErrors = {};
        schema.forEach(field => {
            if (field.required) {
                const val = answers[field.key];
                if (val === undefined || val === null || val === '' || (Array.isArray(val) && val.length === 0)) {
                    localErrors[`answers.${field.key}`] = [`${field.label || field.key} is required.`];
                }
            }
        });

        if (form.requires_check_in && !ticketCode && !respondentEmail) {
            localErrors.ticket_code = ['Please provide your ticket code or registration email to verify attendee check-in.'];
        }

        if (Object.keys(localErrors).length > 0) {
            setErrors(localErrors);
            setSubmitting(false);
            window.scrollTo({ top: 0, behavior: 'smooth' });
            return;
        }

        try {
            const submitUrl = route('public.events.forms.submit', {
                event: event.slug || event.id,
                form: form.slug || form.id,
            });

            const response = await fetch(submitUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
                body: JSON.stringify({
                    respondent_name: respondentName,
                    respondent_email: respondentEmail,
                    ticket_code: ticketCode,
                    answers,
                }),
            });

            const data = await response.json();

            if (!response.ok) {
                if (data.errors) {
                    setErrors(data.errors);
                }
                setServerMessage(data.message || 'Submission failed. Please check the fields and try again.');
                window.scrollTo({ top: 0, behavior: 'smooth' });
            } else {
                setSubmitted(true);
                setServerMessage(data.message || 'Thank you! Your response has been recorded.');
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        } catch (err) {
            setServerMessage('A network error occurred. Please try again.');
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <div className="min-h-screen bg-slate-50 dark:bg-slate-950 font-sans text-slate-900 dark:text-slate-100 flex flex-col justify-between antialiased">
            <Head title={`${form.title} — ${event.name}`} />

            {/* Header / Brand */}
            <header className="border-b border-slate-200 dark:border-slate-800 bg-white/80 dark:bg-slate-900/80 backdrop-blur-md sticky top-0 z-20">
                <div className="max-w-3xl mx-auto px-4 py-3.5 flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <div className="w-8 h-8 rounded-lg bg-indigo-600 flex items-center justify-center text-white font-black text-sm shadow-sm">
                            MC
                        </div>
                        <div>
                            <span className="font-bold text-sm tracking-tight block text-slate-900 dark:text-white">
                                {event.name}
                            </span>
                            <span className="text-[11px] text-slate-500 dark:text-slate-400">
                                Official Feedback & Survey Portal
                            </span>
                        </div>
                    </div>
                    {event.starts_at && (
                        <div className="hidden sm:flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                            <Calendar className="w-3.5 h-3.5" />
                            {event.starts_at}
                        </div>
                    )}
                </div>
            </header>

            {/* Main Form Body */}
            <main className="max-w-3xl w-full mx-auto px-4 py-8 flex-1">
                {/* Form Hero Card */}
                <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm p-6 sm:p-8 mb-6 relative overflow-hidden">
                    <div className="absolute top-0 right-0 w-48 h-48 bg-indigo-50 dark:bg-indigo-950/40 rounded-full blur-3xl pointer-events-none -mr-12 -mt-12" />
                    
                    <div className="flex flex-wrap items-center gap-2 mb-3">
                        <span className="px-2.5 py-1 text-xs font-semibold rounded-md bg-indigo-50 dark:bg-indigo-950/60 text-indigo-700 dark:text-indigo-400 border border-indigo-200/50 dark:border-indigo-800/50 uppercase tracking-wider">
                            {form.type.replace('_', ' ')}
                        </span>
                        {form.requires_check_in && (
                            <span className="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold rounded-md bg-amber-50 dark:bg-amber-950/60 text-amber-700 dark:text-amber-400 border border-amber-200/50 dark:border-amber-800/50">
                                <ShieldCheck className="w-3.5 h-3.5" />
                                Checked-in Attendees Only
                            </span>
                        )}
                        {!form.is_open && (
                            <span className="px-2.5 py-1 text-xs font-semibold rounded-md bg-red-50 dark:bg-red-950/60 text-red-700 dark:text-red-400 border border-red-200/50 dark:border-red-800/50">
                                Closed
                            </span>
                        )}
                    </div>

                    <h1 className="text-2xl sm:text-3xl font-extrabold tracking-tight text-slate-900 dark:text-white">
                        {form.title}
                    </h1>

                    {form.description && (
                        <p className="mt-3 text-sm sm:text-base text-slate-600 dark:text-slate-300 leading-relaxed whitespace-pre-line">
                            {form.description}
                        </p>
                    )}
                </div>

                {/* Submitted State */}
                {submitted ? (
                    <div className="bg-white dark:bg-slate-900 rounded-2xl border border-emerald-200 dark:border-emerald-800/60 shadow-lg p-8 sm:p-12 text-center space-y-4">
                        <div className="w-16 h-16 bg-emerald-100 dark:bg-emerald-950/80 rounded-full flex items-center justify-center mx-auto text-emerald-600 dark:text-emerald-400 shadow-inner">
                            <CheckCircle2 className="w-10 h-10" />
                        </div>
                        <h2 className="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                            Response Received!
                        </h2>
                        <p className="text-sm text-slate-600 dark:text-slate-300 max-w-md mx-auto">
                            {serverMessage || 'Thank you for taking the time to share your feedback. Your submission has been securely recorded in the conference database.'}
                        </p>
                        <div className="pt-4">
                            <a
                                href={route('public.events.show', { event: event.slug || event.id })}
                                className="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-100 text-white text-sm font-semibold transition-all shadow-sm"
                            >
                                Back to Conference Page
                                <ArrowRight className="w-4 h-4" />
                            </a>
                        </div>
                    </div>
                ) : !form.is_open ? (
                    <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8 text-center space-y-3">
                        <AlertCircle className="w-10 h-10 text-amber-500 mx-auto" />
                        <h2 className="text-xl font-bold text-slate-900 dark:text-white">
                            Form Submissions Closed
                        </h2>
                        <p className="text-sm text-slate-600 dark:text-slate-400 max-w-md mx-auto">
                            This evaluation or survey has concluded and is no longer accepting new responses.
                        </p>
                    </div>
                ) : (
                    <form onSubmit={handleSubmit} className="space-y-6">
                        {/* Server Error Alert */}
                        {serverMessage && (
                            <div className="p-4 rounded-xl bg-red-50 dark:bg-red-950/60 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 text-sm flex items-start gap-3">
                                <AlertCircle className="w-5 h-5 shrink-0 mt-0.5" />
                                <div>
                                    <div className="font-semibold">Unable to submit response</div>
                                    <div className="text-xs mt-0.5">{serverMessage}</div>
                                </div>
                            </div>
                        )}

                        {/* Respondent Identification Section */}
                        <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm p-6 sm:p-8 space-y-4">
                            <h3 className="text-sm font-bold text-slate-900 dark:text-white uppercase tracking-wider">
                                Respondent Details
                            </h3>

                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                        Full Name <span className="text-slate-400 font-normal">(optional)</span>
                                    </label>
                                    <input
                                        type="text"
                                        value={respondentName}
                                        onChange={(e) => setRespondentName(e.target.value)}
                                        placeholder="e.g. Dr. Kwame Mensah"
                                        className="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none"
                                    />
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                        Email Address <span className="text-slate-400 font-normal">(optional)</span>
                                    </label>
                                    <input
                                        type="email"
                                        value={respondentEmail}
                                        onChange={(e) => setRespondentEmail(e.target.value)}
                                        placeholder="e.g. kwame@example.com"
                                        className="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none"
                                    />
                                    {errors.respondent_email && (
                                        <p className="text-xs text-red-500 mt-1">{errors.respondent_email[0]}</p>
                                    )}
                                </div>
                            </div>

                            {form.requires_check_in && (
                                <div className="pt-2">
                                    <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                        Ticket Code <span className="text-red-500">*</span>
                                    </label>
                                    <input
                                        type="text"
                                        value={ticketCode}
                                        onChange={(e) => setTicketCode(e.target.value)}
                                        placeholder="e.g. TKT-98A4BC"
                                        className="w-full sm:w-1/2 px-3.5 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none uppercase font-mono"
                                    />
                                    {errors.ticket_code && (
                                        <p className="text-xs text-red-500 mt-1">{errors.ticket_code[0]}</p>
                                    )}
                                    <p className="text-[11px] text-slate-500 dark:text-slate-400 mt-1">
                                        Required to confirm your attendance at this conference for accreditation.
                                    </p>
                                </div>
                            )}
                        </div>

                        {/* Questions Rendering */}
                        <div className="space-y-4">
                            {schema.map((field, idx) => {
                                const fieldError = errors[`answers.${field.key}`];
                                return (
                                    <div
                                        key={field.key || idx}
                                        className={`bg-white dark:bg-slate-900 rounded-2xl border ${
                                            fieldError 
                                                ? 'border-red-300 dark:border-red-800/80 shadow-red-500/5' 
                                                : 'border-slate-200 dark:border-slate-800'
                                        } shadow-sm p-6 sm:p-7 transition-all`}
                                    >
                                        <label className="block text-sm sm:text-base font-bold text-slate-900 dark:text-white mb-1">
                                            {field.label || `Question ${idx + 1}`}
                                            {field.required && (
                                                <span className="text-red-500 ml-1 font-semibold">*</span>
                                            )}
                                        </label>

                                        {field.description && (
                                            <p className="text-xs text-slate-500 dark:text-slate-400 mb-3">
                                                {field.description}
                                            </p>
                                        )}

                                        {/* Field Input Variation */}
                                        <div className="mt-2">
                                            {/* TEXT */}
                                            {field.type === 'text' && (
                                                <input
                                                    type="text"
                                                    value={answers[field.key] || ''}
                                                    onChange={(e) => handleAnswerChange(field.key, e.target.value)}
                                                    placeholder={field.placeholder || 'Your answer...'}
                                                    className="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none"
                                                />
                                            )}

                                            {/* TEXTAREA */}
                                            {field.type === 'textarea' && (
                                                <textarea
                                                    rows={4}
                                                    value={answers[field.key] || ''}
                                                    onChange={(e) => handleAnswerChange(field.key, e.target.value)}
                                                    placeholder={field.placeholder || 'Type detailed response...'}
                                                    className="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none"
                                                />
                                            )}

                                            {/* NUMBER */}
                                            {field.type === 'number' && (
                                                <input
                                                    type="number"
                                                    value={answers[field.key] || ''}
                                                    onChange={(e) => handleAnswerChange(field.key, e.target.value)}
                                                    placeholder="0"
                                                    className="w-full sm:w-1/3 px-3.5 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none"
                                                />
                                            )}

                                            {/* SELECT */}
                                            {field.type === 'select' && (
                                                <select
                                                    value={answers[field.key] || ''}
                                                    onChange={(e) => handleAnswerChange(field.key, e.target.value)}
                                                    className="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none"
                                                >
                                                    <option value="">-- Choose an option --</option>
                                                    {(field.options || []).map((opt, oIdx) => (
                                                        <option key={oIdx} value={opt}>
                                                            {opt}
                                                        </option>
                                                    ))}
                                                </select>
                                            )}

                                            {/* RADIO */}
                                            {field.type === 'radio' && (
                                                <div className="space-y-2">
                                                    {(field.options || []).map((opt, oIdx) => (
                                                        <label
                                                            key={oIdx}
                                                            className={`flex items-center gap-3 p-3 rounded-xl border cursor-pointer transition-all ${
                                                                answers[field.key] === opt
                                                                    ? 'border-indigo-600 bg-indigo-50/50 dark:bg-indigo-950/40 text-indigo-950 dark:text-indigo-200'
                                                                    : 'border-slate-200 dark:border-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800/50'
                                                            }`}
                                                        >
                                                            <input
                                                                type="radio"
                                                                name={field.key}
                                                                value={opt}
                                                                checked={answers[field.key] === opt}
                                                                onChange={() => handleAnswerChange(field.key, opt)}
                                                                className="text-indigo-600 focus:ring-indigo-500"
                                                            />
                                                            <span className="text-sm font-medium">{opt}</span>
                                                        </label>
                                                    ))}
                                                </div>
                                            )}

                                            {/* MULTISELECT */}
                                            {field.type === 'multiselect' && (
                                                <div className="space-y-2">
                                                    {(field.options || []).map((opt, oIdx) => {
                                                        const selected = (answers[field.key] || []).includes(opt);
                                                        return (
                                                            <label
                                                                key={oIdx}
                                                                className={`flex items-center gap-3 p-3 rounded-xl border cursor-pointer transition-all ${
                                                                    selected
                                                                        ? 'border-indigo-600 bg-indigo-50/50 dark:bg-indigo-950/40 text-indigo-950 dark:text-indigo-200'
                                                                        : 'border-slate-200 dark:border-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800/50'
                                                                }`}
                                                            >
                                                                <input
                                                                    type="checkbox"
                                                                    checked={selected}
                                                                    onChange={() => handleMultiSelectToggle(field.key, opt)}
                                                                    className="rounded text-indigo-600 focus:ring-indigo-500"
                                                                />
                                                                <span className="text-sm font-medium">{opt}</span>
                                                            </label>
                                                        );
                                                    })}
                                                </div>
                                            )}

                                            {/* RATING SCALE (1-5) */}
                                            {field.type === 'rating' && (
                                                <div className="flex items-center gap-2 pt-1">
                                                    {[1, 2, 3, 4, 5].map((val) => (
                                                        <button
                                                            key={val}
                                                            type="button"
                                                            onClick={() => handleAnswerChange(field.key, val)}
                                                            className={`p-3 rounded-xl border flex flex-col items-center gap-1 transition-all ${
                                                                (answers[field.key] || 0) >= val
                                                                    ? 'border-amber-400 bg-amber-50 dark:bg-amber-950/40 text-amber-600 dark:text-amber-400 shadow-sm'
                                                                    : 'border-slate-200 dark:border-slate-800 text-slate-400 hover:text-slate-600'
                                                            }`}
                                                        >
                                                            <Star className={`w-6 h-6 ${(answers[field.key] || 0) >= val ? 'fill-amber-400' : ''}`} />
                                                            <span className="text-xs font-bold">{val}</span>
                                                        </button>
                                                    ))}
                                                    <span className="text-xs text-slate-500 dark:text-slate-400 ml-2">
                                                        {answers[field.key] ? `${answers[field.key]} / 5 Stars` : 'Rate from 1 to 5'}
                                                    </span>
                                                </div>
                                            )}

                                            {/* DATE */}
                                            {field.type === 'date' && (
                                                <input
                                                    type="date"
                                                    value={answers[field.key] || ''}
                                                    onChange={(e) => handleAnswerChange(field.key, e.target.value)}
                                                    className="w-full sm:w-1/2 px-3.5 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none"
                                                />
                                            )}

                                            {/* BOOLEAN */}
                                            {field.type === 'boolean' && (
                                                <label className="flex items-center gap-3 p-3 rounded-xl border border-slate-200 dark:border-slate-800 cursor-pointer">
                                                    <input
                                                        type="checkbox"
                                                        checked={Boolean(answers[field.key])}
                                                        onChange={(e) => handleAnswerChange(field.key, e.target.checked)}
                                                        className="rounded text-indigo-600 focus:ring-indigo-500"
                                                    />
                                                    <span className="text-sm font-medium">Yes, I confirm / agree</span>
                                                </label>
                                            )}
                                        </div>

                                        {fieldError && (
                                            <p className="text-xs text-red-500 mt-2 flex items-center gap-1 font-medium">
                                                <AlertCircle className="w-3.5 h-3.5" />
                                                {fieldError[0]}
                                            </p>
                                        )}
                                    </div>
                                );
                            })}
                        </div>

                        {/* Submit Button */}
                        <div className="pt-4 flex justify-end">
                            <button
                                type="submit"
                                disabled={submitting}
                                className="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-8 py-3.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-sm shadow-md hover:shadow-indigo-500/25 transition-all disabled:opacity-50 cursor-pointer"
                            >
                                {submitting ? (
                                    <>
                                        <div className="w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin" />
                                        Submitting Response...
                                    </>
                                ) : (
                                    <>
                                        <Send className="w-4 h-4" />
                                        Submit Form Response
                                    </>
                                )}
                            </button>
                        </div>
                    </form>
                )}
            </main>

            {/* Footer */}
            <footer className="border-t border-slate-200 dark:border-slate-800 py-6 mt-12 bg-white/50 dark:bg-slate-900/50 text-center text-xs text-slate-500 dark:text-slate-400">
                Powered by <span className="font-bold text-slate-700 dark:text-slate-200">MiConvener</span> &bull; Academic & Professional Conference Engine
            </footer>
        </div>
    );
}
