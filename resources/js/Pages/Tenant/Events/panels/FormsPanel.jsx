import { useState, useEffect } from 'react';
import Button from '@/Components/Console/Button';
import Modal from '@/Components/Console/Modal';
import Input from '@/Components/Console/Input';
import Select from '@/Components/Console/Select';
import Checkbox from '@/Components/Console/Checkbox';
import ConfirmModal from '@/Components/Console/ConfirmModal';
import csrfFetch from '@/lib/csrfFetch';
import {
    FileText,
    Plus,
    Copy,
    ExternalLink,
    Download,
    Trash2,
    Edit2,
    CheckCircle2,
    Clock,
    Users,
    Layers,
    RefreshCw,
    X,
    Eye,
    Star,
    Check,
    AlertCircle,
    CopyCheck
} from 'lucide-react';

const FORM_TYPES = [
    { value: 'general', label: 'General Form' },
    { value: 'registration', label: 'Supplemental Registration' },
    { value: 'survey', label: 'Delegate Survey' },
    { value: 'feedback', label: 'Session / Event Feedback' },
    { value: 'cme_evaluation', label: 'CME / CPD Course Evaluation' },
    { value: 'workshop_signup', label: 'Workshop / Breakout Selection' },
    { value: 'abstract_disclosure', label: 'Abstract & Faculty Disclosure' },
];

const FIELD_TYPES = [
    { value: 'text', label: 'Short Text' },
    { value: 'textarea', label: 'Long Text / Paragraph' },
    { value: 'select', label: 'Single Select Dropdown' },
    { value: 'radio', label: 'Radio Buttons' },
    { value: 'multiselect', label: 'Multiple Choice (Checkboxes)' },
    { value: 'rating', label: 'Rating Scale (1 - 5 Stars)' },
    { value: 'number', label: 'Numeric Value' },
    { value: 'date', label: 'Date' },
    { value: 'time', label: 'Time' },
];

