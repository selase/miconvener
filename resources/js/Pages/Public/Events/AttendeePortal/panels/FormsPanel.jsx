import { useState, useEffect } from 'react';
import {
    FileText,
    CheckCircle2,
    Lock,
    AlertCircle,
    Loader2,
    ArrowLeft,
    Send,
    Check,
} from 'lucide-react';
import csrfFetch from '@/lib/csrfFetch';

export default function FormsPanel({ registration, isOnline = true }) {
    const [loading, setLoading] = useState(true);
    const [forms, setForms] = useState([]);
    const [selectedFormId, setSelectedFormId] = useState(null);
    const [activeFormDetails, setActiveFormDetails] = useState(null);
    const [activeFormLoading, setActiveFormLoading] = useState(false);
    const [answers, setAnswers] = useState({});
    const [submitting, setSubmitting] = useState(false);
    const [formErrors, setFormErrors] = useState({});
    const [generalError, setGeneralError] = useState(null);
    const [successMessage, setSuccessMessage] = useState(null);

    const fetchForms = async () => {
        try {
            setLoading(true);
            setGeneralError(null);
            const res = await fetch(`/my/events/${registration.id}/forms`, {
                headers: { Accept: 'application/json' },
            });
            if (res.ok) {
                const data = await res.json();
                setForms(data.forms || []);
            } else {
                setGeneralError('Failed to load forms and surveys.');
            }
        } catch {
            setGeneralError('Network error while loading forms.');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        if (isOnline) {
            fetchForms();
        }
    }, [registration.id, isOnline]);

    const openForm = async (formId) => {
        setSelectedFormId(formId);
        setActiveFormLoading(true);
        setFormErrors({});
        setGeneralError(null);
        setSuccessMessage(null);

        try {
            const res = await fetch(`/my/events/${registration.id}/forms/${formId}`, {
                headers: { Accept: 'application/json' },
            });
            if (res.ok) {
                const data = await res.json();
                setActiveFormDetails(data);
                if (data.submission?.answers) {
                    setAnswers(data.submission.answers);
                } else {
                    setAnswers({});
                }
            } else {
                setGeneralError('Could not load form schema.');
            }
        } catch {
            setGeneralError('Network error loading form details.');
        } finally {
            setActiveFormLoading(false);
        }
    };

    const handleAnswerChange = (fieldKey, value) => {
        setAnswers((prev) => ({
            ...prev,
            [fieldKey]: value,
        }));
        if (formErrors[`answers.${fieldKey}`]) {
            setFormErrors((prev) => {
                const next = { ...prev };
                delete next[`answers.${fieldKey}`];
                return next;
            });
        }
    };

    const handleSubmit = async (e) => {
        e.preventDefault();
        if (!selectedFormId) return;

        setSubmitting(true);
        setFormErrors({});
        setGeneralError(null);
        setSuccessMessage(null);

        try {
            const res = await csrfFetch(`/my/events/${registration.id}/forms/${selectedFormId}`, {
                method: 'POST',
                body: JSON.stringify({ answers }),
            });

            const data = await res.json();

            if (!res.ok) {
                if (res.status === 422 && data.errors) {
                    setFormErrors(data.errors);
                } else {
                    setGeneralError(data.message || 'Failed to submit form.');
                }
                setSubmitting(false);
                return;
            }

            setSuccessMessage(data.message || 'Form submitted successfully!');
            // Refresh list and reload details
            await fetchForms();
            await openForm(selectedFormId);
        } catch {
            setGeneralError('Network error submitting form.');
        } finally {
            setSubmitting(false);
        }
    };

    if (!isOnline) {
        return (
            <div className="rounded-xl border border-border bg-surface p-6 text-center text-ink-secondary text-sm">
                <AlertCircle className="mx-auto mb-2 h-6 w-6 text-amber-500" />
                <p>Internet connection required to access event forms and evaluations.</p>
            </div>
        );
    }

    if (loading) {
        return (
            <div className="flex flex-col items-center justify-center p-12 text-ink-secondary">
                <Loader2 className="h-7 w-7 animate-spin text-accent" />
                <span className="mt-3 text-sm">Loading surveys & evaluations…</span>
            </div>
        );
    }

    // Detail / Submission view
    if (selectedFormId && activeFormDetails) {
        const { form, schema = [], submission } = activeFormDetails;
        const isSubmitted = Boolean(submission);
        const canSubmit = form.is_eligible && !isSubmitted;

        return (
            <div className="rounded-xl border border-border bg-surface p-6 text-left shadow-sm space-y-6">
                <button
                    type="button"
                    onClick={() => {
                        setSelectedFormId(null);
                        setActiveFormDetails(null);
                    }}
                    className="inline-flex items-center gap-1.5 text-xs font-medium text-ink-secondary hover:text-ink min-h-[44px] cursor-pointer"
                >
                    <ArrowLeft className="h-3.5 w-3.5" />
                    Back to all forms
                </button>

                <div>
                    <h2 className="text-lg font-semibold tracking-tight text-ink">{form.title}</h2>
                    {form.description && (
                        <p className="mt-1 text-xs text-ink-secondary">{form.description}</p>
                    )}
                </div>

                {successMessage && (
                    <div className="rounded-lg bg-emerald-50 p-4 text-xs text-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200 flex items-center gap-2">
                        <CheckCircle2 className="h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                        <span>{successMessage}</span>
                    </div>
                )}

                {isSubmitted && !successMessage && (
                    <div className="rounded-lg bg-surface-subtle border border-border p-4 text-xs text-ink flex items-center justify-between">
                        <div className="flex items-center gap-2">
                            <CheckCircle2 className="h-4 w-4 text-emerald-600" />
                            <span>
                                Submitted on{' '}
                                {new Date(submission.submitted_at).toLocaleDateString(undefined, {
                                    month: 'short',
                                    day: 'numeric',
                                    year: 'numeric',
                                    hour: 'numeric',
                                    minute: '2-digit',
                                })}
                            </span>
                        </div>
                        <span className="text-[11px] text-ink-secondary">Response recorded</span>
                    </div>
                )}

                {!form.is_eligible && (
                    <div className="rounded-lg bg-amber-50 p-4 text-xs text-amber-900 dark:bg-amber-950/40 dark:text-amber-200 flex items-center gap-2">
                        <Lock className="h-4 w-4 shrink-0 text-amber-600" />
                        <span>
                            This form requires in-person event check-in before it can be submitted.
                        </span>
                    </div>
                )}

                {generalError && (
                    <div className="rounded-lg bg-red-50 p-3 text-xs text-red-800 dark:bg-red-950/40 dark:text-red-200">
                        {generalError}
                    </div>
                )}

                {activeFormLoading ? (
                    <div className="flex justify-center p-8">
                        <Loader2 className="h-6 w-6 animate-spin text-accent" />
                    </div>
                ) : (
                    <form onSubmit={handleSubmit} className="space-y-5">
                        {schema.map((field) => {
                            const errorMsg = formErrors[`answers.${field.key}`]?.[0];
                            const currentValue = answers[field.key] ?? '';

                            return (
                                <div key={field.key} className="space-y-1.5">
                                    <label className="block text-xs font-semibold text-ink">
                                        {field.label || field.key}{' '}
                                        {field.required && <span className="text-red-500">*</span>}
                                    </label>

                                    {/* Text Input */}
                                    {(!field.type || field.type === 'text') && (
                                        <input
                                            type="text"
                                            disabled={!canSubmit}
                                            value={currentValue}
                                            onChange={(e) =>
                                                handleAnswerChange(field.key, e.target.value)
                                            }
                                            className="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:border-accent focus:outline-none disabled:bg-surface-subtle"
                                        />
                                    )}

                                    {/* Textarea */}
                                    {field.type === 'textarea' && (
                                        <textarea
                                            rows={3}
                                            disabled={!canSubmit}
                                            value={currentValue}
                                            onChange={(e) =>
                                                handleAnswerChange(field.key, e.target.value)
                                            }
                                            className="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:border-accent focus:outline-none disabled:bg-surface-subtle"
                                        />
                                    )}

                                    {/* Number Input */}
                                    {field.type === 'number' && (
                                        <input
                                            type="number"
                                            disabled={!canSubmit}
                                            value={currentValue}
                                            onChange={(e) =>
                                                handleAnswerChange(field.key, e.target.value)
                                            }
                                            className="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:border-accent focus:outline-none disabled:bg-surface-subtle"
                                        />
                                    )}

                                    {/* Select */}
                                    {field.type === 'select' && (
                                        <select
                                            disabled={!canSubmit}
                                            value={currentValue}
                                            onChange={(e) =>
                                                handleAnswerChange(field.key, e.target.value)
                                            }
                                            className="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:border-accent focus:outline-none disabled:bg-surface-subtle"
                                        >
                                            <option value="">Select an option</option>
                                            {(field.options || []).map((opt) => {
                                                const optVal =
                                                    typeof opt === 'object' ? opt.value : opt;
                                                const optLabel =
                                                    typeof opt === 'object' ? opt.label : opt;
                                                return (
                                                    <option key={optVal} value={optVal}>
                                                        {optLabel}
                                                    </option>
                                                );
                                            })}
                                        </select>
                                    )}

                                    {/* Radio Group */}
                                    {field.type === 'radio' && (
                                        <div className="space-y-2 pt-1">
                                            {(field.options || []).map((opt) => {
                                                const optVal =
                                                    typeof opt === 'object' ? opt.value : opt;
                                                const optLabel =
                                                    typeof opt === 'object' ? opt.label : opt;
                                                const isChecked = currentValue === optVal;
                                                return (
                                                    <label
                                                        key={optVal}
                                                        className={`flex items-center gap-2.5 rounded-lg border p-3 text-xs min-h-[44px] cursor-pointer ${
                                                            isChecked
                                                                ? 'border-accent bg-accent-soft/20 text-ink font-medium'
                                                                : 'border-border text-ink-secondary'
                                                        }`}
                                                    >
                                                        <input
                                                            type="radio"
                                                            name={field.key}
                                                            disabled={!canSubmit}
                                                            checked={isChecked}
                                                            onChange={() =>
                                                                handleAnswerChange(
                                                                    field.key,
                                                                    optVal
                                                                )
                                                            }
                                                            className="h-4 w-4 border-border text-accent focus:ring-accent"
                                                        />
                                                        <span>{optLabel}</span>
                                                    </label>
                                                );
                                            })}
                                        </div>
                                    )}

                                    {errorMsg && (
                                        <p className="text-[11px] text-red-600">{errorMsg}</p>
                                    )}
                                </div>
                            );
                        })}

                        {canSubmit && (
                            <button
                                type="submit"
                                disabled={submitting}
                                className="inline-flex min-h-[44px] items-center justify-center gap-1.5 rounded-lg bg-accent px-6 text-xs font-semibold text-white hover:opacity-90 disabled:opacity-50 cursor-pointer w-full sm:w-auto"
                            >
                                {submitting ? (
                                    <>
                                        <Loader2 className="h-4 w-4 animate-spin" />
                                        Submitting…
                                    </>
                                ) : (
                                    <>
                                        <Send className="h-3.5 w-3.5" />
                                        Submit evaluation
                                    </>
                                )}
                            </button>
                        )}
                    </form>
                )}
            </div>
        );
    }

    return (
        <div className="space-y-6 text-left">
            <div>
                <h2 className="text-lg font-semibold tracking-tight text-ink">
                    Surveys & Evaluations
                </h2>
                <p className="text-xs text-ink-secondary">
                    Provide feedback, complete CME evaluations, or sign up for workshops.
                </p>
            </div>

            {generalError && (
                <div className="rounded-lg bg-red-50 p-3 text-xs text-red-800 dark:bg-red-950/40 dark:text-red-200">
                    {generalError}
                </div>
            )}

            {forms.length === 0 ? (
                <div className="rounded-xl border border-border bg-surface p-8 text-center">
                    <FileText className="mx-auto h-8 w-8 text-ink-muted" />
                    <h3 className="mt-3 text-base font-medium text-ink">No forms available</h3>
                    <p className="mt-1.5 text-xs text-ink-secondary">
                        There are currently no active surveys or evaluations for this event.
                    </p>
                </div>
            ) : (
                <div className="space-y-3">
                    {forms.map((item) => (
                        <div
                            key={item.id}
                            className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 rounded-xl border border-border bg-surface p-5 shadow-sm"
                        >
                            <div className="space-y-1">
                                <div className="flex items-center gap-2">
                                    <h3 className="text-sm font-semibold text-ink">{item.title}</h3>
                                    {item.has_submitted ? (
                                        <span className="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
                                            <Check className="h-3 w-3" /> Submitted
                                        </span>
                                    ) : !item.is_eligible ? (
                                        <span className="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-800 dark:bg-amber-950 dark:text-amber-300">
                                            <Lock className="h-3 w-3" /> Check-in required
                                        </span>
                                    ) : null}
                                </div>
                                {item.description && (
                                    <p className="text-xs text-ink-secondary line-clamp-2">
                                        {item.description}
                                    </p>
                                )}
                            </div>

                            <button
                                type="button"
                                onClick={() => openForm(item.id)}
                                className={`inline-flex min-h-[44px] items-center justify-center rounded-lg px-4 py-2 text-xs font-semibold cursor-pointer shrink-0 ${
                                    item.has_submitted
                                        ? 'border border-border bg-surface text-ink hover:border-accent'
                                        : item.is_eligible
                                          ? 'bg-accent text-white hover:opacity-90'
                                          : 'border border-border bg-surface-subtle text-ink-secondary cursor-not-allowed opacity-75'
                                }`}
                            >
                                {item.has_submitted
                                    ? 'View Response'
                                    : item.is_eligible
                                      ? 'Complete Form'
                                      : 'Locked'}
                            </button>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}
