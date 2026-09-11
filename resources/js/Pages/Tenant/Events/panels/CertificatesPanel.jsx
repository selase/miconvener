import { useState, useEffect } from 'react';
import Button from '@/Components/Console/Button';
import Modal from '@/Components/Console/Modal';
import Input from '@/Components/Console/Input';
import Select from '@/Components/Console/Select';
import Checkbox from '@/Components/Console/Checkbox';
import ConfirmModal from '@/Components/Console/ConfirmModal';
import csrfFetch from '@/lib/csrfFetch';
import {
    Award,
    Download,
    QrCode,
    CheckCircle2,
    Users,
    Mic,
    FileText,
    HeartHandshake,
    RefreshCw,
    Search,
    Edit3,
    Trash2,
    ExternalLink,
    Send,
    Plus,
    Clock,
    AlertCircle
} from 'lucide-react';

const ROLE_ICONS = {
    delegate: Users,
    speaker: Mic,
    presenter: FileText,
    volunteer: HeartHandshake,
    custom: Award,
};

const ROLE_BADGES = {
    delegate: { bg: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300', label: 'Delegate' },
    speaker: { bg: 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300', label: 'Speaker' },
    presenter: { bg: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300', label: 'Presenter' },
    volunteer: { bg: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300', label: 'Volunteer' },
    custom: { bg: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300', label: 'Custom' },
};

export default function CertificatesPanel({ event }) {
    const [loading, setLoading] = useState(true);
    const [templates, setTemplates] = useState([]);
    const [certificates, setCertificates] = useState([]);
    const [stats, setStats] = useState(null);
    const [eligible, setEligible] = useState(null);
    const [search, setSearch] = useState('');
    const [roleFilter, setRoleFilter] = useState('all');

    // Template Edit Modal
    const [templateModalOpen, setTemplateModalOpen] = useState(false);
    const [selectedTemplate, setSelectedTemplate] = useState(null);
    const [savingTemplate, setSavingTemplate] = useState(false);
    const [templateForm, setTemplateForm] = useState({
        role: 'delegate',
        title: '',
        body_template: '',
        issuer_name: '',
        issuer_title: '',
        show_qr: true,
        show_cpd_hours: true,
        default_cpd_hours: '6.0',
    });

    // Issue Modal
    const [issueModalOpen, setIssueModalOpen] = useState(false);
    const [issuing, setIssuing] = useState(false);
    const [issueForm, setIssueForm] = useState({
        target_group: 'checked_in_delegates',
        role: 'delegate',
        template_id: '',
        cpd_hours: '6.0',
        custom_name: '',
        custom_email: '',
    });
    const [issueResult, setIssueResult] = useState(null);

    // Delete Modal
    const [deleteCertTarget, setDeleteCertTarget] = useState(null);

    const loadData = async () => {
        setLoading(true);
        try {
            let url = route('tenant.events.certificates.index', { event: event.id });
            const params = new URLSearchParams();
            if (roleFilter !== 'all') params.append('role', roleFilter);
            if (search.trim()) params.append('search', search.trim());
            if (params.toString()) url += `?${params.toString()}`;

            const res = await csrfFetch(url);
            if (res.ok) {
                const data = await res.json();
                setTemplates(data.templates || []);
                setCertificates(data.certificates?.data || []);
                setStats(data.stats || null);
                setEligible(data.eligible || null);
            }
        } catch (err) {
            console.error('Failed to load certificates data:', err);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        loadData();
    }, [event.id, roleFilter]);

    // Open Template Editor
    const openEditTemplate = (tmpl) => {
        setSelectedTemplate(tmpl);
        setTemplateForm({
            role: tmpl.role,
            title: tmpl.title,
            body_template: tmpl.body_template || '',
            issuer_name: tmpl.issuer_name || '',
            issuer_title: tmpl.issuer_title || '',
            show_qr: tmpl.show_qr !== false,
            show_cpd_hours: !!tmpl.show_cpd_hours,
            default_cpd_hours: tmpl.default_cpd_hours ? String(tmpl.default_cpd_hours) : '0',
        });
        setTemplateModalOpen(true);
    };

    // Save Template
    const handleSaveTemplate = async (e) => {
        e.preventDefault();
        setSavingTemplate(true);
        try {
            const url = selectedTemplate
                ? route('tenant.events.certificates.templates.update', { event: event.id, template: selectedTemplate.id })
                : route('tenant.events.certificates.templates.store', { event: event.id });
            const method = selectedTemplate ? 'PUT' : 'POST';

            const res = await csrfFetch(url, {
                method,
                body: JSON.stringify(templateForm),
            });

            if (res.ok) {
                setTemplateModalOpen(false);
                setSelectedTemplate(null);
                await loadData();
            }
        } catch (err) {
            console.error('Failed to save certificate template:', err);
        } finally {
            setSavingTemplate(false);
        }
    };

    // Handle Bulk/Individual Issuance
    const handleIssueCertificates = async (e) => {
        e.preventDefault();
        setIssuing(true);
        setIssueResult(null);
        try {
            const payload = {
                target_group: issueForm.target_group,
                role: issueForm.role,
                template_id: issueForm.template_id || undefined,
                cpd_hours: parseFloat(issueForm.cpd_hours) || 0,
            };

            if (issueForm.target_group === 'custom') {
                payload.custom_recipients = [
                    { name: issueForm.custom_name, email: issueForm.custom_email }
                ];
            }

            const res = await csrfFetch(route('tenant.events.certificates.issue', { event: event.id }), {
                method: 'POST',
                body: JSON.stringify(payload),
            });

            if (res.ok) {
                const data = await res.json();
                setIssueResult(data.message);
                setTimeout(() => {
                    setIssueModalOpen(false);
                    setIssueResult(null);
                }, 1500);
                await loadData();
            }
        } catch (err) {
            console.error('Failed to issue certificates:', err);
        } finally {
            setIssuing(false);
        }
    };

    // Delete Certificate
    const handleDeleteCertificate = async () => {
        if (!deleteCertTarget) return;
        try {
            const res = await csrfFetch(route('tenant.events.certificates.destroy', {
                event: event.id,
                certificate: deleteCertTarget.id
            }), {
                method: 'DELETE',
            });
            if (res.ok) {
                setDeleteCertTarget(null);
                await loadData();
            }
        } catch (err) {
            console.error('Failed to delete certificate:', err);
        }
    };

    return (
        <div className="space-y-6">
            {/* Header */}
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <h2 className="text-xl font-bold text-slate-900 dark:text-white flex items-center gap-2">
                        <Award className="w-5 h-5 text-amber-500" />
                        Multi-Role Electronic Certificates & Accreditation
                    </h2>
                    <p className="text-sm text-slate-500 dark:text-slate-400">
                        Generate and distribute verified digital certificates with CPD/CME hours and tamper-proof QR codes.
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    <Button
                        variant="primary"
                        size="sm"
                        onClick={() => {
                            setIssueResult(null);
                            setIssueModalOpen(true);
                        }}
                    >
                        <Send className="w-4 h-4 mr-1.5" />
                        Issue Certificates
                    </Button>
                </div>
            </div>

            {/* Metrics */}
            {stats && (
                <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-4 shadow-sm">
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-semibold text-slate-500 uppercase tracking-wider">Total Issued</span>
                            <Award className="w-4 h-4 text-amber-500" />
                        </div>
                        <div className="mt-2 text-2xl font-black text-slate-900 dark:text-white">
                            {stats.total_issued}
                        </div>
                        <p className="mt-1 text-xs text-slate-500">
                            {stats.total_downloads} total PDF downloads
                        </p>
                    </div>

                    <div className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-4 shadow-sm">
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-semibold text-slate-500 uppercase tracking-wider">Delegates</span>
                            <Users className="w-4 h-4 text-blue-500" />
                        </div>
                        <div className="mt-2 text-2xl font-black text-blue-600">
                            {stats.delegates}
                        </div>
                        <p className="mt-1 text-xs text-slate-500">
                            Eligible: {eligible?.checked_in_delegates || 0} checked-in
                        </p>
                    </div>

                    <div className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-4 shadow-sm">
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-semibold text-slate-500 uppercase tracking-wider">Speakers</span>
                            <Mic className="w-4 h-4 text-purple-500" />
                        </div>
                        <div className="mt-2 text-2xl font-black text-purple-600">
                            {stats.speakers}
                        </div>
                        <p className="mt-1 text-xs text-slate-500">
                            Eligible: {eligible?.speakers || 0} event speakers
                        </p>
                    </div>

                    <div className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-4 shadow-sm">
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-semibold text-slate-500 uppercase tracking-wider">Presenters</span>
                            <FileText className="w-4 h-4 text-amber-500" />
                        </div>
                        <div className="mt-2 text-2xl font-black text-amber-600">
                            {stats.presenters}
                        </div>
                        <p className="mt-1 text-xs text-slate-500">
                            Eligible: {eligible?.presenters || 0} abstract authors
                        </p>
                    </div>
                </div>
            )}

            {/* Role Templates Roster */}
            <div className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-4 shadow-xs space-y-4">
                <div className="flex items-center justify-between">
                    <h3 className="text-sm font-bold text-slate-900 dark:text-white flex items-center gap-2">
                        <QrCode className="w-4 h-4 text-indigo-500" />
                        Role-Based Certificate Templates
                    </h3>
                    <span className="text-xs text-slate-500">
                        Configure wording, CPD hours, and issuers for each participant tier
                    </span>
                </div>

                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">
                    {templates.map(tmpl => {
                        const IconComponent = ROLE_ICONS[tmpl.role] || Award;
                        const badge = ROLE_BADGES[tmpl.role] || ROLE_BADGES.custom;

                        return (
                            <div
                                key={tmpl.id}
                                className="border border-slate-200 dark:border-slate-800 rounded-lg p-3.5 bg-slate-50/50 dark:bg-slate-800/40 flex flex-col justify-between hover:border-indigo-400 transition"
                            >
                                <div>
                                    <div className="flex items-center justify-between">
                                        <span className={`text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full ${badge.bg}`}>
                                            {badge.label}
                                        </span>
                                        <IconComponent className="w-4 h-4 text-slate-400" />
                                    </div>
                                    <h4 className="text-xs font-bold text-slate-900 dark:text-white mt-2 line-clamp-1">
                                        {tmpl.title}
                                    </h4>
                                    <p className="text-[11px] text-slate-500 dark:text-slate-400 mt-1 line-clamp-2">
                                        {tmpl.body_template}
                                    </p>
                                    {tmpl.show_cpd_hours && (
                                        <div className="mt-2 text-[10px] font-semibold text-amber-600 dark:text-amber-400 flex items-center gap-1">
                                            <Clock className="w-3 h-3" />
                                            {tmpl.default_cpd_hours} CPD Contact Hours
                                        </div>
                                    )}
                                </div>
                                <div className="mt-3 pt-2.5 border-t border-slate-200 dark:border-slate-700/60 flex items-center justify-between text-[11px]">
                                    <span className="text-slate-400 truncate max-w-[120px]">
                                        {tmpl.issuer_name || 'Convener'}
                                    </span>
                                    <button
                                        onClick={() => openEditTemplate(tmpl)}
                                        className="font-semibold text-indigo-600 dark:text-indigo-400 hover:underline flex items-center gap-1"
                                    >
                                        <Edit3 className="w-3 h-3" />
                                        Customize
                                    </button>
                                </div>
                            </div>
                        );
                    })}
                </div>
            </div>

            {/* Issued Certificates Table */}
            <div className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl overflow-hidden shadow-xs">
                {/* Table Filters */}
                <div className="p-4 border-b border-slate-200 dark:border-slate-800 flex flex-col sm:flex-row items-center justify-between gap-3">
                    <div className="flex items-center gap-2 overflow-x-auto w-full sm:w-auto">
                        <button
                            onClick={() => setRoleFilter('all')}
                            className={`px-3 py-1.5 text-xs font-semibold rounded-lg transition ${
                                roleFilter === 'all'
                                    ? 'bg-slate-900 dark:bg-slate-100 text-white dark:text-slate-900 shadow-sm'
                                    : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300'
                            }`}
                        >
                            All ({stats?.total_issued || 0})
                        </button>
                        {['delegate', 'speaker', 'presenter', 'volunteer'].map(r => (
                            <button
                                key={r}
                                onClick={() => setRoleFilter(r)}
                                className={`px-3 py-1.5 text-xs font-semibold rounded-lg capitalize transition ${
                                    roleFilter === r
                                        ? 'bg-slate-900 dark:bg-slate-100 text-white dark:text-slate-900 shadow-sm'
                                        : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300'
                                }`}
                            >
                                {r}s
                            </button>
                        ))}
                    </div>

                    <div className="flex items-center gap-2 w-full sm:w-auto">
                        <div className="relative w-full sm:w-60">
                            <Search className="w-4 h-4 text-slate-400 absolute left-3 top-2.5" />
                            <input
                                type="text"
                                placeholder="Search recipient or code..."
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                onKeyDown={(e) => e.key === 'Enter' && loadData()}
                                className="text-xs pl-9 pr-3 py-1.5 rounded-lg border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-800 text-slate-900 dark:text-white focus:outline-none focus:ring-1 focus:ring-indigo-500 w-full"
                            />
                        </div>
                        <button
                            onClick={loadData}
                            className="p-1.5 text-slate-500 hover:text-slate-800 dark:hover:text-white"
                            title="Refresh"
                        >
                            <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin' : ''}`} />
                        </button>
                    </div>
                </div>

                {/* Table Content */}
                {loading && certificates.length === 0 ? (
                    <div className="p-12 text-center text-slate-500 text-xs">Loading certificates...</div>
                ) : certificates.length === 0 ? (
                    <div className="p-12 text-center space-y-2">
                        <Award className="w-8 h-8 text-slate-300 mx-auto" />
                        <p className="text-sm font-semibold text-slate-700 dark:text-slate-300">No Certificates Issued Yet</p>
                        <p className="text-xs text-slate-500 max-w-sm mx-auto">
                            Click "Issue Certificates" above to generate accredited credentials for your delegates, speakers, or presenters.
                        </p>
                    </div>
                ) : (
                    <table className="w-full text-left text-xs">
                        <thead className="bg-slate-50 dark:bg-slate-800/70 border-b border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 font-bold uppercase tracking-wider">
                            <tr>
                                <th className="p-3.5">Recipient</th>
                                <th className="p-3.5">Role</th>
                                <th className="p-3.5">Credential ID</th>
                                <th className="p-3.5">CPD Hours</th>
                                <th className="p-3.5">Issued Date</th>
                                <th className="p-3.5 text-center">Downloads</th>
                                <th className="p-3.5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800 text-slate-700 dark:text-slate-300">
                            {certificates.map(cert => {
                                const badge = ROLE_BADGES[cert.role] || ROLE_BADGES.custom;

                                return (
                                    <tr key={cert.id} className="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition">
                                        <td className="p-3.5">
                                            <div className="font-bold text-slate-900 dark:text-white">
                                                {cert.recipient_name}
                                            </div>
                                            <div className="text-[11px] text-slate-500">
                                                {cert.recipient_email}
                                            </div>
                                        </td>
                                        <td className="p-3.5">
                                            <span className={`text-[10px] font-bold px-2 py-0.5 rounded-full ${badge.bg}`}>
                                                {badge.label}
                                            </span>
                                        </td>
                                        <td className="p-3.5 font-mono text-[11px] text-slate-800 dark:text-slate-200">
                                            {cert.verification_code}
                                        </td>
                                        <td className="p-3.5">
                                            {cert.cpd_hours > 0 ? (
                                                <span className="text-[11px] font-semibold text-amber-600 dark:text-amber-400">
                                                    {Number(cert.cpd_hours).toFixed(1)} hrs
                                                </span>
                                            ) : (
                                                <span className="text-slate-400">—</span>
                                            )}
                                        </td>
                                        <td className="p-3.5 text-slate-500">
                                            {cert.issued_at ? cert.issued_at.split('T')[0] : '—'}
                                        </td>
                                        <td className="p-3.5 text-center">
                                            <span className="text-xs bg-slate-100 dark:bg-slate-800 px-2 py-0.5 rounded-full font-semibold">
                                                {cert.download_count}
                                            </span>
                                        </td>
                                        <td className="p-3.5 text-right space-x-2">
                                            <a
                                                href={route('tenant.events.certificates.download', {
                                                    event: event.id,
                                                    certificate: cert.id
                                                })}
                                                className="inline-flex items-center gap-1 text-xs font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-400"
                                                title="Download PDF"
                                            >
                                                <Download className="w-3.5 h-3.5" />
                                                PDF
                                            </a>
                                            <a
                                                href={`/verify/cert/${cert.uuid}`}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="inline-flex items-center gap-1 text-xs font-semibold text-slate-600 hover:text-slate-900 dark:text-slate-400"
                                                title="Open Public Verification Badge"
                                            >
                                                <ExternalLink className="w-3.5 h-3.5" />
                                                Verify
                                            </a>
                                            <button
                                                onClick={() => setDeleteCertTarget(cert)}
                                                className="p-1 text-slate-400 hover:text-rose-600"
                                                title="Revoke Certificate"
                                            >
                                                <Trash2 className="w-3.5 h-3.5" />
                                            </button>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                )}
            </div>

            {/* Template Editor Modal */}
            <Modal
                isOpen={templateModalOpen}
                onClose={() => setTemplateModalOpen(false)}
                title={`Customize ${templateForm.role.toUpperCase()} Certificate Template`}
            >
                <form onSubmit={handleSaveTemplate} className="space-y-4 max-h-[75vh] overflow-y-auto pr-1">
                    <div>
                        <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                            Certificate Title *
                        </label>
                        <Input
                            type="text"
                            required
                            placeholder="e.g. Certificate of Participation"
                            value={templateForm.title}
                            onChange={(e) => setTemplateForm({ ...templateForm, title: e.target.value })}
                        />
                    </div>

                    <div>
                        <div className="flex items-center justify-between mb-1">
                            <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300">
                                Body Template Wording *
                            </label>
                            <span className="text-[10px] text-indigo-600 dark:text-indigo-400">
                                Placeholders: {'{name}'}, {'{event_name}'}, {'{date}'}, {'{hours}'}
                            </span>
                        </div>
                        <textarea
                            rows={4}
                            required
                            className="w-full text-xs rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 p-2.5 text-slate-900 dark:text-white focus:ring-2 focus:ring-indigo-500 focus:outline-none"
                            value={templateForm.body_template}
                            onChange={(e) => setTemplateForm({ ...templateForm, body_template: e.target.value })}
                        />
                    </div>

                    <div className="grid grid-cols-2 gap-3">
                        <div>
                            <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                Signatory / Issuer Name
                            </label>
                            <Input
                                type="text"
                                placeholder="e.g. Prof. Kofi Mensah"
                                value={templateForm.issuer_name}
                                onChange={(e) => setTemplateForm({ ...templateForm, issuer_name: e.target.value })}
                            />
                        </div>
                        <div>
                            <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                Signatory Title
                            </label>
                            <Input
                                type="text"
                                placeholder="e.g. Chair, Academic Scientific Board"
                                value={templateForm.issuer_title}
                                onChange={(e) => setTemplateForm({ ...templateForm, issuer_title: e.target.value })}
                            />
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-3 items-center pt-2 border-t border-slate-100 dark:border-slate-800">
                        <div>
                            <label className="flex items-center gap-2 text-xs font-medium text-slate-700 dark:text-slate-300 cursor-pointer">
                                <Checkbox
                                    checked={templateForm.show_qr}
                                    onChange={(checked) => setTemplateForm({ ...templateForm, show_qr: checked })}
                                />
                                Print Verification QR Code
                            </label>
                        </div>
                        <div>
                            <label className="flex items-center gap-2 text-xs font-medium text-slate-700 dark:text-slate-300 cursor-pointer">
                                <Checkbox
                                    checked={templateForm.show_cpd_hours}
                                    onChange={(checked) => setTemplateForm({ ...templateForm, show_cpd_hours: checked })}
                                />
                                Include CPD/CME Hours Badge
                            </label>
                        </div>
                    </div>

                    {templateForm.show_cpd_hours && (
                        <div>
                            <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                Default CPD / CME Hours
                            </label>
                            <Input
                                type="number"
                                step="0.5"
                                value={templateForm.default_cpd_hours}
                                onChange={(e) => setTemplateForm({ ...templateForm, default_cpd_hours: e.target.value })}
                            />
                        </div>
                    )}

                    <div className="flex justify-end gap-2 pt-3 border-t border-slate-100 dark:border-slate-800">
                        <Button variant="secondary" type="button" onClick={() => setTemplateModalOpen(false)}>
                            Cancel
                        </Button>
                        <Button variant="primary" type="submit" disabled={savingTemplate}>
                            {savingTemplate ? 'Saving...' : 'Save Template'}
                        </Button>
                    </div>
                </form>
            </Modal>

            {/* Bulk Issuance Modal */}
            <Modal
                isOpen={issueModalOpen}
                onClose={() => setIssueModalOpen(false)}
                title="Issue Accredited Certificates"
            >
                <form onSubmit={handleIssueCertificates} className="space-y-4">
                    {issueResult && (
                        <div className="p-3 bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 rounded-lg text-xs text-emerald-800 dark:text-emerald-300 flex items-center gap-2">
                            <CheckCircle2 className="w-4 h-4 flex-shrink-0" />
                            {issueResult}
                        </div>
                    )}

                    <div>
                        <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                            Target Recipient Cohort *
                        </label>
                        <Select
                            value={issueForm.target_group}
                            onChange={(e) => {
                                const tg = e.target.value;
                                const defaultRole = tg === 'speakers' ? 'speaker' : (tg === 'presenters' ? 'presenter' : 'delegate');
                                setIssueForm({
                                    ...issueForm,
                                    target_group: tg,
                                    role: defaultRole,
                                });
                            }}
                        >
                            <option value="checked_in_delegates">
                                Checked-In Attendees Only ({eligible?.checked_in_delegates || 0} eligible)
                            </option>
                            <option value="all_delegates">
                                All Confirmed Attendees ({eligible?.all_delegates || 0} eligible)
                            </option>
                            <option value="speakers">
                                Distinguished Speakers ({eligible?.speakers || 0} eligible)
                            </option>
                            <option value="presenters">
                                Accepted Scientific Abstract Presenters ({eligible?.presenters || 0} eligible)
                            </option>
                            <option value="custom">
                                Custom Individual Entry
                            </option>
                        </Select>
                    </div>

                    {issueForm.target_group === 'custom' && (
                        <div className="grid grid-cols-2 gap-3 p-3 bg-slate-50 dark:bg-slate-800/50 rounded-lg border border-slate-200 dark:border-slate-700">
                            <div>
                                <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                    Recipient Name *
                                </label>
                                <Input
                                    type="text"
                                    required
                                    placeholder="Full Name"
                                    value={issueForm.custom_name}
                                    onChange={(e) => setIssueForm({ ...issueForm, custom_name: e.target.value })}
                                />
                            </div>
                            <div>
                                <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                    Recipient Email *
                                </label>
                                <Input
                                    type="email"
                                    required
                                    placeholder="email@example.com"
                                    value={issueForm.custom_email}
                                    onChange={(e) => setIssueForm({ ...issueForm, custom_email: e.target.value })}
                                />
                            </div>
                        </div>
                    )}

                    <div className="grid grid-cols-2 gap-3">
                        <div>
                            <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                Certificate Role *
                            </label>
                            <Select
                                value={issueForm.role}
                                onChange={(e) => setIssueForm({ ...issueForm, role: e.target.value })}
                            >
                                <option value="delegate">Delegate</option>
                                <option value="speaker">Speaker</option>
                                <option value="presenter">Presenter</option>
                                <option value="volunteer">Volunteer</option>
                                <option value="custom">Custom</option>
                            </Select>
                        </div>
                        <div>
                            <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                CPD Hours
                            </label>
                            <Input
                                type="number"
                                step="0.5"
                                value={issueForm.cpd_hours}
                                onChange={(e) => setIssueForm({ ...issueForm, cpd_hours: e.target.value })}
                            />
                        </div>
                    </div>

                    <div className="flex justify-end gap-2 pt-3 border-t border-slate-100 dark:border-slate-800">
                        <Button variant="secondary" type="button" onClick={() => setIssueModalOpen(false)}>
                            Cancel
                        </Button>
                        <Button variant="primary" type="submit" disabled={issuing}>
                            {issuing ? 'Issuing...' : 'Generate & Issue'}
                        </Button>
                    </div>
                </form>
            </Modal>

            {/* Confirm Revoke Modal */}
            <ConfirmModal
                isOpen={!!deleteCertTarget}
                title="Revoke Certificate?"
                message={`Are you sure you want to revoke the certificate for ${deleteCertTarget?.recipient_name} (${deleteCertTarget?.verification_code})? The QR code and verification link will no longer be valid.`}
                confirmLabel="Revoke Certificate"
                variant="danger"
                onConfirm={handleDeleteCertificate}
                onCancel={() => setDeleteCertTarget(null)}
            />
        </div>
    );
}