export default function FormsPanel({ event }) {
    const [loading, setLoading] = useState(true);
    const [forms, setForms] = useState([]);
    const [summary, setSummary] = useState(null);
    const [copiedSlug, setCopiedSlug] = useState(null);

    // Form Modal
    const [modalOpen, setModalOpen] = useState(false);
    const [editingForm, setEditingForm] = useState(null);
    const [saving, setSaving] = useState(false);
    const [deleteTarget, setDeleteTarget] = useState(null);

    // Submissions Modal
    const [submissionsModalOpen, setSubmissionsModalOpen] = useState(false);
    const [selectedFormForSubmissions, setSelectedFormForSubmissions] = useState(null);
    const [submissions, setSubmissions] = useState([]);
    const [loadingSubmissions, setLoadingSubmissions] = useState(false);

    // Schema Builder State
    const [formFields, setFormFields] = useState([]);
    const [formData, setFormData] = useState({
        title: '',
        description: '',
        type: 'general',
        is_active: true,
        is_public: true,
        requires_check_in: false,
        submission_limit: '',
    });

    const loadData = async () => {
        setLoading(true);
        try {
            const res = await csrfFetch(route('tenant.events.dynamic-forms.index', { event: event.id }));
            if (res.ok) {
                const data = await res.json();
                setForms(data.forms || []);
                setSummary(data.summary || null);
            }
        } catch (err) {
            console.error('Failed to load dynamic forms:', err);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        loadData();
    }, [event.id]);

    const openCreateModal = () => {
        setEditingForm(null);
        setFormData({
            title: '',
            description: '',
            type: 'general',
            is_active: true,
            is_public: true,
            requires_check_in: false,
            submission_limit: '',
        });
        setFormFields([
            { key: 'feedback_comment', label: 'Overall Impression', type: 'textarea', required: true, options: [] },
            { key: 'overall_rating', label: 'Quality Rating', type: 'rating', required: true, options: [] },
        ]);
        setModalOpen(true);
    };

    const openEditModal = async (f) => {
        setEditingForm(f);
        setFormData({
            title: f.title,
            description: f.description || '',
            type: f.type,
            is_active: f.is_active,
            is_public: f.is_public,
            requires_check_in: f.requires_check_in,
            submission_limit: f.submission_limit ? String(f.submission_limit) : '',
        });
        setFormFields(f.schema || []);
        setModalOpen(true);
    };

    const addField = () => {
        const index = formFields.length + 1;
        setFormFields([
            ...formFields,
            {
                key: `field_${Date.now()}`,
                label: `New Question ${index}`,
                type: 'text',
                required: false,
                placeholder: '',
                options: ['Option 1', 'Option 2'],
            }
        ]);
    };

    const updateField = (idx, patch) => {
        const updated = [...formFields];
        updated[idx] = { ...updated[idx], ...patch };
        setFormFields(updated);
    };

    const removeField = (idx) => {
        setFormFields(formFields.filter((_, i) => i !== idx));
    };

    const handleSaveForm = async (e) => {
        e.preventDefault();
        setSaving(true);
        try {
            const url = editingForm
                ? route('tenant.events.dynamic-forms.update', { event: event.id, form: editingForm.id })
                : route('tenant.events.dynamic-forms.store', { event: event.id });
            const method = editingForm ? 'PUT' : 'POST';

            const payload = {
                ...formData,
                submission_limit: formData.submission_limit ? parseInt(formData.submission_limit, 10) : null,
                schema: formFields.map(f => ({
                    ...f,
                    key: f.key || f.label.toLowerCase().replace(/[^a-z0-9]/g, '_'),
                })),
            };

            const res = await csrfFetch(url, {
                method,
                body: JSON.stringify(payload),
            });

            if (res.ok) {
                setModalOpen(false);
                setEditingForm(null);
                await loadData();
            }
        } catch (err) {
            console.error('Failed to save form:', err);
        } finally {
            setSaving(false);
        }
    };

    const handleDeleteForm = async () => {
        if (!deleteTarget) return;
        try {
            const res = await csrfFetch(route('tenant.events.dynamic-forms.destroy', {
                event: event.id,
                form: deleteTarget.id
            }), {
                method: 'DELETE',
            });
            if (res.ok) {
                setDeleteTarget(null);
                await loadData();
            }
        } catch (err) {
            console.error('Failed to delete form:', err);
        }
    };

    const handleDuplicate = async (f) => {
        try {
            const res = await csrfFetch(route('tenant.events.dynamic-forms.duplicate', {
                event: event.id,
                form: f.id,
            }), { method: 'POST' });
            if (res.ok) {
                await loadData();
            }
        } catch (err) {
            console.error('Failed to duplicate form:', err);
        }
    };

    const openSubmissions = async (f) => {
        setSelectedFormForSubmissions(f);
        setSubmissionsModalOpen(true);
        setLoadingSubmissions(true);
        try {
            const res = await csrfFetch(route('tenant.events.dynamic-forms.submissions', {
                event: event.id,
                form: f.id,
            }));
            if (res.ok) {
                const data = await res.json();
                setSubmissions(data.submissions?.data || []);
            }
        } catch (err) {
            console.error('Failed to load submissions:', err);
        } finally {
            setLoadingSubmissions(false);
        }
    };

    const copyPublicUrl = (f) => {
        navigator.clipboard.writeText(f.public_url);
        setCopiedSlug(f.id);
        setTimeout(() => setCopiedSlug(null), 2000);
    };

    return (
        <div className="space-y-6">
            {/* Header */}
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <h2 className="text-xl font-bold text-slate-900 dark:text-white flex items-center gap-2">
                        <FileText className="w-5 h-5 text-indigo-600" />
                        In-Event Dynamic Forms & Questionnaires Engine
                    </h2>
                    <p className="text-sm text-slate-500 dark:text-slate-400">
                        Create general-purpose surveys, CME evaluations, workshop signups, and dietary questionnaires on the fly.
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    <Button variant="primary" size="sm" onClick={openCreateModal}>
                        <Plus className="w-4 h-4 mr-1.5" />
                        Create Form
                    </Button>
                </div>
            </div>

            {/* Metrics */}
            {summary && (
                <div className="grid grid-cols-2 md:grid-cols-3 gap-4">
                    <div className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-4 shadow-sm">
                        <span className="text-xs font-semibold text-slate-500 uppercase tracking-wider">Total Forms</span>
                        <div className="mt-2 text-2xl font-black text-slate-900 dark:text-white">
                            {summary.total_forms}
                        </div>
                        <p className="mt-1 text-xs text-slate-500">Active engines</p>
                    </div>

                    <div className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-4 shadow-sm">
                        <span className="text-xs font-semibold text-slate-500 uppercase tracking-wider">Open for Responses</span>
                        <div className="mt-2 text-2xl font-black text-emerald-600">
                            {summary.active_forms}
                        </div>
                        <p className="mt-1 text-xs text-slate-500">Live questionnaires</p>
                    </div>

                    <div className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-4 shadow-sm">
                        <span className="text-xs font-semibold text-slate-500 uppercase tracking-wider">Total Submissions</span>
                        <div className="mt-2 text-2xl font-black text-indigo-600">
                            {summary.total_submissions}
                        </div>
                        <p className="mt-1 text-xs text-slate-500">Collected responses</p>
                    </div>
                </div>
            )}

            {/* Forms Roster */}
            {loading ? (
                <div className="p-12 text-center text-xs text-slate-500">Loading forms...</div>
            ) : forms.length === 0 ? (
                <div className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-12 text-center space-y-3">
                    <FileText className="w-10 h-10 text-slate-300 mx-auto" />
                    <p className="text-sm font-bold text-slate-700 dark:text-slate-300">No Dynamic Forms Created Yet</p>
                    <p className="text-xs text-slate-500 max-w-md mx-auto">
                        Create custom in-event surveys, CME evaluations, workshop selection forms, or feedback polls with custom schemas.
                    </p>
                    <Button variant="primary" size="sm" onClick={openCreateModal}>
                        <Plus className="w-4 h-4 mr-1.5" />
                        Create First Form
                    </Button>
                </div>
            ) : (
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    {forms.map(f => (
                        <div
                            key={f.id}
                            className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-4 flex flex-col justify-between shadow-xs hover:border-indigo-400 transition"
                        >
                            <div>
                                <div className="flex items-center justify-between gap-2">
                                    <span className="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded bg-indigo-50 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-300">
                                        {f.type.replace('_', ' ')}
                                    </span>
                                    <span className={`text-[10px] font-semibold px-2 py-0.5 rounded-full ${
                                        f.is_active
                                            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300'
                                            : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400'
                                    }`}>
                                        {f.is_active ? 'Active' : 'Closed'}
                                    </span>
                                </div>

                                <h3 className="text-sm font-bold text-slate-900 dark:text-white mt-2 line-clamp-1">
                                    {f.title}
                                </h3>

                                {f.description && (
                                    <p className="text-xs text-slate-500 dark:text-slate-400 mt-1 line-clamp-2">
                                        {f.description}
                                    </p>
                                )}

                                <div className="mt-3 pt-3 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between text-xs text-slate-500">
                                    <span>{f.fields_count} fields</span>
                                    <span className="font-semibold text-slate-900 dark:text-white">
                                        {f.submissions_count} responses
                                    </span>
                                </div>
                            </div>

                            {/* Actions */}
                            <div className="mt-4 pt-3 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between gap-1">
                                <div className="flex items-center gap-1">
                                    <button
                                        onClick={() => openSubmissions(f)}
                                        className="text-xs font-semibold text-indigo-600 hover:text-indigo-800 flex items-center gap-1 px-2 py-1 rounded bg-indigo-50 dark:bg-indigo-900/30"
                                        title="View Submissions"
                                    >
                                        <Eye className="w-3.5 h-3.5" />
                                        Responses
                                    </button>
                                    <button
                                        onClick={() => copyPublicUrl(f)}
                                        className="text-xs text-slate-500 hover:text-slate-800 p-1.5 rounded hover:bg-slate-100 dark:hover:bg-slate-800"
                                        title="Copy Public Link"
                                    >
                                        {copiedSlug === f.id ? <Check className="w-3.5 h-3.5 text-emerald-600" /> : <Copy className="w-3.5 h-3.5" />}
                                    </button>
                                    <a
                                        href={f.public_url}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="text-xs text-slate-500 hover:text-slate-800 p-1.5 rounded hover:bg-slate-100 dark:hover:bg-slate-800"
                                        title="Open in new tab"
                                    >
                                        <ExternalLink className="w-3.5 h-3.5" />
                                    </a>
                                </div>

                                <div className="flex items-center gap-1">
                                    <button
                                        onClick={() => handleDuplicate(f)}
                                        className="text-slate-400 hover:text-slate-700 p-1"
                                        title="Duplicate Form"
                                    >
                                        <CopyCheck className="w-3.5 h-3.5" />
                                    </button>
                                    <button
                                        onClick={() => openEditModal(f)}
                                        className="text-slate-400 hover:text-indigo-600 p-1"
                                        title="Edit Schema"
                                    >
                                        <Edit2 className="w-3.5 h-3.5" />
                                    </button>
                                    <button
                                        onClick={() => setDeleteTarget(f)}
                                        className="text-slate-400 hover:text-rose-600 p-1"
                                        title="Delete Form"
                                    >
                                        <Trash2 className="w-3.5 h-3.5" />
                                    </button>
                                </div>
                            </div>
                        </div>
                    ))}
                </div>
            )}

            {/* Create/Edit Form Modal with Schema Builder */}
            <Modal
                isOpen={modalOpen}
                onClose={() => setModalOpen(false)}
                title={editingForm ? 'Edit Dynamic Form' : 'Create General-Purpose Dynamic Form'}
            >
                <form onSubmit={handleSaveForm} className="space-y-4 max-h-[75vh] overflow-y-auto pr-1">
                    <div className="grid grid-cols-2 gap-3">
                        <div className="col-span-2 sm:col-span-1">
                            <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                Form Title *
                            </label>
                            <Input
                                type="text"
                                required
                                placeholder="e.g. Session 3 CME Knowledge Assessment"
                                value={formData.title}
                                onChange={(e) => setFormData({ ...formData, title: e.target.value })}
                            />
                        </div>
                        <div className="col-span-2 sm:col-span-1">
                            <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                Form Category *
                            </label>
                            <Select
                                value={formData.type}
                                onChange={(e) => setFormData({ ...formData, type: e.target.value })}
                            >
                                {FORM_TYPES.map(t => (
                                    <option key={t.value} value={t.value}>{t.label}</option>
                                ))}
                            </Select>
                        </div>
                    </div>

                    <div>
                        <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                            Description / Instructions
                        </label>
                        <textarea
                            rows={2}
                            placeholder="Provide guidance to respondents..."
                            className="w-full text-xs rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 p-2.5 text-slate-900 dark:text-white focus:outline-none focus:ring-1 focus:ring-indigo-500"
                            value={formData.description}
                            onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                        />
                    </div>

                    <div className="grid grid-cols-2 gap-3 pt-2 border-t border-slate-100 dark:border-slate-800">
                        <label className="flex items-center gap-2 text-xs font-medium text-slate-700 dark:text-slate-300 cursor-pointer">
                            <Checkbox
                                checked={formData.is_active}
                                onChange={(checked) => setFormData({ ...formData, is_active: checked })}
                            />
                            Active (Accepting Responses)
                        </label>
                        <label className="flex items-center gap-2 text-xs font-medium text-slate-700 dark:text-slate-300 cursor-pointer">
                            <Checkbox
                                checked={formData.requires_check_in}
                                onChange={(checked) => setFormData({ ...formData, requires_check_in: checked })}
                            />
                            Restricted to Checked-In Attendees
                        </label>
                    </div>

                    {/* Field Schema Builder */}
                    <div className="pt-3 border-t border-slate-200 dark:border-slate-800 space-y-3">
                        <div className="flex items-center justify-between">
                            <h4 className="text-xs font-bold text-slate-900 dark:text-white uppercase tracking-wider">
                                Form Questions & Fields ({formFields.length})
                            </h4>
                            <button
                                type="button"
                                onClick={addField}
                                className="text-xs font-semibold text-indigo-600 hover:text-indigo-800 flex items-center gap-1"
                            >
                                <Plus className="w-3.5 h-3.5" />
                                Add Question
                            </button>
                        </div>

                        <div className="space-y-3">
                            {formFields.map((field, idx) => (
                                <div
                                    key={field.key || idx}
                                    className="p-3 bg-slate-50 dark:bg-slate-800/50 rounded-lg border border-slate-200 dark:border-slate-700 space-y-2 relative"
                                >
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="text-[10px] font-bold text-slate-400">Q{idx + 1}</span>
                                        <button
                                            type="button"
                                            onClick={() => removeField(idx)}
                                            className="text-slate-400 hover:text-rose-600 p-0.5"
                                        >
                                            <X className="w-3.5 h-3.5" />
                                        </button>
                                    </div>

                                    <div className="grid grid-cols-2 gap-2">
                                        <div>
                                            <label className="block text-[10px] font-semibold text-slate-500 mb-0.5">
                                                Question Label
                                            </label>
                                            <Input
                                                type="text"
                                                value={field.label}
                                                onChange={(e) => updateField(idx, { label: e.target.value })}
                                            />
                                        </div>
                                        <div>
                                            <label className="block text-[10px] font-semibold text-slate-500 mb-0.5">
                                                Answer Input Type
                                            </label>
                                            <Select
                                                value={field.type}
                                                onChange={(e) => updateField(idx, { type: e.target.value })}
                                            >
                                                {FIELD_TYPES.map(ft => (
                                                    <option key={ft.value} value={ft.value}>{ft.label}</option>
                                                ))}
                                            </Select>
                                        </div>
                                    </div>

                                    {/* Options input for select/radio/multiselect */}
                                    {['select', 'radio', 'multiselect'].includes(field.type) && (
                                        <div>
                                            <label className="block text-[10px] font-semibold text-slate-500 mb-0.5">
                                                Comma-Separated Options
                                            </label>
                                            <Input
                                                type="text"
                                                placeholder="Option 1, Option 2, Option 3"
                                                value={Array.isArray(field.options) ? field.options.join(', ') : ''}
                                                onChange={(e) => updateField(idx, {
                                                    options: e.target.value.split(',').map(s => s.trim()).filter(Boolean)
                                                })}
                                            />
                                        </div>
                                    )}

                                    <div className="flex items-center gap-2 pt-1">
                                        <label className="flex items-center gap-1.5 text-[11px] text-slate-600 dark:text-slate-400 cursor-pointer">
                                            <Checkbox
                                                checked={field.required}
                                                onChange={(checked) => updateField(idx, { required: checked })}
                                            />
                                            Required Answer
                                        </label>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>

                    <div className="flex justify-end gap-2 pt-3 border-t border-slate-100 dark:border-slate-800">
                        <Button variant="secondary" type="button" onClick={() => setModalOpen(false)}>
                            Cancel
                        </Button>
                        <Button variant="primary" type="submit" disabled={saving}>
                            {saving ? 'Saving...' : (editingForm ? 'Update Form' : 'Save & Publish Form')}
                        </Button>
                    </div>
                </form>
            </Modal>

            {/* Submissions Viewer Modal */}
            <Modal
                isOpen={submissionsModalOpen}
                onClose={() => setSubmissionsModalOpen(false)}
                title={`Submissions: ${selectedFormForSubmissions?.title || ''}`}
            >
                <div className="space-y-4 max-h-[75vh] flex flex-col">
                    <div className="flex items-center justify-between">
                        <span className="text-xs text-slate-500">
                            {submissions.length} Total Submissions Recorded
                        </span>
                        {selectedFormForSubmissions && (
                            <a
                                href={route('tenant.events.dynamic-forms.submissions.export', {
                                    event: event.id,
                                    form: selectedFormForSubmissions.id,
                                })}
                                className="inline-flex items-center gap-1.5 text-xs font-semibold px-2.5 py-1.5 rounded-lg bg-indigo-50 dark:bg-indigo-900/30 text-indigo-600 hover:bg-indigo-100"
                            >
                                <Download className="w-3.5 h-3.5" />
                                Export CSV
                            </a>
                        )}
                    </div>

                    <div className="overflow-x-auto flex-1 border border-slate-200 dark:border-slate-800 rounded-lg">
                        {loadingSubmissions ? (
                            <div className="p-8 text-center text-xs text-slate-500">Loading submissions...</div>
                        ) : submissions.length === 0 ? (
                            <div className="p-8 text-center text-xs text-slate-400 italic">No responses recorded yet</div>
                        ) : (
                            <table className="w-full text-left text-xs">
                                <thead className="bg-slate-50 dark:bg-slate-800 text-slate-600 dark:text-slate-300 font-semibold">
                                    <tr>
                                        <th className="p-2.5">Respondent</th>
                                        <th className="p-2.5">Date</th>
                                        {(selectedFormForSubmissions?.schema || []).map(f => (
                                            <th key={f.key} className="p-2.5">{f.label}</th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                    {submissions.map(sub => (
                                        <tr key={sub.id} className="hover:bg-slate-50 dark:hover:bg-slate-800/40">
                                            <td className="p-2.5 font-medium text-slate-900 dark:text-white">
                                                {sub.respondent_name || sub.registration?.full_name || 'Anonymous'}
                                                <div className="text-[10px] text-slate-400">
                                                    {sub.respondent_email || sub.registration?.email}
                                                </div>
                                            </td>
                                            <td className="p-2.5 text-slate-500 whitespace-nowrap">
                                                {sub.submitted_at?.split('T')[0]}
                                            </td>
                                            {(selectedFormForSubmissions?.schema || []).map(f => {
                                                const ans = sub.answers?.[f.key];
                                                return (
                                                    <td key={f.key} className="p-2.5 max-w-[160px] truncate">
                                                        {f.type === 'rating' && ans ? (
                                                            <div className="flex items-center gap-0.5 text-amber-500">
                                                                {ans} <Star className="w-3 h-3 fill-amber-500" />
                                                            </div>
                                                        ) : Array.isArray(ans) ? (
                                                            ans.join(', ')
                                                        ) : (
                                                            String(ans ?? '—')
                                                        )}
                                                    </td>
                                                );
                                            })}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </div>

                    <div className="flex justify-end pt-2">
                        <Button variant="secondary" onClick={() => setSubmissionsModalOpen(false)}>
                            Close
                        </Button>
                    </div>
                </div>
            </Modal>

            {/* Confirm Delete Modal */}
            <ConfirmModal
                isOpen={!!deleteTarget}
                title="Delete Dynamic Form?"
                message={`Are you sure you want to delete "${deleteTarget?.title}"? All submitted responses will be permanently removed.`}
                confirmLabel="Delete Form"
                variant="danger"
                onConfirm={handleDeleteForm}
                onCancel={() => setDeleteTarget(null)}
            />
        </div>
    );
}
