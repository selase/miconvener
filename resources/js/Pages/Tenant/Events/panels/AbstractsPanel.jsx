import React, { useState, useEffect } from 'react';
import csrfFetch from '@/lib/csrfFetch';

export default function AbstractsPanel({ event }) {
    const [abstracts, setAbstracts] = useState([]);
    const [stats, setStats] = useState({
        total: 0,
        submitted: 0,
        under_review: 0,
        accepted_oral: 0,
        accepted_poster: 0,
        rejected: 0,
    });
    const [tracks, setTracks] = useState([]);
    const [reviewers, setReviewers] = useState([]);
    const [loading, setLoading] = useState(true);
    const [statusFilter, setStatusFilter] = useState('all');
    const [trackFilter, setTrackFilter] = useState('all');
    const [searchQuery, setSearchQuery] = useState('');

    // Active modals/drawers
    const [selectedAbstract, setSelectedAbstract] = useState(null);
    const [assigningFor, setAssigningFor] = useState(null);
    const [selectedReviewerId, setSelectedReviewerId] = useState('');
    const [decisionFor, setDecisionFor] = useState(null);
    const [decisionStatus, setDecisionStatus] = useState('accepted_oral');
    const [decisionNotes, setDecisionNotes] = useState('');
    const [notifyAuthor, setNotifyAuthor] = useState(true);
    const [actionLoading, setActionLoading] = useState(false);
    const [feedbackMessage, setFeedbackMessage] = useState(null);

    const loadData = async () => {
        setLoading(true);
        try {
            const url = new URL(route('tenant.events.abstracts.index', { event: event.id }));
            if (statusFilter !== 'all') url.searchParams.append('status', statusFilter);
            if (trackFilter !== 'all') url.searchParams.append('track', trackFilter);
            if (searchQuery.trim()) url.searchParams.append('search', searchQuery.trim());

            const res = await csrfFetch(url.toString());
            if (res.ok) {
                const data = await res.json();
                setAbstracts(data.abstracts || []);
                setStats(data.stats || {});
                setTracks(data.tracks || []);
                setReviewers(data.reviewers || []);
            }
        } catch (err) {
            console.error('Failed to load abstracts', err);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        loadData();
    }, [statusFilter, trackFilter]);

    const handleSearchSubmit = (e) => {
        e.preventDefault();
        loadData();
    };

    const handleAssignReviewer = async (e) => {
        e.preventDefault();
        if (!selectedReviewerId || !assigningFor) return;
        setActionLoading(true);
        try {
            const res = await csrfFetch(route('tenant.events.abstracts.assign-reviewer', {
                event: event.id,
                abstract: assigningFor.id,
            }), {
                method: 'POST',
                body: JSON.stringify({ reviewer_id: selectedReviewerId }),
            });
            if (res.ok) {
                setFeedbackMessage({ type: 'success', text: 'Reviewer assigned successfully.' });
                setAssigningFor(null);
                setSelectedReviewerId('');
                loadData();
            }
        } catch (err) {
            setFeedbackMessage({ type: 'error', text: 'Failed to assign reviewer.' });
        } finally {
            setActionLoading(false);
        }
    };

    const handleRemoveReviewer = async (abstractId, reviewId) => {
        if (!confirm('Are you sure you want to remove this reviewer assignment?')) return;
        setActionLoading(true);
        try {
            const res = await csrfFetch(route('tenant.events.abstracts.remove-reviewer', {
                event: event.id,
                abstract: abstractId,
                review: reviewId,
            }), { method: 'DELETE' });
            if (res.ok) {
                setFeedbackMessage({ type: 'success', text: 'Reviewer removed.' });
                loadData();
                if (selectedAbstract?.id === abstractId) {
                    setSelectedAbstract(prev => prev ? {
                        ...prev,
                        reviews: prev.reviews.filter(r => r.id !== reviewId)
                    } : null);
                }
            }
        } catch (err) {
            setFeedbackMessage({ type: 'error', text: 'Failed to remove reviewer.' });
        } finally {
            setActionLoading(false);
        }
    };

    const handleRecordDecision = async (e) => {
        e.preventDefault();
        if (!decisionFor) return;
        setActionLoading(true);
        try {
            const res = await csrfFetch(route('tenant.events.abstracts.decision', {
                event: event.id,
                abstract: decisionFor.id,
            }), {
                method: 'POST',
                body: JSON.stringify({
                    status: decisionStatus,
                    decision_notes: decisionNotes,
                    notify_author: notifyAuthor,
                }),
            });
            if (res.ok) {
                setFeedbackMessage({ type: 'success', text: `Decision recorded: ${decisionStatus.replace('_', ' ')}` });
                setDecisionFor(null);
                setDecisionNotes('');
                loadData();
            }
        } catch (err) {
            setFeedbackMessage({ type: 'error', text: 'Failed to record decision.' });
        } finally {
            setActionLoading(false);
        }
    };

    const copySubmitLink = () => {
        const url = `${window.location.origin}/e/${event.slug}/abstracts/submit`;
        navigator.clipboard.writeText(url);
        alert('Public submission link copied to clipboard:\n' + url);
    };

    const downloadAbstractBook = () => {
        window.open(route('tenant.events.reports.abstract-book', { event: event.id }), '_blank');
    };

    return (
        <div className="space-y-6">
            {/* Header & Metrics */}
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h2 className="text-xl font-bold text-ink">Scientific Abstracts & Peer Review</h2>
                    <p className="text-sm text-ink-secondary mt-1">
                        Manage call for papers, reviewer assignment, rubric scoring, and presentation decisions.
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    <button
                        onClick={copySubmitLink}
                        className="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold rounded-md border border-border bg-surface text-ink hover:bg-surface-hover transition-colors"
                        title="Copy public author submission link"
                    >
                        <svg className="w-4 h-4 text-ink-secondary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                        </svg>
                        Copy Submission URL
                    </button>
                    <button
                        onClick={downloadAbstractBook}
                        className="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold rounded-md bg-accent text-white hover:bg-accent-hover shadow-sm transition-colors"
                    >
                        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        Download Abstract Book (PDF)
                    </button>
                </div>
            </div>

            {feedbackMessage && (
                <div className={`p-4 rounded-md text-sm ${feedbackMessage.type === 'success' ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' : 'bg-rose-50 text-rose-800 border border-rose-200'}`}>
                    {feedbackMessage.text}
                </div>
            )}

            {/* Metrics cards */}
            <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                <div className="rounded-lg border border-border bg-surface p-3 text-center">
                    <div className="text-xs font-medium text-ink-secondary uppercase tracking-wider">Total</div>
                    <div className="mt-1 text-2xl font-bold text-ink">{stats.total || 0}</div>
                </div>
                <div className="rounded-lg border border-border bg-surface p-3 text-center">
                    <div className="text-xs font-medium text-ink-secondary uppercase tracking-wider">Submitted</div>
                    <div className="mt-1 text-2xl font-bold text-blue-600">{stats.submitted || 0}</div>
                </div>
                <div className="rounded-lg border border-border bg-surface p-3 text-center">
                    <div className="text-xs font-medium text-ink-secondary uppercase tracking-wider">Under Review</div>
                    <div className="mt-1 text-2xl font-bold text-amber-600">{stats.under_review || 0}</div>
                </div>
                <div className="rounded-lg border border-border bg-surface p-3 text-center">
                    <div className="text-xs font-medium text-ink-secondary uppercase tracking-wider">Accepted (Oral)</div>
                    <div className="mt-1 text-2xl font-bold text-emerald-600">{stats.accepted_oral || 0}</div>
                </div>
                <div className="rounded-lg border border-border bg-surface p-3 text-center">
                    <div className="text-xs font-medium text-ink-secondary uppercase tracking-wider">Accepted (Poster)</div>
                    <div className="mt-1 text-2xl font-bold text-teal-600">{stats.accepted_poster || 0}</div>
                </div>
                <div className="rounded-lg border border-border bg-surface p-3 text-center">
                    <div className="text-xs font-medium text-ink-secondary uppercase tracking-wider">Rejected</div>
                    <div className="mt-1 text-2xl font-bold text-rose-600">{stats.rejected || 0}</div>
                </div>
            </div>

            {/* Filters Bar */}
            <div className="flex flex-wrap items-center justify-between gap-3 bg-surface p-3 rounded-lg border border-border">
                <div className="flex flex-wrap items-center gap-2">
                    <select
                        value={statusFilter}
                        onChange={(e) => setStatusFilter(e.target.value)}
                        className="text-xs rounded-md border-border bg-surface text-ink px-2.5 py-1.5 focus:ring-accent"
                    >
                        <option value="all">All Statuses</option>
                        <option value="submitted">Submitted</option>
                        <option value="under_review">Under Review</option>
                        <option value="accepted_oral">Accepted (Oral)</option>
                        <option value="accepted_poster">Accepted (Poster)</option>
                        <option value="rejected">Rejected</option>
                    </select>

                    {tracks.length > 0 && (
                        <select
                            value={trackFilter}
                            onChange={(e) => setTrackFilter(e.target.value)}
                            className="text-xs rounded-md border-border bg-surface text-ink px-2.5 py-1.5 focus:ring-accent"
                        >
                            <option value="all">All Tracks</option>
                            {tracks.map(t => (
                                <option key={t} value={t}>{t}</option>
                            ))}
                        </select>
                    )}
                </div>

                <form onSubmit={handleSearchSubmit} className="flex items-center gap-2">
                    <input
                        type="text"
                        placeholder="Search code, title, author..."
                        value={searchQuery}
                        onChange={(e) => setSearchQuery(e.target.value)}
                        className="text-xs rounded-md border-border bg-surface text-ink px-3 py-1.5 w-48 sm:w-64 focus:ring-accent"
                    />
                    <button
                        type="submit"
                        className="text-xs px-3 py-1.5 rounded-md border border-border bg-surface text-ink hover:bg-surface-hover"
                    >
                        Search
                    </button>
                </form>
            </div>

            {/* Abstracts Table */}
            <div className="border border-border rounded-lg overflow-hidden bg-surface">
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-xs text-ink divide-y divide-border">
                        <thead className="bg-surface-subtle text-ink-secondary uppercase font-semibold">
                            <tr>
                                <th className="px-4 py-3">Code</th>
                                <th className="px-4 py-3">Title & Track</th>
                                <th className="px-4 py-3">Presenting Author</th>
                                <th className="px-4 py-3">Pref</th>
                                <th className="px-4 py-3">Review & Score</th>
                                <th className="px-4 py-3">Status</th>
                                <th className="px-4 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-border">
                            {loading ? (
                                <tr>
                                    <td colSpan="7" className="px-4 py-8 text-center text-ink-secondary">
                                        Loading abstracts...
                                    </td>
                                </tr>
                            ) : abstracts.length === 0 ? (
                                <tr>
                                    <td colSpan="7" className="px-4 py-8 text-center text-ink-secondary">
                                        No abstracts found matching current criteria.
                                    </td>
                                </tr>
                            ) : (
                                abstracts.map((item) => {
                                    const presentingAuthor = item.authors.find(a => a.is_presenting) || item.authors[0];
                                    const completedReviews = item.reviews.filter(r => r.status === 'completed');

                                    return (
                                        <tr key={item.id} className="hover:bg-surface-hover transition-colors">
                                            <td className="px-4 py-3 font-mono font-bold text-accent whitespace-nowrap">
                                                {item.code}
                                            </td>
                                            <td className="px-4 py-3 max-w-xs sm:max-w-md">
                                                <div className="font-semibold text-ink line-clamp-1">{item.title}</div>
                                                <div className="text-ink-secondary text-[11px] mt-0.5">
                                                    {item.track ? <span className="inline-block px-1.5 py-0.5 rounded bg-surface-subtle text-ink-secondary mr-1">{item.track}</span> : null}
                                                    {item.authors.length} author{item.authors.length > 1 ? 's' : ''}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3 whitespace-nowrap">
                                                <div className="font-medium text-ink">{presentingAuthor?.name || 'N/A'}</div>
                                                <div className="text-[11px] text-ink-secondary">{presentingAuthor?.affiliation || ''}</div>
                                            </td>
                                            <td className="px-4 py-3 uppercase text-[11px] font-semibold text-ink-secondary">
                                                {item.presentation_preference}
                                            </td>
                                            <td className="px-4 py-3 whitespace-nowrap">
                                                <div className="flex items-center gap-1.5">
                                                    <span className="text-[11px] text-ink-secondary">
                                                        {completedReviews.length}/{item.reviews.length} reviews
                                                    </span>
                                                    {item.average_score !== null && (
                                                        <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                                            ★ {item.average_score}
                                                        </span>
                                                    )}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3 whitespace-nowrap">
                                                <span className={`inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold ${
                                                    item.status === 'accepted_oral' ? 'bg-emerald-100 text-emerald-800' :
                                                    item.status === 'accepted_poster' ? 'bg-teal-100 text-teal-800' :
                                                    item.status === 'rejected' ? 'bg-rose-100 text-rose-800' :
                                                    item.status === 'under_review' ? 'bg-amber-100 text-amber-800' :
                                                    'bg-blue-100 text-blue-800'
                                                }`}>
                                                    {item.status.replace('_', ' ')}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3 text-right whitespace-nowrap space-x-2">
                                                <button
                                                    onClick={() => setSelectedAbstract(item)}
                                                    className="text-xs text-accent hover:underline font-medium"
                                                >
                                                    View
                                                </button>
                                                <button
                                                    onClick={() => setAssigningFor(item)}
                                                    className="text-xs text-ink-secondary hover:text-ink font-medium"
                                                >
                                                    Reviewers ({item.reviews.length})
                                                </button>
                                                <button
                                                    onClick={() => {
                                                        setDecisionFor(item);
                                                        setDecisionStatus(item.status.startsWith('accepted') ? item.status : 'accepted_oral');
                                                        setDecisionNotes(item.decision_notes || '');
                                                    }}
                                                    className="text-xs font-semibold text-emerald-600 hover:text-emerald-700"
                                                >
                                                    Decide
                                                </button>
                                            </td>
                                        </tr>
                                    );
                                })
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            {/* View Abstract Drawer / Modal */}
            {selectedAbstract && (
                <div className="fixed inset-0 z-50 overflow-y-auto bg-black/50 flex items-center justify-center p-4">
                    <div className="bg-surface rounded-xl border border-border max-w-3xl w-full max-h-[90vh] flex flex-col shadow-2xl">
                        <div className="p-4 border-b border-border flex items-center justify-between">
                            <div>
                                <span className="font-mono font-bold text-accent text-sm mr-2">{selectedAbstract.code}</span>
                                <span className="uppercase text-xs font-semibold px-2 py-0.5 rounded bg-surface-subtle text-ink-secondary">
                                    {selectedAbstract.presentation_preference}
                                </span>
                            </div>
                            <button
                                onClick={() => setSelectedAbstract(null)}
                                className="text-ink-secondary hover:text-ink text-lg font-bold"
                            >
                                &times;
                            </button>
                        </div>

                        <div className="p-6 overflow-y-auto space-y-6 text-sm text-ink">
                            <div>
                                <h3 className="text-lg font-bold text-ink leading-snug">{selectedAbstract.title}</h3>
                                {selectedAbstract.track && (
                                    <div className="mt-1 text-xs text-ink-secondary font-medium">
                                        Track: <span className="text-ink font-semibold">{selectedAbstract.track}</span>
                                    </div>
                                )}
                            </div>

                            {/* Authors */}
                            <div className="border-t border-b border-border py-3">
                                <div className="text-xs font-semibold text-ink-secondary uppercase tracking-wider mb-2">Authors & Affiliations</div>
                                <div className="space-y-1">
                                    {selectedAbstract.authors.map((a, i) => (
                                        <div key={i} className="text-xs flex items-center gap-2">
                                            <span className={a.is_presenting ? 'font-bold text-ink underline' : 'text-ink'}>
                                                {a.name}
                                            </span>
                                            {a.is_presenting && <span className="text-[10px] text-accent font-semibold">(Presenting)</span>}
                                            <span className="text-ink-secondary">&bull; {a.affiliation}</span>
                                            {a.country && <span className="text-ink-secondary">({a.country})</span>}
                                        </div>
                                    ))}
                                </div>
                            </div>

                            {/* Structured Abstract Body */}
                            <div>
                                <div className="text-xs font-semibold text-ink-secondary uppercase tracking-wider mb-2">Abstract Body</div>
                                {selectedAbstract.structured_abstract ? (
                                    <div className="space-y-3 bg-surface-subtle p-4 rounded-lg text-xs leading-relaxed">
                                        {selectedAbstract.structured_abstract.background && (
                                            <div>
                                                <span className="font-bold text-accent uppercase tracking-wider text-[11px] block">Background:</span>
                                                <p className="mt-0.5 text-ink">{selectedAbstract.structured_abstract.background}</p>
                                            </div>
                                        )}
                                        {selectedAbstract.structured_abstract.methods && (
                                            <div>
                                                <span className="font-bold text-accent uppercase tracking-wider text-[11px] block">Methods:</span>
                                                <p className="mt-0.5 text-ink">{selectedAbstract.structured_abstract.methods}</p>
                                            </div>
                                        )}
                                        {selectedAbstract.structured_abstract.results && (
                                            <div>
                                                <span className="font-bold text-accent uppercase tracking-wider text-[11px] block">Results:</span>
                                                <p className="mt-0.5 text-ink">{selectedAbstract.structured_abstract.results}</p>
                                            </div>
                                        )}
                                        {selectedAbstract.structured_abstract.conclusion && (
                                            <div>
                                                <span className="font-bold text-accent uppercase tracking-wider text-[11px] block">Conclusion:</span>
                                                <p className="mt-0.5 text-ink">{selectedAbstract.structured_abstract.conclusion}</p>
                                            </div>
                                        )}
                                    </div>
                                ) : (
                                    <div className="bg-surface-subtle p-4 rounded-lg text-xs whitespace-pre-wrap">
                                        {selectedAbstract.body || 'No abstract text provided.'}
                                    </div>
                                )}
                            </div>

                            {/* Keywords & COI */}
                            <div className="grid grid-cols-2 gap-4 text-xs">
                                <div>
                                    <span className="font-semibold text-ink-secondary uppercase tracking-wider text-[10px] block">Keywords</span>
                                    <div className="mt-1 text-ink">
                                        {selectedAbstract.keywords?.length ? selectedAbstract.keywords.join(', ') : 'None'}
                                    </div>
                                </div>
                                <div>
                                    <span className="font-semibold text-ink-secondary uppercase tracking-wider text-[10px] block">Conflict of Interest</span>
                                    <div className="mt-1 text-ink">
                                        {selectedAbstract.conflict_of_interest || 'None reported'}
                                    </div>
                                </div>
                            </div>

                            {/* Manuscript Download */}
                            {selectedAbstract.file_url && (
                                <div>
                                    <a
                                        href={selectedAbstract.file_url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md border border-border text-xs font-semibold text-accent hover:bg-surface-hover"
                                    >
                                        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                        </svg>
                                        Download Submitted Manuscript File
                                    </a>
                                </div>
                            )}

                            {/* Peer Reviews & Rubric Breakdown */}
                            <div className="border-t border-border pt-4">
                                <div className="flex items-center justify-between mb-3">
                                    <div className="text-xs font-semibold text-ink-secondary uppercase tracking-wider">
                                        Peer Review Evaluations ({selectedAbstract.reviews.length})
                                    </div>
                                    {selectedAbstract.average_score !== null && (
                                        <span className="text-xs font-bold text-amber-700 bg-amber-50 px-2 py-0.5 rounded border border-amber-200">
                                            Average Score: {selectedAbstract.average_score} / 5.0
                                        </span>
                                    )}
                                </div>

                                {selectedAbstract.reviews.length === 0 ? (
                                    <p className="text-xs text-ink-secondary italic">No reviewers assigned yet.</p>
                                ) : (
                                    <div className="space-y-3">
                                        {selectedAbstract.reviews.map((r) => (
                                            <div key={r.id} className="border border-border rounded-lg p-3 bg-surface-subtle text-xs">
                                                <div className="flex items-center justify-between mb-2">
                                                    <span className="font-semibold text-ink">{r.reviewer_name}</span>
                                                    <div className="flex items-center gap-2">
                                                        <span className={`px-2 py-0.5 rounded text-[10px] font-bold ${
                                                            r.status === 'completed' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'
                                                        }`}>
                                                            {r.status}
                                                        </span>
                                                        <button
                                                            onClick={() => handleRemoveReviewer(selectedAbstract.id, r.id)}
                                                            className="text-rose-600 hover:underline text-[11px]"
                                                        >
                                                            Remove
                                                        </button>
                                                    </div>
                                                </div>

                                                {r.status === 'completed' ? (
                                                    <div className="space-y-2 mt-2">
                                                        <div className="grid grid-cols-4 gap-2 text-center bg-surface p-2 rounded border border-border">
                                                            <div>
                                                                <div className="text-[10px] text-ink-secondary">Novelty</div>
                                                                <div className="font-bold text-ink">{r.novelty_score}/5</div>
                                                            </div>
                                                            <div>
                                                                <div className="text-[10px] text-ink-secondary">Methodology</div>
                                                                <div className="font-bold text-ink">{r.methodology_score}/5</div>
                                                            </div>
                                                            <div>
                                                                <div className="text-[10px] text-ink-secondary">Relevance</div>
                                                                <div className="font-bold text-ink">{r.relevance_score}/5</div>
                                                            </div>
                                                            <div>
                                                                <div className="text-[10px] text-ink-secondary">Clarity</div>
                                                                <div className="font-bold text-ink">{r.clarity_score}/5</div>
                                                            </div>
                                                        </div>
                                                        <div className="flex items-center gap-2">
                                                            <span className="font-semibold text-ink">Recommendation:</span>
                                                            <span className="uppercase text-[10px] font-bold px-1.5 py-0.5 rounded bg-accent/10 text-accent">
                                                                {r.recommendation?.replace('_', ' ')}
                                                            </span>
                                                        </div>
                                                        {r.comments_to_author && (
                                                            <div>
                                                                <span className="font-semibold text-ink-secondary text-[11px]">Author Feedback:</span>
                                                                <p className="italic text-ink mt-0.5">{r.comments_to_author}</p>
                                                            </div>
                                                        )}
                                                        {r.confidential_comments && (
                                                            <div className="p-2 bg-amber-50/50 rounded border border-amber-100">
                                                                <span className="font-semibold text-amber-800 text-[11px]">Confidential Chair Note:</span>
                                                                <p className="text-amber-900 mt-0.5">{r.confidential_comments}</p>
                                                            </div>
                                                        )}
                                                    </div>
                                                ) : (
                                                    <p className="text-ink-secondary italic text-[11px] mt-1">
                                                        Awaiting review submission...
                                                    </p>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </div>

                        <div className="p-4 border-t border-border flex justify-end">
                            <button
                                onClick={() => setSelectedAbstract(null)}
                                className="px-4 py-2 text-xs font-semibold rounded-md border border-border bg-surface text-ink hover:bg-surface-hover"
                            >
                                Close
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* Assign Reviewer Modal */}
            {assigningFor && (
                <div className="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
                    <div className="bg-surface rounded-xl border border-border max-w-md w-full p-5 shadow-xl space-y-4">
                        <div className="flex items-center justify-between">
                            <h3 className="text-base font-bold text-ink">Assign Peer Reviewer</h3>
                            <button onClick={() => setAssigningFor(null)} className="text-ink-secondary hover:text-ink font-bold">&times;</button>
                        </div>

                        <p className="text-xs text-ink-secondary">
                            Assign an academic reviewer from your organization team for abstract <strong className="text-ink">{assigningFor.code}</strong>.
                        </p>

                        <form onSubmit={handleAssignReviewer} className="space-y-4">
                            <div>
                                <label className="text-xs font-semibold text-ink block mb-1">Select Reviewer</label>
                                <select
                                    value={selectedReviewerId}
                                    onChange={(e) => setSelectedReviewerId(e.target.value)}
                                    className="w-full text-xs rounded-md border-border bg-surface text-ink p-2 focus:ring-accent"
                                    required
                                >
                                    <option value="">-- Choose Reviewer --</option>
                                    {reviewers.map(u => (
                                        <option key={u.id} value={u.id}>
                                            {u.first_name} {u.last_name} ({u.email})
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div className="flex justify-end gap-2 pt-2">
                                <button
                                    type="button"
                                    onClick={() => setAssigningFor(null)}
                                    className="px-3 py-1.5 text-xs font-medium rounded-md border border-border text-ink hover:bg-surface-hover"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={actionLoading || !selectedReviewerId}
                                    className="px-4 py-1.5 text-xs font-semibold rounded-md bg-accent text-white hover:bg-accent-hover disabled:opacity-50"
                                >
                                    {actionLoading ? 'Assigning...' : 'Assign Reviewer'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* Record Decision Modal */}
            {decisionFor && (
                <div className="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
                    <div className="bg-surface rounded-xl border border-border max-w-lg w-full p-6 shadow-xl space-y-4">
                        <div className="flex items-center justify-between">
                            <h3 className="text-base font-bold text-ink">Record Scientific Decision</h3>
                            <button onClick={() => setDecisionFor(null)} className="text-ink-secondary hover:text-ink font-bold">&times;</button>
                        </div>

                        <div className="text-xs text-ink-secondary">
                            Abstract: <span className="font-mono font-bold text-ink">{decisionFor.code}</span> &bull; {decisionFor.title}
                        </div>

                        <form onSubmit={handleRecordDecision} className="space-y-4">
                            <div>
                                <label className="text-xs font-semibold text-ink block mb-1">Final Decision</label>
                                <select
                                    value={decisionStatus}
                                    onChange={(e) => setDecisionStatus(e.target.value)}
                                    className="w-full text-xs rounded-md border-border bg-surface text-ink p-2 focus:ring-accent"
                                >
                                    <option value="accepted_oral">Accept as Oral Presentation</option>
                                    <option value="accepted_poster">Accept as Poster Presentation</option>
                                    <option value="rejected">Reject Submission</option>
                                </select>
                            </div>

                            <div>
                                <label className="text-xs font-semibold text-ink block mb-1">Decision Notes & Feedback (sent to author)</label>
                                <textarea
                                    rows="4"
                                    value={decisionNotes}
                                    onChange={(e) => setDecisionNotes(e.target.value)}
                                    placeholder="Enter feedback or acceptance details for the author..."
                                    className="w-full text-xs rounded-md border-border bg-surface text-ink p-2 focus:ring-accent"
                                />
                            </div>

                            <label className="flex items-center gap-2 cursor-pointer text-xs text-ink">
                                <input
                                    type="checkbox"
                                    checked={notifyAuthor}
                                    onChange={(e) => setNotifyAuthor(e.target.checked)}
                                    className="rounded border-border text-accent focus:ring-accent"
                                />
                                Send formal decision notification email to author immediately
                            </label>

                            <div className="flex justify-end gap-2 pt-2">
                                <button
                                    type="button"
                                    onClick={() => setDecisionFor(null)}
                                    className="px-3 py-1.5 text-xs font-medium rounded-md border border-border text-ink hover:bg-surface-hover"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={actionLoading}
                                    className="px-4 py-1.5 text-xs font-semibold rounded-md bg-accent text-white hover:bg-accent-hover disabled:opacity-50"
                                >
                                    {actionLoading ? 'Saving...' : 'Save Decision'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </div>
    );
}
