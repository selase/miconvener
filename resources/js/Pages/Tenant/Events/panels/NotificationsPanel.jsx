import { useState, useEffect } from 'react';
import { 
    Bell, 
    Plus, 
    Mail, 
    MessageSquare, 
    Phone, 
    Clock, 
    ShieldCheck, 
    Send, 
    CheckCircle2, 
    AlertCircle, 
    Trash2, 
    Edit3, 
    ToggleLeft, 
    ToggleRight, 
    Settings, 
    Sparkles, 
    Play, 
    Users,
    ChevronRight,
    Zap
} from 'lucide-react';

export default function NotificationsPanel({ event }) {
    const [rules, setRules] = useState([]);
    const [audiences, setAudiences] = useState([]);
    const [settings, setSettings] = useState(null);
    const [recentLogs, setRecentLogs] = useState([]);
    const [presets, setPresets] = useState([]);
    const [loading, setLoading] = useState(true);

    const [isRuleModalOpen, setIsRuleModalOpen] = useState(false);
    const [isSettingsModalOpen, setIsSettingsModalOpen] = useState(false);
    const [isTestModalOpen, setIsTestModalOpen] = useState(false);
    const [editingRule, setEditingRule] = useState(null);

    const [ruleForm, setRuleForm] = useState({
        name: '',
        target_role: 'attendee',
        target_audience: 'all',
        trigger_type: 'scheduled_offset',
        offset_direction: 'before',
        offset_amount: 1,
        offset_unit: 'days',
        channels: ['email'],
        subject: '',
        body_template: '',
        is_active: true,
    });

    const [settingsForm, setSettingsForm] = useState({
        sms_enabled: false,
        whatsapp_enabled: false,
        overage_billing_enabled: false,
        anti_abuse_cooldown_minutes: 60,
    });

    const [testForm, setTestForm] = useState({
        channels: ['email'],
        subject: 'Test Conference Reminder',
        body_template: 'Hello {name},\n\nThis is a live test notification for {event_name} at {venue}.\n\nTicket Code: {ticket_code}',
    });

    const [actionMessage, setActionMessage] = useState('');
    const [actionError, setActionError] = useState('');

    const fetchRules = async () => {
        try {
            setLoading(true);
            const res = await fetch(route('tenant.events.notification-rules.index', { event: event.id }));
            const data = await res.json();
            setRules(data.rules || []);
            setAudiences(data.audiences || []);
            setSettings(data.settings || null);
            setRecentLogs(data.recent_logs || []);
            setPresets(data.presets || []);
            if (data.settings) {
                setSettingsForm({
                    sms_enabled: data.settings.sms_enabled,
                    whatsapp_enabled: data.settings.whatsapp_enabled,
                    overage_billing_enabled: data.settings.overage_billing_enabled,
                    anti_abuse_cooldown_minutes: data.settings.anti_abuse_cooldown_minutes,
                });
            }
        } catch (err) {
            console.error(err);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchRules();
    }, [event.id]);

    const handleOpenCreateModal = (preset = null) => {
        if (preset) {
            setRuleForm({
                ...preset,
                is_active: true,
            });
        } else {
            setRuleForm({
                name: '',
                target_role: 'attendee',
                target_audience: 'all',
                trigger_type: 'scheduled_offset',
                offset_direction: 'before',
                offset_amount: 1,
                offset_unit: 'days',
                channels: ['email'],
                subject: `Reminder: ${event.name}`,
                body_template: `Dear {name},\n\nThis is an automated notification regarding {event_name} on {date} at {venue}.\n\nYour Ticket Code: {ticket_code}`,
                is_active: true,
            });
        }
        setEditingRule(null);
        setIsRuleModalOpen(true);
    };

    const handleOpenEditModal = (rule) => {
        setEditingRule(rule);
        setRuleForm({
            name: rule.name,
            target_role: rule.target_role,
            target_audience: rule.target_audience,
            trigger_type: rule.trigger_type,
            offset_direction: rule.offset_direction,
            offset_amount: rule.offset_amount,
            offset_unit: rule.offset_unit,
            channels: rule.channels || ['email'],
            subject: rule.subject,
            body_template: rule.body_template,
            is_active: rule.is_active,
        });
        setIsRuleModalOpen(true);
    };

    const handleSaveRule = async (e) => {
        e.preventDefault();
        setActionMessage('');
        setActionError('');

        const url = editingRule
            ? route('tenant.events.notification-rules.update', { event: event.id, rule: editingRule.id })
            : route('tenant.events.notification-rules.store', { event: event.id });
        const method = editingRule ? 'PUT' : 'POST';

        try {
            const res = await fetch(url, {
                method,
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
                body: JSON.stringify(ruleForm),
            });
            const data = await res.json();
            if (res.ok) {
                setActionMessage(data.message || 'Rule saved successfully.');
                setIsRuleModalOpen(false);
                fetchRules();
            } else {
                setActionError(data.message || 'Error saving rule.');
            }
        } catch (err) {
            setActionError('Network error saving notification rule.');
        }
    };

    const handleToggleRule = async (ruleId) => {
        try {
            const res = await fetch(route('tenant.events.notification-rules.toggle', { event: event.id, rule: ruleId }), {
                method: 'PATCH',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
            });
            if (res.ok) fetchRules();
        } catch (err) {
            console.error(err);
        }
    };

    const handleDeleteRule = async (ruleId) => {
        if (!confirm('Are you sure you want to delete this notification rule?')) return;
        try {
            const res = await fetch(route('tenant.events.notification-rules.destroy', { event: event.id, rule: ruleId }), {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
            });
            if (res.ok) fetchRules();
        } catch (err) {
            console.error(err);
        }
    };

    const handleDispatchNow = async (ruleId) => {
        if (!confirm('Dispatch this notification immediately to all matching recipients?')) return;
        try {
            const res = await fetch(route('tenant.events.notification-rules.dispatch', { event: event.id, rule: ruleId }), {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
            });
            const data = await res.json();
            if (res.ok) {
                setActionMessage(data.message);
                fetchRules();
            } else {
                setActionError(data.message || 'Dispatch failed.');
            }
        } catch (err) {
            setActionError('Network error dispatching rule.');
        }
    };

    const handleSendTest = async (e) => {
        e.preventDefault();
        try {
            const res = await fetch(route('tenant.events.notification-rules.test-send', { event: event.id }), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
                body: JSON.stringify(testForm),
            });
            const data = await res.json();
            if (res.ok) {
                setActionMessage('Test notification sent to your profile email/phone.');
                setIsTestModalOpen(false);
                fetchRules();
            } else {
                setActionError(data.message || 'Test send failed.');
            }
        } catch (err) {
            setActionError('Failed to send test notification.');
        }
    };

    const handleSaveSettings = async (e) => {
        e.preventDefault();
        try {
            const res = await fetch(route('tenant.events.notification-settings.update', { event: event.id }), {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
                body: JSON.stringify(settingsForm),
            });
            const data = await res.json();
            if (res.ok) {
                setActionMessage(data.message);
                setIsSettingsModalOpen(false);
                fetchRules();
            }
        } catch (err) {
            setActionError('Failed to update notification settings.');
        }
    };

    const insertVariable = (tag) => {
        setRuleForm(prev => ({
            ...prev,
            body_template: prev.body_template + ' ' + tag,
        }));
    };

    const toggleChannel = (channel) => {
        setRuleForm(prev => {
            const current = prev.channels || [];
            const next = current.includes(channel)
                ? current.filter(c => c !== channel)
                : [...current, channel];
            return { ...prev, channels: next.length > 0 ? next : ['email'] };
        });
    };

    const emailUsed = settings?.email_used_this_month || 0;
    const emailLimit = settings?.email_monthly_limit || 2500;
    const emailPct = Math.min(100, Math.round((emailUsed / emailLimit) * 100));

    return (
        <div className="space-y-6">
            {/* Action Banners */}
            {actionMessage && (
                <div className="p-4 rounded-xl bg-emerald-50 dark:bg-emerald-950/50 border border-emerald-200 dark:border-emerald-800 text-emerald-800 dark:text-emerald-300 text-sm flex items-center justify-between">
                    <span className="flex items-center gap-2 font-medium">
                        <CheckCircle2 className="w-4 h-4 text-emerald-600" />
                        {actionMessage}
                    </span>
                    <button onClick={() => setActionMessage('')} className="text-xs hover:underline">Dismiss</button>
                </div>
            )}
            {actionError && (
                <div className="p-4 rounded-xl bg-red-50 dark:bg-red-950/50 border border-red-200 dark:border-red-800 text-red-800 dark:text-red-300 text-sm flex items-center justify-between">
                    <span className="flex items-center gap-2 font-medium">
                        <AlertCircle className="w-4 h-4 text-red-600" />
                        {actionError}
                    </span>
                    <button onClick={() => setActionError('')} className="text-xs hover:underline">Dismiss</button>
                </div>
            )}

            {/* Top Bar: Metric Cards & Settings */}
            <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                {/* Monthly Quota Meter */}
                <div className="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-4 shadow-sm">
                    <div className="flex items-center justify-between text-xs text-slate-500 dark:text-slate-400 font-semibold mb-1">
                        <span>Direct Email Quota</span>
                        <span>{emailPct}%</span>
                    </div>
                    <div className="text-2xl font-bold text-slate-900 dark:text-white">
                        {emailUsed.toLocaleString()} <span className="text-xs font-normal text-slate-400">/ {emailLimit.toLocaleString()} free</span>
                    </div>
                    <div className="w-full bg-slate-100 dark:bg-slate-800 rounded-full h-2 mt-2.5 overflow-hidden">
                        <div 
                            className={`h-full rounded-full transition-all ${emailPct > 90 ? 'bg-red-500' : emailPct > 70 ? 'bg-amber-500' : 'bg-indigo-600'}`}
                            style={{ width: `${emailPct}%` }}
                        />
                    </div>
                </div>

                {/* Channel Integrations */}
                <div className="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-4 shadow-sm">
                    <div className="text-xs text-slate-500 dark:text-slate-400 font-semibold mb-1">Channels & Omnichannel</div>
                    <div className="flex items-center gap-2 mt-1">
                        <span className="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded bg-emerald-50 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800 font-medium">
                            <Mail className="w-3 h-3" /> Email
                        </span>
                        <span className={`inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded border font-medium ${settings?.sms_enabled ? 'bg-emerald-50 dark:bg-emerald-950/60 text-emerald-700 border-emerald-200' : 'bg-slate-50 dark:bg-slate-800 text-slate-400 border-slate-200 dark:border-slate-700'}`}>
                            <Phone className="w-3 h-3" /> SMS
                        </span>
                        <span className={`inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded border font-medium ${settings?.whatsapp_enabled ? 'bg-emerald-50 dark:bg-emerald-950/60 text-emerald-700 border-emerald-200' : 'bg-slate-50 dark:bg-slate-800 text-slate-400 border-slate-200 dark:border-slate-700'}`}>
                            <MessageSquare className="w-3 h-3" /> WhatsApp
                        </span>
                    </div>
                    <p className="text-[11px] text-slate-400 mt-2 flex items-center gap-1">
                        <Sparkles className="w-3 h-3 text-indigo-500" /> Staged for Omnichannel Gateway
                    </p>
                </div>

                {/* Anti-Abuse Guardrails */}
                <div className="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-4 shadow-sm">
                    <div className="text-xs text-slate-500 dark:text-slate-400 font-semibold mb-1">Anti-Abuse Guardrails</div>
                    <div className="text-lg font-bold text-slate-900 dark:text-white flex items-center gap-1.5 mt-0.5">
                        <ShieldCheck className="w-5 h-5 text-indigo-600" /> Active Protection
                    </div>
                    <div className="text-[11px] text-slate-500 dark:text-slate-400 mt-1">
                        {settings?.anti_abuse_cooldown_minutes || 60}m frequency cooldown per attendee
                    </div>
                </div>

                {/* Quick Controls */}
                <div className="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-4 shadow-sm flex flex-col justify-between">
                    <div className="text-xs text-slate-500 dark:text-slate-400 font-semibold">Channel Configuration</div>
                    <div className="flex items-center gap-2 mt-2">
                        <button
                            type="button"
                            onClick={() => setIsTestModalOpen(true)}
                            className="flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800 text-xs font-semibold text-slate-700 dark:text-slate-200 transition-all cursor-pointer"
                        >
                            <Send className="w-3 h-3" /> Send Test
                        </button>
                        <button
                            type="button"
                            onClick={() => setIsSettingsModalOpen(true)}
                            className="inline-flex items-center justify-center p-1.5 rounded-lg border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800 text-slate-600 dark:text-slate-300 transition-all cursor-pointer"
                            title="Notification & Billing Settings"
                        >
                            <Settings className="w-4 h-4" />
                        </button>
                    </div>
                </div>
            </div>

            {/* Quick Presets Carousel / Cards */}
            <div>
                <div className="flex items-center justify-between mb-3">
                    <h3 className="text-sm font-bold text-slate-900 dark:text-white flex items-center gap-1.5">
                        <Zap className="w-4 h-4 text-amber-500" /> Recommended Notification Presets
                    </h3>
                    <button
                        type="button"
                        onClick={() => handleOpenCreateModal()}
                        className="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold shadow-sm transition-all cursor-pointer"
                    >
                        <Plus className="w-3.5 h-3.5" /> Create Custom Rule
                    </button>
                </div>
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    {presets.map((preset, idx) => (
                        <div
                            key={idx}
                            className="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-4 hover:border-indigo-300 dark:hover:border-indigo-700 transition-all shadow-sm flex flex-col justify-between"
                        >
                            <div>
                                <span className="text-[10px] font-bold uppercase tracking-wider text-indigo-600 dark:text-indigo-400 block mb-1">
                                    {preset.offset_amount} {preset.offset_unit} {preset.offset_direction}
                                </span>
                                <h4 className="text-xs font-bold text-slate-900 dark:text-white mb-1 leading-snug">
                                    {preset.name}
                                </h4>
                                <p className="text-[11px] text-slate-500 dark:text-slate-400 line-clamp-2">
                                    {preset.subject}
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => handleOpenCreateModal(preset)}
                                className="mt-3 w-full py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 hover:bg-indigo-50 dark:hover:bg-indigo-950/50 hover:text-indigo-600 text-slate-600 dark:text-slate-300 text-xs font-semibold transition-all flex items-center justify-center gap-1 cursor-pointer"
                            >
                                Deploy Preset <ChevronRight className="w-3 h-3" />
                            </button>
                        </div>
                    ))}
                </div>
            </div>

            {/* Active Automated Rules Table */}
            <div className="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden">
                <div className="p-4 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                    <h3 className="text-sm font-bold text-slate-900 dark:text-white flex items-center gap-2">
                        <Bell className="w-4 h-4 text-indigo-600" />
                        Active Automated Campaigns ({rules.length})
                    </h3>
                </div>

                {rules.length === 0 ? (
                    <div className="p-12 text-center text-sm text-slate-500 dark:text-slate-400">
                        <Bell className="w-10 h-10 mx-auto text-slate-300 dark:text-slate-600 mb-3" />
                        <p className="font-semibold text-slate-800 dark:text-slate-200">No automated rules configured yet</p>
                        <p className="text-xs mt-1 max-w-sm mx-auto">
                            Deploy one of the recommended presets above or create a custom trigger to automate attendee communications.
                        </p>
                    </div>
                ) : (
                    <div className="divide-y divide-slate-100 dark:divide-slate-800">
                        {rules.map((rule) => (
                            <div key={rule.id} className="p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3 hover:bg-slate-50/50 dark:hover:bg-slate-800/30 transition-all">
                                <div className="space-y-1">
                                    <div className="flex items-center gap-2 flex-wrap">
                                        <h4 className="text-sm font-bold text-slate-900 dark:text-white">
                                            {rule.name}
                                        </h4>
                                        <span className={`px-2 py-0.5 rounded text-[11px] font-semibold border ${
                                            rule.is_active 
                                                ? 'bg-emerald-50 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-400 border-emerald-200 dark:border-emerald-800'
                                                : 'bg-slate-100 dark:bg-slate-800 text-slate-500 border-slate-200 dark:border-slate-700'
                                        }`}>
                                            {rule.is_active ? 'Active' : 'Paused'}
                                        </span>
                                        <span className="text-xs text-slate-500 dark:text-slate-400 flex items-center gap-1">
                                            <Clock className="w-3 h-3" />
                                            {rule.offset_amount} {rule.offset_unit} {rule.offset_direction}
                                        </span>
                                    </div>
                                    <p className="text-xs text-slate-600 dark:text-slate-300 line-clamp-1">
                                        <span className="font-semibold text-slate-700 dark:text-slate-200">Subject:</span> {rule.subject}
                                    </p>
                                    <div className="flex items-center gap-2 text-[11px] text-slate-400 flex-wrap pt-0.5">
                                        <span className="flex items-center gap-1 text-slate-500 dark:text-slate-400">
                                            <Users className="w-3 h-3" /> Target: <strong className="text-slate-700 dark:text-slate-300 font-semibold">{rule.target_audience}</strong>
                                        </span>
                                        &bull;
                                        <span>Channels: {(rule.channels || []).join(', ').toUpperCase()}</span>
                                        {rule.last_dispatched_at && (
                                            <>
                                                &bull;
                                                <span>Last sent: {new Date(rule.last_dispatched_at).toLocaleString()}</span>
                                            </>
                                        )}
                                    </div>
                                </div>

                                <div className="flex items-center gap-1.5 self-end sm:self-center">
                                    <button
                                        type="button"
                                        onClick={() => handleDispatchNow(rule.id)}
                                        className="px-2.5 py-1.5 rounded-lg border border-indigo-200 dark:border-indigo-800/80 bg-indigo-50 dark:bg-indigo-950/50 hover:bg-indigo-100 text-indigo-700 dark:text-indigo-300 text-xs font-semibold transition-all flex items-center gap-1 cursor-pointer"
                                        title="Dispatch campaign immediately"
                                    >
                                        <Play className="w-3 h-3" /> Send Now
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => handleToggleRule(rule.id)}
                                        className="p-1.5 rounded-lg border border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-600 dark:text-slate-300 transition-all cursor-pointer"
                                        title={rule.is_active ? 'Pause Rule' : 'Activate Rule'}
                                    >
                                        {rule.is_active ? <ToggleRight className="w-4 h-4 text-emerald-600" /> : <ToggleLeft className="w-4 h-4 text-slate-400" />}
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => handleOpenEditModal(rule)}
                                        className="p-1.5 rounded-lg border border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-600 dark:text-slate-300 transition-all cursor-pointer"
                                        title="Edit Rule"
                                    >
                                        <Edit3 className="w-4 h-4" />
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => handleDeleteRule(rule.id)}
                                        className="p-1.5 rounded-lg border border-slate-200 dark:border-slate-700 hover:bg-red-50 dark:hover:bg-red-950/50 text-red-600 transition-all cursor-pointer"
                                        title="Delete Rule"
                                    >
                                        <Trash2 className="w-4 h-4" />
                                    </button>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>

            {/* Create / Edit Rule Modal */}
            {isRuleModalOpen && (
                <div className="fixed inset-0 z-50 bg-black/50 backdrop-blur-xs flex items-center justify-center p-4 overflow-y-auto">
                    <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 max-w-2xl w-full p-6 shadow-2xl space-y-4 my-8 max-h-[90vh] overflow-y-auto">
                        <div className="flex items-center justify-between border-b border-slate-200 dark:border-slate-800 pb-3">
                            <h3 className="text-base font-bold text-slate-900 dark:text-white">
                                {editingRule ? 'Edit Notification Rule' : 'Create Automated Notification Rule'}
                            </h3>
                            <button onClick={() => setIsRuleModalOpen(false)} className="text-slate-400 hover:text-slate-600 font-bold text-lg">&times;</button>
                        </div>

                        <form onSubmit={handleSaveRule} className="space-y-4">
                            <div>
                                <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                    Campaign Name
                                </label>
                                <input
                                    type="text"
                                    value={ruleForm.name}
                                    onChange={(e) => setRuleForm({ ...ruleForm, name: e.target.value })}
                                    required
                                    placeholder="e.g. 7-Day Pre-Event Preparation"
                                    className="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none"
                                />
                            </div>

                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                        Target Audience
                                    </label>
                                    <select
                                        value={ruleForm.target_audience}
                                        onChange={(e) => {
                                            const aud = audiences.find(a => a.key === e.target.value);
                                            setRuleForm({
                                                ...ruleForm,
                                                target_audience: e.target.value,
                                                target_role: aud?.role || 'attendee',
                                            });
                                        }}
                                        className="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none"
                                    >
                                        {audiences.map((aud) => (
                                            <option key={aud.key} value={aud.key}>
                                                {aud.label}
                                            </option>
                                        ))}
                                    </select>
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                        Dispatch Channels
                                    </label>
                                    <div className="flex items-center gap-3 pt-1.5">
                                        <label className="flex items-center gap-1.5 text-xs font-medium cursor-pointer">
                                            <input
                                                type="checkbox"
                                                checked={ruleForm.channels.includes('email')}
                                                onChange={() => toggleChannel('email')}
                                                className="rounded text-indigo-600 focus:ring-indigo-500"
                                            />
                                            Email
                                        </label>
                                        <label className="flex items-center gap-1.5 text-xs font-medium cursor-pointer">
                                            <input
                                                type="checkbox"
                                                checked={ruleForm.channels.includes('sms')}
                                                onChange={() => toggleChannel('sms')}
                                                className="rounded text-indigo-600 focus:ring-indigo-500"
                                            />
                                            SMS
                                        </label>
                                        <label className="flex items-center gap-1.5 text-xs font-medium cursor-pointer">
                                            <input
                                                type="checkbox"
                                                checked={ruleForm.channels.includes('whatsapp')}
                                                onChange={() => toggleChannel('whatsapp')}
                                                className="rounded text-indigo-600 focus:ring-indigo-500"
                                            />
                                            WhatsApp
                                        </label>
                                    </div>
                                </div>
                            </div>

                            {/* Schedule Timing Offset */}
                            <div className="p-3.5 bg-slate-50 dark:bg-slate-800/60 rounded-xl border border-slate-200 dark:border-slate-700/60 space-y-2">
                                <label className="block text-xs font-bold text-slate-700 dark:text-slate-300">
                                    Trigger Timing
                                </label>
                                <div className="grid grid-cols-3 gap-2">
                                    <input
                                        type="number"
                                        min="0"
                                        value={ruleForm.offset_amount}
                                        onChange={(e) => setRuleForm({ ...ruleForm, offset_amount: parseInt(e.target.value) || 0 })}
                                        className="px-3 py-1.5 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm focus:outline-none"
                                    />
                                    <select
                                        value={ruleForm.offset_unit}
                                        onChange={(e) => setRuleForm({ ...ruleForm, offset_unit: e.target.value })}
                                        className="px-3 py-1.5 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm focus:outline-none"
                                    >
                                        <option value="days">Days</option>
                                        <option value="hours">Hours</option>
                                        <option value="minutes">Minutes</option>
                                    </select>
                                    <select
                                        value={ruleForm.offset_direction}
                                        onChange={(e) => setRuleForm({ ...ruleForm, offset_direction: e.target.value })}
                                        className="px-3 py-1.5 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm focus:outline-none"
                                    >
                                        <option value="before">Before Event</option>
                                        <option value="after">After Event</option>
                                    </select>
                                </div>
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                    Email Subject / Headline
                                </label>
                                <input
                                    type="text"
                                    value={ruleForm.subject}
                                    onChange={(e) => setRuleForm({ ...ruleForm, subject: e.target.value })}
                                    required
                                    placeholder="Important conference update..."
                                    className="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none"
                                />
                            </div>

                            <div>
                                <div className="flex items-center justify-between mb-1">
                                    <label className="text-xs font-semibold text-slate-700 dark:text-slate-300">
                                        Message Template Body
                                    </label>
                                    <div className="flex items-center gap-1 text-[11px] text-slate-400">
                                        Insert: 
                                        {['{name}', '{event_name}', '{venue}', '{date}', '{ticket_code}'].map(tag => (
                                            <button
                                                key={tag}
                                                type="button"
                                                onClick={() => insertVariable(tag)}
                                                className="px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 text-indigo-600 font-mono text-[10px] cursor-pointer"
                                            >
                                                {tag}
                                            </button>
                                        ))}
                                    </div>
                                </div>
                                <textarea
                                    rows={5}
                                    value={ruleForm.body_template}
                                    onChange={(e) => setRuleForm({ ...ruleForm, body_template: e.target.value })}
                                    required
                                    className="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm font-mono focus:ring-2 focus:ring-indigo-500 focus:outline-none"
                                />
                            </div>

                            <div className="flex justify-end gap-2 pt-2 border-t border-slate-200 dark:border-slate-800">
                                <button
                                    type="button"
                                    onClick={() => setIsRuleModalOpen(false)}
                                    className="px-4 py-2 rounded-xl border border-slate-300 dark:border-slate-700 text-xs font-semibold text-slate-700 dark:text-slate-300"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    className="px-5 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold shadow-sm"
                                >
                                    {editingRule ? 'Update Campaign Rule' : 'Save & Activate Campaign'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* Test Send Modal */}
            {isTestModalOpen && (
                <div className="fixed inset-0 z-50 bg-black/50 backdrop-blur-xs flex items-center justify-center p-4">
                    <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 max-w-md w-full p-6 shadow-2xl space-y-4">
                        <div className="flex items-center justify-between border-b border-slate-200 dark:border-slate-800 pb-3">
                            <h3 className="text-base font-bold text-slate-900 dark:text-white">
                                Dispatch Test Notification
                            </h3>
                            <button onClick={() => setIsTestModalOpen(false)} className="text-slate-400 hover:text-slate-600 font-bold text-lg">&times;</button>
                        </div>
                        <p className="text-xs text-slate-500 dark:text-slate-400">
                            Dispatches a test message with interpolated sample data directly to your administrator email/phone.
                        </p>

                        <form onSubmit={handleSendTest} className="space-y-3">
                            <div>
                                <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                    Subject
                                </label>
                                <input
                                    type="text"
                                    value={testForm.subject}
                                    onChange={(e) => setTestForm({ ...testForm, subject: e.target.value })}
                                    required
                                    className="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm focus:outline-none"
                                />
                            </div>
                            <div>
                                <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                    Message Body
                                </label>
                                <textarea
                                    rows={4}
                                    value={testForm.body_template}
                                    onChange={(e) => setTestForm({ ...testForm, body_template: e.target.value })}
                                    required
                                    className="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm font-mono focus:outline-none"
                                />
                            </div>
                            <div className="flex justify-end gap-2 pt-2">
                                <button
                                    type="button"
                                    onClick={() => setIsTestModalOpen(false)}
                                    className="px-4 py-2 rounded-xl border border-slate-300 dark:border-slate-700 text-xs font-semibold text-slate-700 dark:text-slate-300"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    className="px-5 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold shadow-sm"
                                >
                                    Send Live Test
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* Tenant Notification & Billing Settings Modal */}
            {isSettingsModalOpen && (
                <div className="fixed inset-0 z-50 bg-black/50 backdrop-blur-xs flex items-center justify-center p-4">
                    <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 max-w-md w-full p-6 shadow-2xl space-y-4">
                        <div className="flex items-center justify-between border-b border-slate-200 dark:border-slate-800 pb-3">
                            <h3 className="text-base font-bold text-slate-900 dark:text-white">
                                Notification Channel & Billing Settings
                            </h3>
                            <button onClick={() => setIsSettingsModalOpen(false)} className="text-slate-400 hover:text-slate-600 font-bold text-lg">&times;</button>
                        </div>

                        <form onSubmit={handleSaveSettings} className="space-y-4">
                            <div className="space-y-3">
                                <label className="flex items-center justify-between p-3 rounded-xl border border-slate-200 dark:border-slate-800">
                                    <div>
                                        <div className="text-xs font-bold text-slate-900 dark:text-white">Enable SMS Staging</div>
                                        <div className="text-[11px] text-slate-500">Dispatch SMS alerts staged for Omnichannel Gateway</div>
                                    </div>
                                    <input
                                        type="checkbox"
                                        checked={settingsForm.sms_enabled}
                                        onChange={(e) => setSettingsForm({ ...settingsForm, sms_enabled: e.target.checked })}
                                        className="rounded text-indigo-600 focus:ring-indigo-500 h-4 w-4"
                                    />
                                </label>

                                <label className="flex items-center justify-between p-3 rounded-xl border border-slate-200 dark:border-slate-800">
                                    <div>
                                        <div className="text-xs font-bold text-slate-900 dark:text-white">Enable WhatsApp Staging</div>
                                        <div className="text-[11px] text-slate-500">Dispatch WhatsApp alerts staged for Omnichannel Gateway</div>
                                    </div>
                                    <input
                                        type="checkbox"
                                        checked={settingsForm.whatsapp_enabled}
                                        onChange={(e) => setSettingsForm({ ...settingsForm, whatsapp_enabled: e.target.checked })}
                                        className="rounded text-indigo-600 focus:ring-indigo-500 h-4 w-4"
                                    />
                                </label>

                                <label className="flex items-center justify-between p-3 rounded-xl border border-slate-200 dark:border-slate-800">
                                    <div>
                                        <div className="text-xs font-bold text-slate-900 dark:text-white">Email Overage Billing</div>
                                        <div className="text-[11px] text-slate-500">Permit sends beyond 2,500 monthly limit billed to ledger</div>
                                    </div>
                                    <input
                                        type="checkbox"
                                        checked={settingsForm.overage_billing_enabled}
                                        onChange={(e) => setSettingsForm({ ...settingsForm, overage_billing_enabled: e.target.checked })}
                                        className="rounded text-indigo-600 focus:ring-indigo-500 h-4 w-4"
                                    />
                                </label>

                                <div>
                                    <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                        Anti-Abuse Cooldown (Minutes)
                                    </label>
                                    <input
                                        type="number"
                                        min="5"
                                        max="1440"
                                        value={settingsForm.anti_abuse_cooldown_minutes}
                                        onChange={(e) => setSettingsForm({ ...settingsForm, anti_abuse_cooldown_minutes: parseInt(e.target.value) || 60 })}
                                        className="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm focus:outline-none"
                                    />
                                    <p className="text-[11px] text-slate-400 mt-1">Prevents repeated duplicate notifications to the same attendee.</p>
                                </div>
                            </div>

                            <div className="flex justify-end gap-2 pt-2 border-t border-slate-200 dark:border-slate-800">
                                <button
                                    type="button"
                                    onClick={() => setIsSettingsModalOpen(false)}
                                    className="px-4 py-2 rounded-xl border border-slate-300 dark:border-slate-700 text-xs font-semibold text-slate-700 dark:text-slate-300"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    className="px-5 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold shadow-sm"
                                >
                                    Save Preferences
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </div>
    );
}
