import React, { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import { csrfFetchFormData } from '@/lib/csrfFetch';

export default function AbstractSubmit({ event, org }) {
    const [title, setTitle] = useState('');
    const [track, setTrack] = useState(event.tracks?.[0] || '');
    const [presentationPreference, setPresentationPreference] = useState('either');
    const [structuredAbstract, setStructuredAbstract] = useState({
        background: '',
        methods: '',
        results: '',
        conclusion: '',
    });
    const [keywordsText, setKeywordsText] = useState('');
    const [conflictOfInterest, setConflictOfInterest] = useState('');
    const [file, setFile] = useState(null);

    const [authors, setAuthors] = useState([
        { first_name: '', last_name: '', email: '', affiliation: '', country: '', is_presenting: true, is_corresponding: true }
    ]);

    const [loading, setLoading] = useState(false);
    const [submittedCode, setSubmittedCode] = useState(null);
    const [trackingUrl, setTrackingUrl] = useState(null);
    const [errorMessage, setErrorMessage] = useState(null);

    const handleAuthorChange = (index, field, value) => {
        const next = [...authors];
        next[index][field] = value;
        setAuthors(next);
    };

    const handlePresentingChange = (index) => {
        const next = authors.map((a, i) => ({
            ...a,
            is_presenting: i === index,
        }));
        setAuthors(next);
    };

    const addAuthor = () => {
        setAuthors([
            ...authors,
            { first_name: '', last_name: '', email: '', affiliation: '', country: '', is_presenting: false, is_corresponding: false }
        ]);
    };

    const removeAuthor = (index) => {
        if (authors.length <= 1) return;
        const next = authors.filter((_, i) => i !== index);
        // Ensure at least one author is marked presenting
        if (!next.some(a => a.is_presenting)) {
            next[0].is_presenting = true;
        }
        setAuthors(next);
    };

    const handleSubmit = async (e) => {
        e.preventDefault();
        setLoading(true);
        setErrorMessage(null);

        const formData = new FormData();
        formData.append('title', title);
        if (track) formData.append('track', track);
        formData.append('presentation_preference', presentationPreference);
        formData.append('structured_abstract[background]', structuredAbstract.background);
        formData.append('structured_abstract[methods]', structuredAbstract.methods);
        formData.append('structured_abstract[results]', structuredAbstract.results);
        formData.append('structured_abstract[conclusion]', structuredAbstract.conclusion);

        const keywords = keywordsText.split(',').map(k => k.trim()).filter(Boolean);
        keywords.forEach((kw, idx) => {
            formData.append(`keywords[${idx}]`, kw);
        });

        if (conflictOfInterest) {
            formData.append('conflict_of_interest', conflictOfInterest);
        }

        if (file) {
            formData.append('file', file);
        }

        authors.forEach((author, idx) => {
            formData.append(`authors[${idx}][first_name]`, author.first_name);
            formData.append(`authors[${idx}][last_name]`, author.last_name);
            formData.append(`authors[${idx}][email]`, author.email);
            formData.append(`authors[${idx}][affiliation]`, author.affiliation);
            if (author.country) formData.append(`authors[${idx}][country]`, author.country);
            formData.append(`authors[${idx}][is_presenting]`, author.is_presenting ? '1' : '0');
            formData.append(`authors[${idx}][is_corresponding]`, author.is_corresponding ? '1' : '0');
        });

        try {
            const res = await csrfFetchFormData(route('public.events.abstracts.store', { event: event.slug }), formData);

            const data = await res.json();
            if (res.ok && data.success) {
                setSubmittedCode(data.code);
                setTrackingUrl(data.tracking_url);
            } else {
                setErrorMessage(data.message || 'Failed to submit abstract. Please check all required fields.');
            }
        } catch (err) {
            setErrorMessage('A network error occurred while submitting your abstract.');
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="min-h-screen bg-slate-50 text-slate-900 py-10 px-4 sm:px-6 lg:px-8 font-sans">
            <Head title={`Submit Abstract - ${event.title}`} />

            <div className="max-w-3xl mx-auto space-y-6">
                {/* Header Card */}
                <div className="bg-white rounded-2xl p-6 sm:p-8 shadow-sm border border-slate-200">
                    <div className="flex items-center justify-between gap-4">
                        <Link
                            href={route('public.events.show', { event: event.slug })}
                            className="text-xs font-semibold text-blue-600 hover:text-blue-700 inline-flex items-center gap-1"
                        >
                            &larr; Back to Event Page
                        </Link>
                        <span className="text-xs font-medium text-slate-500">{org?.name}</span>
                    </div>

                    <h1 className="text-2xl sm:text-3xl font-bold text-slate-900 mt-4">
                        Scientific Abstract Submission
                    </h1>
                    <p className="text-sm text-slate-600 mt-1.5 font-medium">
                        {event.title}
                    </p>
                    <div className="mt-3 text-xs text-slate-500 flex flex-wrap gap-4 border-t border-slate-100 pt-3">
                        <span>Official Call for Papers & Peer Review</span>
                        <span>Structured Format (Background, Methods, Results, Conclusion)</span>
                    </div>
                </div>

                {/* Submission Success Screen */}
                {submittedCode ? (
                    <div className="bg-white rounded-2xl p-8 shadow-sm border border-emerald-200 text-center space-y-5">
                        <div className="w-16 h-16 bg-emerald-100 text-emerald-700 rounded-full flex items-center justify-center mx-auto">
                            <svg className="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                            </svg>
                        </div>
                        <div>
                            <h2 className="text-2xl font-bold text-slate-900">Abstract Submitted Successfully!</h2>
                            <p className="text-sm text-slate-600 mt-2">
                                Your submission has entered the peer review queue. Save your tracking code below:
                            </p>
                        </div>

                        <div className="inline-block bg-slate-100 border border-slate-300 rounded-xl px-6 py-3">
                            <div className="text-xs text-slate-500 uppercase tracking-widest font-semibold">Tracking Code</div>
                            <div className="text-2xl font-mono font-extrabold text-blue-700 mt-0.5">{submittedCode}</div>
                        </div>

                        <div className="pt-2 flex flex-col sm:flex-row items-center justify-center gap-3">
                            {trackingUrl && (
                                <a
                                    href={trackingUrl}
                                    className="w-full sm:w-auto px-6 py-2.5 rounded-xl bg-blue-600 text-white font-semibold text-sm hover:bg-blue-700 transition"
                                >
                                    View Status & Timeline
                                </a>
                            )}
                            <Link
                                href={route('public.events.show', { event: event.slug })}
                                className="w-full sm:w-auto px-6 py-2.5 rounded-xl border border-slate-300 text-slate-700 font-semibold text-sm hover:bg-slate-50 transition"
                            >
                                Return to Conference
                            </Link>
                        </div>
                    </div>
                ) : (
                    /* Submission Form */
                    <form onSubmit={handleSubmit} className="bg-white rounded-2xl p-6 sm:p-8 shadow-sm border border-slate-200 space-y-8">
                        {errorMessage && (
                            <div className="p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-sm">
                                {errorMessage}
                            </div>
                        )}

                        {/* Title & Metadata */}
                        <div className="space-y-4">
                            <h3 className="text-base font-bold text-slate-900 border-b border-slate-100 pb-2">
                                1. Title & Presentation Details
                            </h3>

                            <div>
                                <label className="block text-xs font-semibold text-slate-700 mb-1">
                                    Abstract Title <span className="text-rose-500">*</span>
                                </label>
                                <input
                                    type="text"
                                    required
                                    value={title}
                                    onChange={(e) => setTitle(e.target.value)}
                                    placeholder="e.g. Comparative Efficacy of Novel Clinical Protocols..."
                                    className="w-full text-sm rounded-xl border-slate-300 px-3.5 py-2.5 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                />
                            </div>

                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label className="block text-xs font-semibold text-slate-700 mb-1">
                                        Scientific Track / Category
                                    </label>
                                    <input
                                        type="text"
                                        value={track}
                                        onChange={(e) => setTrack(e.target.value)}
                                        placeholder="e.g. Health Informatics, Cardiology, Clinical Skills"
                                        className="w-full text-sm rounded-xl border-slate-300 px-3.5 py-2.5 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                    />
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold text-slate-700 mb-1">
                                        Presentation Preference <span className="text-rose-500">*</span>
                                    </label>
                                    <select
                                        value={presentationPreference}
                                        onChange={(e) => setPresentationPreference(e.target.value)}
                                        className="w-full text-sm rounded-xl border-slate-300 px-3.5 py-2.5 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                    >
                                        <option value="either">Either Oral or Poster (Recommended)</option>
                                        <option value="oral">Oral Presentation Only</option>
                                        <option value="poster">Poster Presentation Only</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        {/* Authors & Affiliations */}
                        <div className="space-y-4">
                            <div className="flex items-center justify-between border-b border-slate-100 pb-2">
                                <h3 className="text-base font-bold text-slate-900">
                                    2. Authors & Affiliations
                                </h3>
                                <button
                                    type="button"
                                    onClick={addAuthor}
                                    className="text-xs font-semibold text-blue-600 hover:text-blue-700"
                                >
                                    + Add Co-Author
                                </button>
                            </div>

                            <div className="space-y-4">
                                {authors.map((author, index) => (
                                    <div key={index} className="p-4 rounded-xl border border-slate-200 bg-slate-50/50 space-y-3">
                                        <div className="flex items-center justify-between">
                                            <span className="text-xs font-bold text-slate-700 uppercase tracking-wider">
                                                Author #{index + 1} {author.is_presenting ? '(Presenting Author)' : ''}
                                            </span>
                                            {authors.length > 1 && (
                                                <button
                                                    type="button"
                                                    onClick={() => removeAuthor(index)}
                                                    className="text-xs text-rose-600 hover:underline"
                                                >
                                                    Remove
                                                </button>
                                            )}
                                        </div>

                                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                            <div>
                                                <label className="block text-[11px] font-semibold text-slate-600 mb-1">First Name *</label>
                                                <input
                                                    type="text"
                                                    required
                                                    value={author.first_name}
                                                    onChange={(e) => handleAuthorChange(index, 'first_name', e.target.value)}
                                                    className="w-full text-xs rounded-lg border-slate-300 p-2"
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-[11px] font-semibold text-slate-600 mb-1">Last Name *</label>
                                                <input
                                                    type="text"
                                                    required
                                                    value={author.last_name}
                                                    onChange={(e) => handleAuthorChange(index, 'last_name', e.target.value)}
                                                    className="w-full text-xs rounded-lg border-slate-300 p-2"
                                                />
                                            </div>
                                        </div>

                                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                            <div>
                                                <label className="block text-[11px] font-semibold text-slate-600 mb-1">Email *</label>
                                                <input
                                                    type="email"
                                                    required
                                                    value={author.email}
                                                    onChange={(e) => handleAuthorChange(index, 'email', e.target.value)}
                                                    className="w-full text-xs rounded-lg border-slate-300 p-2"
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-[11px] font-semibold text-slate-600 mb-1">Affiliation / Institution *</label>
                                                <input
                                                    type="text"
                                                    required
                                                    value={author.affiliation}
                                                    onChange={(e) => handleAuthorChange(index, 'affiliation', e.target.value)}
                                                    placeholder="e.g. Dept of Medicine, University of Ghana"
                                                    className="w-full text-xs rounded-lg border-slate-300 p-2"
                                                />
                                            </div>
                                        </div>

                                        <div className="flex items-center gap-4 pt-1">
                                            <label className="inline-flex items-center gap-2 text-xs text-slate-700 cursor-pointer">
                                                <input
                                                    type="radio"
                                                    name="presenting_author"
                                                    checked={author.is_presenting}
                                                    onChange={() => handlePresentingChange(index)}
                                                    className="text-blue-600 focus:ring-blue-500"
                                                />
                                                Designate as Presenting Author
                                            </label>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>

                        {/* Structured Abstract Body */}
                        <div className="space-y-4">
                            <h3 className="text-base font-bold text-slate-900 border-b border-slate-100 pb-2">
                                3. Structured Abstract Body
                            </h3>

                            <div className="space-y-3">
                                <div>
                                    <label className="block text-xs font-semibold text-slate-700 mb-1">Background & Objectives *</label>
                                    <textarea
                                        required
                                        rows="3"
                                        value={structuredAbstract.background}
                                        onChange={(e) => setStructuredAbstract({ ...structuredAbstract, background: e.target.value })}
                                        placeholder="State the clinical background, research problem, and study aim..."
                                        className="w-full text-xs rounded-xl border-slate-300 p-3 focus:ring-2 focus:ring-blue-500"
                                    />
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold text-slate-700 mb-1">Methods *</label>
                                    <textarea
                                        required
                                        rows="3"
                                        value={structuredAbstract.methods}
                                        onChange={(e) => setStructuredAbstract({ ...structuredAbstract, methods: e.target.value })}
                                        placeholder="Describe the study design, setting, participant criteria, and analytical methods..."
                                        className="w-full text-xs rounded-xl border-slate-300 p-3 focus:ring-2 focus:ring-blue-500"
                                    />
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold text-slate-700 mb-1">Results *</label>
                                    <textarea
                                        required
                                        rows="3"
                                        value={structuredAbstract.results}
                                        onChange={(e) => setStructuredAbstract({ ...structuredAbstract, results: e.target.value })}
                                        placeholder="Summarize key findings, statistical metrics, and outcomes observed..."
                                        className="w-full text-xs rounded-xl border-slate-300 p-3 focus:ring-2 focus:ring-blue-500"
                                    />
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold text-slate-700 mb-1">Conclusion *</label>
                                    <textarea
                                        required
                                        rows="2"
                                        value={structuredAbstract.conclusion}
                                        onChange={(e) => setStructuredAbstract({ ...structuredAbstract, conclusion: e.target.value })}
                                        placeholder="State the conclusions and primary scientific/clinical implications..."
                                        className="w-full text-xs rounded-xl border-slate-300 p-3 focus:ring-2 focus:ring-blue-500"
                                    />
                                </div>
                            </div>
                        </div>

                        {/* Keywords, COI & File Upload */}
                        <div className="space-y-4">
                            <h3 className="text-base font-bold text-slate-900 border-b border-slate-100 pb-2">
                                4. Supplementary Details & Manuscript
                            </h3>

                            <div>
                                <label className="block text-xs font-semibold text-slate-700 mb-1">
                                    Keywords (comma separated)
                                </label>
                                <input
                                    type="text"
                                    value={keywordsText}
                                    onChange={(e) => setKeywordsText(e.target.value)}
                                    placeholder="e.g. Epidemiology, Machine Learning, Clinical Trials"
                                    className="w-full text-sm rounded-xl border-slate-300 px-3.5 py-2.5 focus:ring-2 focus:ring-blue-500"
                                />
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-slate-700 mb-1">
                                    Conflict of Interest Declaration
                                </label>
                                <input
                                    type="text"
                                    value={conflictOfInterest}
                                    onChange={(e) => setConflictOfInterest(e.target.value)}
                                    placeholder="Declare any commercial disclosures or 'None reported'"
                                    className="w-full text-sm rounded-xl border-slate-300 px-3.5 py-2.5 focus:ring-2 focus:ring-blue-500"
                                />
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-slate-700 mb-1">
                                    Upload Full Manuscript / Supplementary PDF (Optional, max 20MB)
                                </label>
                                <input
                                    type="file"
                                    accept=".pdf,.doc,.docx"
                                    onChange={(e) => setFile(e.target.files?.[0] || null)}
                                    className="w-full text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100"
                                />
                            </div>
                        </div>

                        {/* Submit Button */}
                        <div className="pt-4 border-t border-slate-200 flex justify-end">
                            <button
                                type="submit"
                                disabled={loading}
                                className="w-full sm:w-auto px-8 py-3 rounded-xl bg-blue-600 text-white font-bold text-sm hover:bg-blue-700 shadow-md transition disabled:opacity-50"
                            >
                                {loading ? 'Submitting Abstract...' : 'Submit Abstract for Peer Review'}
                            </button>
                        </div>
                    </form>
                )}
            </div>
        </div>
    );
}
