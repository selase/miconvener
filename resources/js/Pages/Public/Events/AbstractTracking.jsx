import React from 'react';
import { Head, Link } from '@inertiajs/react';

export default function AbstractTracking({ event, abstract, org }) {
    const isAccepted = abstract.status === 'accepted_oral' || abstract.status === 'accepted_poster';

    return (
        <div className="min-h-screen bg-slate-50 text-slate-900 py-10 px-4 sm:px-6 lg:px-8 font-sans">
            <Head title={`Abstract ${abstract.code} - ${event.title}`} />

            <div className="max-w-3xl mx-auto space-y-6">
                {/* Navigation Header */}
                <div className="flex items-center justify-between">
                    <Link
                        href={route('public.events.show', { event: event.slug })}
                        className="text-xs font-semibold text-blue-600 hover:text-blue-700 inline-flex items-center gap-1"
                    >
                        &larr; Back to Event Page
                    </Link>
                    <span className="text-xs font-medium text-slate-500">{org?.name}</span>
                </div>

                {/* Main Status Card */}
                <div className="bg-white rounded-2xl p-6 sm:p-8 shadow-sm border border-slate-200 space-y-6">
                    <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 border-b border-slate-100 pb-5">
                        <div>
                            <span className="font-mono font-bold text-blue-600 text-sm">{abstract.code}</span>
                            <span className="text-xs text-slate-400 ml-2">&bull; Submitted on {new Date(abstract.created_at).toLocaleDateString()}</span>
                        </div>
                        <span className={`inline-flex items-center px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider ${
                            abstract.status === 'accepted_oral' ? 'bg-emerald-100 text-emerald-800' :
                            abstract.status === 'accepted_poster' ? 'bg-teal-100 text-teal-800' :
                            abstract.status === 'rejected' ? 'bg-rose-100 text-rose-800' :
                            abstract.status === 'under_review' ? 'bg-amber-100 text-amber-800' :
                            'bg-blue-100 text-blue-800'
                        }`}>
                            {abstract.status.replace('_', ' ')}
                        </span>
                    </div>

                    <div>
                        <h1 className="text-xl sm:text-2xl font-bold text-slate-900 leading-snug">
                            {abstract.title}
                        </h1>
                        {abstract.track && (
                            <div className="mt-2 inline-block px-2.5 py-1 rounded-lg bg-slate-100 text-slate-700 text-xs font-medium">
                                Track: {abstract.track}
                            </div>
                        )}
                    </div>

                    {/* Committee Decision Callout */}
                    {abstract.decision_notes && (
                        <div className={`p-4 rounded-xl border ${
                            isAccepted ? 'bg-emerald-50 border-emerald-200 text-emerald-900' : 'bg-slate-100 border-slate-200 text-slate-800'
                        }`}>
                            <div className="font-bold text-xs uppercase tracking-wider mb-1">Scientific Committee Note:</div>
                            <p className="text-xs leading-relaxed italic">{abstract.decision_notes}</p>
                        </div>
                    )}

                    {/* Scheduled Sessions (if accepted and programmed) */}
                    {abstract.scheduled_sessions?.length > 0 && (
                        <div className="p-4 rounded-xl bg-blue-50 border border-blue-200 space-y-2">
                            <div className="text-xs font-bold text-blue-900 uppercase tracking-wider">
                                Scheduled Presentation Session
                            </div>
                            {abstract.scheduled_sessions.map((s) => (
                                <div key={s.id} className="text-xs text-blue-950">
                                    <div className="font-bold">{s.title} ({s.type?.replace('_', ' ')})</div>
                                    <div className="text-blue-800 mt-0.5">
                                        {new Date(s.starts_at).toLocaleString()} {s.location ? `• Room: ${s.location}` : ''}
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}

                    {/* Authors */}
                    <div className="border-t border-slate-100 pt-4">
                        <div className="text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Authors</div>
                        <div className="space-y-1">
                            {abstract.authors.map((a, i) => (
                                <div key={i} className="text-xs text-slate-700">
                                    <span className={a.is_presenting ? 'font-bold text-slate-900 underline' : 'font-medium'}>
                                        {a.name}
                                    </span>
                                    {a.is_presenting && <span className="text-[10px] text-blue-600 font-bold ml-1">(Presenting)</span>}
                                    <span className="text-slate-500 ml-1">&bull; {a.affiliation}</span>
                                    {a.country && <span className="text-slate-500 ml-1">({a.country})</span>}
                                </div>
                            ))}
                        </div>
                    </div>

                    {/* Structured Body */}
                    <div className="border-t border-slate-100 pt-4 space-y-3">
                        <div className="text-xs font-bold text-slate-500 uppercase tracking-wider">Abstract Text</div>
                        {abstract.structured_abstract ? (
                            <div className="space-y-3 bg-slate-50 p-4 rounded-xl text-xs leading-relaxed text-slate-800">
                                {abstract.structured_abstract.background && (
                                    <div>
                                        <span className="font-bold text-blue-700 uppercase tracking-wider text-[10px] block">Background:</span>
                                        <p className="mt-0.5">{abstract.structured_abstract.background}</p>
                                    </div>
                                )}
                                {abstract.structured_abstract.methods && (
                                    <div>
                                        <span className="font-bold text-blue-700 uppercase tracking-wider text-[10px] block">Methods:</span>
                                        <p className="mt-0.5">{abstract.structured_abstract.methods}</p>
                                    </div>
                                )}
                                {abstract.structured_abstract.results && (
                                    <div>
                                        <span className="font-bold text-blue-700 uppercase tracking-wider text-[10px] block">Results:</span>
                                        <p className="mt-0.5">{abstract.structured_abstract.results}</p>
                                    </div>
                                )}
                                {abstract.structured_abstract.conclusion && (
                                    <div>
                                        <span className="font-bold text-blue-700 uppercase tracking-wider text-[10px] block">Conclusion:</span>
                                        <p className="mt-0.5">{abstract.structured_abstract.conclusion}</p>
                                    </div>
                                )}
                            </div>
                        ) : (
                            <p className="text-xs text-slate-700 whitespace-pre-wrap">{abstract.body}</p>
                        )}
                    </div>

                    {abstract.file_url && (
                        <div className="border-t border-slate-100 pt-4">
                            <a
                                href={abstract.file_url}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="inline-flex items-center gap-2 text-xs font-semibold text-blue-600 hover:text-blue-700"
                            >
                                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                                Download Submitted Manuscript File
                            </a>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
