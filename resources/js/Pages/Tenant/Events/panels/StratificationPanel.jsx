import { useState, useEffect } from 'react';
import Button from '@/Components/Console/Button';
import Modal from '@/Components/Console/Modal';
import Input from '@/Components/Console/Input';
import Select from '@/Components/Console/Select';
import ConfirmModal from '@/Components/Console/ConfirmModal';
import csrfFetch from '@/lib/csrfFetch';
import {
    Users,
    Filter,
    Plus,
    RefreshCw,
    Download,
    Trash2,
    Edit2,
    CheckCircle2,
    Clock,
    X,
    UserCheck,
    Pin,
    Search,
    ChevronRight,
    SlidersHorizontal,
    Sparkles,
    AlertCircle
} from 'lucide-react';

const RULE_TYPES = [
    { value: 'ticket_type', label: 'Ticket / Delegate Type' },
    { value: 'status', label: 'Registration / Attendance Status' },
    { value: 'cme_hours', label: 'Accredited CPD / CME Hours' },
    { value: 'session_attendance', label: 'Breakout Session / Room Headcount' },
    { value: 'form_answer', label: 'Dynamic Form Response Answer' },
];

export default function StratificationPanel({ event }) {
    const [loading, setLoading] = useState(true);
    const [groups, setGroups] = useState([]);
    const [meta, setMeta] = useState({ ticket_types: [], sessions: [], forms: [], total_attendees: 0 });

    // Group Create/Edit Modal
    const [modalOpen, setModalOpen] = useState(false);
    const [editingGroup, setEditingGroup] = useState(null);
    const [saving, setSaving] = useState(false);
    const [deleteTarget, setDeleteTarget] = useState(null);

    // Group Form State
    const [formData, setFormData] = useState({
        name: '',
        description: '',
        color: '#6366F1',
        icon: 'users',
        type: 'dynamic',
    });
    const [criteriaRules, setCriteriaRules] = useState([]);

    // Roster Drawer/Modal
    const [rosterModalOpen, setRosterModalOpen] = useState(false);
    const [selectedGroup, setSelectedGroup] = useState(null);
    const [members, setMembers] = useState([]);
    const [loadingMembers, setLoadingMembers] = useState(false);
    const [syncingGroupId, setSyncingGroupId] = useState(null);

    const loadData = async () => {
        setLoading(true);
        try {
            const res = await csrfFetch(route('tenant.events.participant-groups.index', { event: event.id }));
            if (res.ok) {
                const data = await res.json();
                setGroups(data.groups || []);
                setMeta(data.meta || { ticket_types: [], sessions: [], forms: [], total_attendees: 0 });
            }
        } catch (err) {
            console.error('Failed to load participant groups:', err);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        loadData();
    }, [event.id]);

    const openCreateModal = () => {
        setEditingGroup(null);
        setFormData({
            name: '',
            description: '',
            color: '#6366F1',
            icon: 'users',
            type: 'dynamic',
        });
        setCriteriaRules([
            { type: 'status', operator: 'is', value: 'checked_in' },
        ]);
        setModalOpen(true);
    };

    const openEditModal = (group) => {
        setEditingGroup(group);
        setFormData({
            name: group.name,
            description: group.description || '',
            color: group.color || '#6366F1',
            icon: group.icon || 'users',
            type: group.type,
        });
        setCriteriaRules(group.criteria || []);
        setModalOpen(true);
    };

    const addRule = () => {
        setCriteriaRules([
            ...criteriaRules,
            { type: 'ticket_type', operator: 'is', value: meta.ticket_types[0]?.id || '' }
        ]);
    };

    const updateRule = (idx, patch) => {
        const updated = [...criteriaRules];
        updated[idx] = { ...updated[idx], ...patch };
        setCriteriaRules(updated);
    };

    const removeRule = (idx) => {
        setCriteriaRules(criteriaRules.filter((_, i) => i !== idx));
    };

    const handleSaveGroup = async (e) => {
        e.preventDefault();
        setSaving(true);
        try {
            const url = editingGroup
                ? route('tenant.events.participant-groups.update', { event: event.id, group: editingGroup.id })
                : route('tenant.events.participant-groups.store', { event: event.id });
            const method = editingGroup ? 'PUT' : 'POST';

            const payload = {
                ...formData,
                criteria: formData.type === 'dynamic' ? criteriaRules : [],
            };

            const res = await csrfFetch(url, {
                method,
                body: JSON.stringify(payload),
            });

            if (res.ok) {
                setModalOpen(false);
                setEditingGroup(null);
                await loadData();
            }
        } catch (err) {
            console.error('Failed to save group:', err);
        } finally {
            setSaving(false);
        }
    };

    const handleSync = async (group) => {
        setSyncingGroupId(group.id);
        try {
            const res = await csrfFetch(route('tenant.events.participant-groups.sync', {
                event: event.id,
                group: group.id,
            }), { method: 'POST' });
            if (res.ok) {
                await loadData();
            }
        } catch (err) {
            console.error('Failed to sync group:', err);
        } finally {
            setSyncingGroupId(null);
        }
    };

    const handleDeleteGroup = async () => {
        if (!deleteTarget) return;
        try {
            const res = await csrfFetch(route('tenant.events.participant-groups.destroy', {
                event: event.id,
                group: deleteTarget.id,
            }), { method: 'DELETE' });
            if (res.ok) {
                setDeleteTarget(null);
                await loadData();
            }
        } catch (err) {
            console.error('Failed to delete group:', err);
        }
    };

    const openRoster = async (group) => {
        setSelectedGroup(group);
        setRosterModalOpen(true);
        setLoadingMembers(true);
        try {
            const res = await csrfFetch(route('tenant.events.participant-groups.show', {
                event: event.id,
                group: group.id,
            }));
            if (res.ok) {
                const data = await res.json();
                setMembers(data.members?.data || []);
            }
        } catch (err) {
            console.error('Failed to load group roster:', err);
        } finally {
            setLoadingMembers(false);
        }
    };

    const removeMember = async (registrationId) => {
        if (!selectedGroup) return;
        try {
            const res = await csrfFetch(route('tenant.events.participant-groups.members.remove', {
                event: event.id,
                group: selectedGroup.id,
                registration: registrationId,
            }), { method: 'DELETE' });
            if (res.ok) {
                setMembers(members.filter(m => m.registration_id !== registrationId));
                await loadData();
            }
        } catch (err) {
            console.error('Failed to remove member:', err);
        }
    };

    return (
        <div className="space-y-6">
            {/* Header */}
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <h2 className="text-xl font-bold text-slate-900 dark:text-white flex items-center gap-2">
                        <Users className="w-5 h-5 text-indigo-600" />
                        Participant Stratification & Dynamic Cohort Engine
                    </h2>
                    <p className="text-sm text-slate-500 dark:text-slate-400">
                        Segment attendees into tailored groups using multi-parameter rules: ticket tier, attendance, room check-in, CPD hours, and questionnaire responses.
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    <Button variant="primary" size="sm" onClick={openCreateModal}>
                        <Plus className="w-4 h-4 mr-1.5" />
                        Create Group / Cohort
                    </Button>
                </div>
            </div>

            {/* Metrics */}
            <div className="grid grid-cols-2 md:grid-cols-3 gap-4">
                <div className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-4 shadow-sm">
                    <span className="text-xs font-semibold text-slate-500 uppercase tracking-wider">Active Cohorts</span>
                    <div className="mt-2 text-2xl font-black text-slate-900 dark:text-white">
                        {groups.length}
                    </div>
                    <p className="mt-1 text-xs text-slate-500">Stratified cohorts</p>
                </div>

                <div className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-4 shadow-sm">
                    <span className="text-xs font-semibold text-slate-500 uppercase tracking-wider">Total Stratified Members</span>
                    <div className="mt-2 text-2xl font-black text-indigo-600">
                        {groups.reduce((sum, g) => sum + (g.member_count || 0), 0)}
                    </div>
                    <p className="mt-1 text-xs text-slate-500">Across all groups</p>
                </div>

                <div className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-4 shadow-sm">
                    <span className="text-xs font-semibold text-slate-500 uppercase tracking-wider">Total Conference Attendees</span>
                    <div className="mt-2 text-2xl font-black text-slate-900 dark:text-white">
                        {meta.total_attendees}
                    </div>
                    <p className="mt-1 text-xs text-slate-500">Registered pool</p>
                </div>
            </div>

            {/* Groups Grid */}
            {loading ? (
                <div className="p-12 text-center text-xs text-slate-500">Loading stratification cohorts...</div>
            ) : groups.length === 0 ? (
                <div className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-12 text-center space-y-3">
                    <Users className="w-10 h-10 text-slate-300 mx-auto" />
                    <p className="text-sm font-bold text-slate-700 dark:text-slate-300">No Participant Cohorts Created Yet</p>
                    <p className="text-xs text-slate-500 max-w-md mx-auto">
                        Stratify participants automatically based on combined rules (e.g., specific ticket types, in-event check-ins, CME hours, or questionnaire answers).
                    </p>
                    <Button variant="primary" size="sm" onClick={openCreateModal}>
                        <Plus className="w-4 h-4 mr-1.5" />
                        Create First Cohort
                    </Button>
                </div>
            ) : (
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    {groups.map(group => {
                        const isSyncing = syncingGroupId === group.id;
                        const criteriaCount = (group.criteria || []).length;

                        return (
                            <div
                                key={group.id}
                                className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-4 flex flex-col justify-between shadow-xs hover:border-indigo-400 transition"
                            >
                                <div>
                                    <div className="flex items-center justify-between gap-2">
                                        <div className="flex items-center gap-2">
                                            <span className="w-3 h-3 rounded-full flex-shrink-0" style={{ backgroundColor: group.color }}></span>
                                            <span className="text-xs font-bold text-slate-900 dark:text-white truncate">
                                                {group.name}
                                            </span>
                                        </div>
                                        <span className={`text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded ${
                                            group.type === 'dynamic'
                                                ? 'bg-purple-50 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300'
                                                : 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300'
                                        }`}>
                                            {group.type}
                                        </span>
                                    </div>

                                    {group.description && (
                                        <p className="text-xs text-slate-500 dark:text-slate-400 mt-2 line-clamp-2">
                                            {group.description}
                                        </p>
                                    )}

                                    {/* Criteria Rule Summary */}
                                    {group.type === 'dynamic' && (
                                        <div className="mt-3 p-2 rounded-lg bg-slate-50 dark:bg-slate-800/60 border border-slate-100 dark:border-slate-800 text-[11px] text-slate-600 dark:text-slate-400 space-y-1">
                                            <div className="font-semibold text-slate-800 dark:text-slate-200 flex items-center gap-1">
                                                <Filter className="w-3 h-3 text-indigo-500" />
                                                Matching Criteria ({criteriaCount} rules):
                                            </div>
                                            {(group.criteria || []).map((rule, rIdx) => (
                                                <div key={rIdx} className="truncate pl-3 border-l-2 border-indigo-400">
                                                    {rule.type}: {rule.operator} {String(rule.value || '')}
                                                </div>
                                            ))}
                                        </div>
                                    )}

                                    <div className="mt-3 pt-3 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between text-xs">
                                        <span className="text-slate-500">Active Roster:</span>
                                        <span className="font-extrabold text-indigo-600 text-sm">
                                            {group.member_count || 0} participants
                                        </span>
                                    </div>
                                </div>

                                {/* Actions */}
                                <div className="mt-4 pt-3 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between gap-1">
                                    <div className="flex items-center gap-1">
                                        <button
                                            onClick={() => openRoster(group)}
                                            className="text-xs font-semibold text-indigo-600 hover:text-indigo-800 px-2 py-1 rounded bg-indigo-50 dark:bg-indigo-900/30 flex items-center gap-1"
                                        >
                                            <Users className="w-3.5 h-3.5" />
                                            Roster
                                        </button>
                                        {group.type === 'dynamic' && (
                                            <button
                                                onClick={() => handleSync(group)}
                                                disabled={isSyncing}
                                                className="text-xs text-slate-500 hover:text-slate-800 p-1.5 rounded hover:bg-slate-100 dark:hover:bg-slate-800"
                                                title="Recalculate dynamic membership"
                                            >
                                                <RefreshCw className={`w-3.5 h-3.5 ${isSyncing ? 'animate-spin text-indigo-600' : ''}`} />
                                            </button>
                                        )}
                                        <a
                                            href={route('tenant.events.participant-groups.export', {
                                                event: event.id,
                                                group: group.id,
                                            })}
                                            className="text-xs text-slate-500 hover:text-slate-800 p-1.5 rounded hover:bg-slate-100 dark:hover:bg-slate-800"
                                            title="Export CSV Roster"
                                        >
                                            <Download className="w-3.5 h-3.5" />
                                        </a>
                                    </div>

                                    <div className="flex items-center gap-1">
                                        <button
                                            onClick={() => openEditModal(group)}
                                            className="text-slate-400 hover:text-indigo-600 p-1"
                                            title="Edit Criteria"
                                        >
                                            <Edit2 className="w-3.5 h-3.5" />
                                        </button>
                                        <button
                                            onClick={() => setDeleteTarget(group)}
                                            className="text-slate-400 hover:text-rose-600 p-1"
                                            title="Delete Group"
                                        >
                                            <Trash2 className="w-3.5 h-3.5" />
                                        </button>
                                    </div>
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}

            {/* Create/Edit Cohort Modal with Multi-Parameter Criteria Engine */}
            <Modal
                isOpen={modalOpen}
                onClose={() => setModalOpen(false)}
                title={editingGroup ? 'Edit Participant Cohort' : 'Create Stratified Participant Cohort'}
            >
                <form onSubmit={handleSaveGroup} className="space-y-4 max-h-[75vh] overflow-y-auto pr-1">
                    <div className="grid grid-cols-2 gap-3">
                        <div className="col-span-2 sm:col-span-1">
                            <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                Cohort Name *
                            </label>
                            <Input
                                type="text"
                                required
                                placeholder="e.g. Pediatric Fellows - Checked In"
                                value={formData.name}
                                onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                            />
                        </div>
                        <div className="col-span-2 sm:col-span-1">
                            <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                Cohort Tag Color
                            </label>
                            <div className="flex items-center gap-2">
                                <input
                                    type="color"
                                    value={formData.color}
                                    onChange={(e) => setFormData({ ...formData, color: e.target.value })}
                                    className="w-9 h-9 rounded border border-slate-300 p-0.5 cursor-pointer"
                                />
                                <Input
                                    type="text"
                                    value={formData.color}
                                    onChange={(e) => setFormData({ ...formData, color: e.target.value })}
                                    className="flex-1 font-mono text-xs"
                                />
                            </div>
                        </div>
                    </div>

                    <div>
                        <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                            Cohort Type *
                        </label>
                        <Select
                            value={formData.type}
                            onChange={(e) => setFormData({ ...formData, type: e.target.value })}
                        >
                            <option value="dynamic">Dynamic Rule-Based (Auto-calculated)</option>
                            <option value="manual">Static / Manual (Hand-picked attendees)</option>
                        </Select>
                    </div>

                    <div>
                        <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                            Description & Operational Objective
                        </label>
                        <textarea
                            rows={2}
                            placeholder="Purpose of this cohort (e.g. targeted blast for hands-on simulation workshop)..."
                            className="w-full text-xs rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 p-2.5 text-slate-900 dark:text-white focus:outline-none focus:ring-1 focus:ring-indigo-500"
                            value={formData.description}
                            onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                        />
                    </div>

                    {/* Multi-Parameter Rule Builder */}
                    {formData.type === 'dynamic' && (
                        <div className="pt-3 border-t border-slate-200 dark:border-slate-800 space-y-3">
                            <div className="flex items-center justify-between">
                                <h4 className="text-xs font-bold text-slate-900 dark:text-white uppercase tracking-wider flex items-center gap-1.5">
                                    <SlidersHorizontal className="w-3.5 h-3.5 text-indigo-500" />
                                    Segmentation Criteria Rules ({criteriaRules.length})
                                </h4>
                                <button
                                    type="button"
                                    onClick={addRule}
                                    className="text-xs font-semibold text-indigo-600 hover:text-indigo-800 flex items-center gap-1"
                                >
                                    <Plus className="w-3.5 h-3.5" />
                                    Add Rule
                                </button>
                            </div>

                            <div className="space-y-3">
                                {criteriaRules.map((rule, idx) => (
                                    <div
                                        key={idx}
                                        className="p-3 bg-slate-50 dark:bg-slate-800/50 rounded-lg border border-slate-200 dark:border-slate-700 space-y-2 relative"
                                    >
                                        <div className="flex items-center justify-between">
                                            <span className="text-[10px] font-bold text-slate-400">Rule {idx + 1}</span>
                                            <button
                                                type="button"
                                                onClick={() => removeRule(idx)}
                                                className="text-slate-400 hover:text-rose-600 p-0.5"
                                            >
                                                <X className="w-3.5 h-3.5" />
                                            </button>
                                        </div>

                                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-2">
                                            <div>
                                                <label className="block text-[10px] font-semibold text-slate-500 mb-0.5">
                                                    Parameter Field
                                                </label>
                                                <Select
                                                    value={rule.type}
                                                    onChange={(e) => updateRule(idx, { type: e.target.value, value: '' })}
                                                >
                                                    {RULE_TYPES.map(rt => (
                                                        <option key={rt.value} value={rt.value}>{rt.label}</option>
                                                    ))}
                                                </Select>
                                            </div>

                                            <div>
                                                <label className="block text-[10px] font-semibold text-slate-500 mb-0.5">
                                                    Condition / Operator
                                                </label>
                                                <Select
                                                    value={rule.operator || 'is'}
                                                    onChange={(e) => updateRule(idx, { operator: e.target.value })}
                                                >
                                                    {rule.type === 'cme_hours' ? (
                                                        <>
                                                            <option value="gte">Greater Than or Equal (&gt;=)</option>
                                                            <option value="lte">Less Than or Equal (&lt;=)</option>
                                                            <option value="gt">Greater Than (&gt;)</option>
                                                        </>
                                                    ) : rule.type === 'session_attendance' ? (
                                                        <>
                                                            <option value="attended">Attended Room</option>
                                                            <option value="not_attended">Did Not Attend</option>
                                                            <option value="min_minutes">Min Minutes Dwell</option>
                                                        </>
                                                    ) : rule.type === 'form_answer' ? (
                                                        <>
                                                            <option value="equals">Equals Exactly</option>
                                                            <option value="contains">Contains Word</option>
                                                            <option value="is_filled">Has Responded</option>
                                                        </>
                                                    ) : (
                                                        <>
                                                            <option value="is">Is / Equals</option>
                                                            <option value="is_not">Is Not</option>
                                                        </>
                                                    )}
                                                </Select>
                                            </div>

                                            <div>
                                                <label className="block text-[10px] font-semibold text-slate-500 mb-0.5">
                                                    Target Value
                                                </label>
                                                {rule.type === 'ticket_type' ? (
                                                    <Select
                                                        value={rule.value}
                                                        onChange={(e) => updateRule(idx, { value: e.target.value })}
                                                    >
                                                        <option value="">Select Ticket Type</option>
                                                        {meta.ticket_types.map(tt => (
                                                            <option key={tt.id} value={tt.id}>{tt.name}</option>
                                                        ))}
                                                    </Select>
                                                ) : rule.type === 'status' ? (
                                                    <Select
                                                        value={rule.value}
                                                        onChange={(e) => updateRule(idx, { value: e.target.value })}
                                                    >
                                                        <option value="checked_in">Checked In</option>
                                                        <option value="confirmed">Confirmed (Not Checked In)</option>
                                                        <option value="waitlisted">Waitlisted</option>
                                                        <option value="pending_approval">Pending Approval</option>
                                                    </Select>
                                                ) : rule.type === 'cme_hours' ? (
                                                    <Input
                                                        type="number"
                                                        step="0.5"
                                                        placeholder="e.g. 5.0"
                                                        value={rule.value}
                                                        onChange={(e) => updateRule(idx, { value: e.target.value })}
                                                    />
                                                ) : (
                                                    <Input
                                                        type="text"
                                                        placeholder="Value"
                                                        value={rule.value}
                                                        onChange={(e) => updateRule(idx, { value: e.target.value })}
                                                    />
                                                )}
                                            </div>
                                        </div>

                                        {/* Additional context for form_answer */}
                                        {rule.type === 'form_answer' && (
                                            <div className="grid grid-cols-2 gap-2 pt-1 border-t border-slate-200 dark:border-slate-700">
                                                <div>
                                                    <label className="block text-[10px] font-semibold text-slate-500 mb-0.5">Form</label>
                                                    <Select
                                                        value={rule.form_id || ''}
                                                        onChange={(e) => updateRule(idx, { form_id: e.target.value })}
                                                    >
                                                        <option value="">Any Dynamic Form</option>
                                                        {meta.forms.map(f => (
                                                            <option key={f.id} value={f.id}>{f.title}</option>
                                                        ))}
                                                    </Select>
                                                </div>
                                                <div>
                                                    <label className="block text-[10px] font-semibold text-slate-500 mb-0.5">Question Field Key</label>
                                                    <Input
                                                        type="text"
                                                        placeholder="e.g. subspecialty"
                                                        value={rule.field_key || ''}
                                                        onChange={(e) => updateRule(idx, { field_key: e.target.value })}
                                                    />
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}

                    <div className="flex justify-end gap-2 pt-3 border-t border-slate-100 dark:border-slate-800">
                        <Button variant="secondary" type="button" onClick={() => setModalOpen(false)}>
                            Cancel
                        </Button>
                        <Button variant="primary" type="submit" disabled={saving}>
                            {saving ? 'Saving...' : (editingGroup ? 'Update Cohort' : 'Save Cohort')}
                        </Button>
                    </div>
                </form>
            </Modal>

            {/* Group Members Roster Drawer */}
            <Modal
                isOpen={rosterModalOpen}
                onClose={() => setRosterModalOpen(false)}
                title={`Cohort Roster: ${selectedGroup?.name || ''}`}
            >
                <div className="space-y-4 max-h-[75vh] flex flex-col">
                    <div className="flex items-center justify-between">
                        <span className="text-xs text-slate-500">
                            {members.length} Members in this Cohort
                        </span>
                        {selectedGroup && (
                            <a
                                href={route('tenant.events.participant-groups.export', {
                                    event: event.id,
                                    group: selectedGroup.id,
                                })}
                                className="inline-flex items-center gap-1.5 text-xs font-semibold px-2.5 py-1.5 rounded-lg bg-indigo-50 dark:bg-indigo-900/30 text-indigo-600 hover:bg-indigo-100"
                            >
                                <Download className="w-3.5 h-3.5" />
                                Export CSV Roster
                            </a>
                        )}
                    </div>

                    <div className="overflow-x-auto flex-1 border border-slate-200 dark:border-slate-800 rounded-lg">
                        {loadingMembers ? (
                            <div className="p-8 text-center text-xs text-slate-500">Loading roster...</div>
                        ) : members.length === 0 ? (
                            <div className="p-8 text-center text-xs text-slate-400 italic">No attendees matched this cohort yet</div>
                        ) : (
                            <table className="w-full text-left text-xs">
                                <thead className="bg-slate-50 dark:bg-slate-800 text-slate-600 dark:text-slate-300 font-semibold">
                                    <tr>
                                        <th className="p-2.5">Participant</th>
                                        <th className="p-2.5">Ticket Tier</th>
                                        <th className="p-2.5">Status</th>
                                        <th className="p-2.5">Match Type</th>
                                        <th className="p-2.5 text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                    {members.map(m => {
                                        const reg = m.registration;
                                        if (!reg) return null;

                                        return (
                                            <tr key={m.id} className="hover:bg-slate-50 dark:hover:bg-slate-800/40">
                                                <td className="p-2.5">
                                                    <div className="font-bold text-slate-900 dark:text-white">
                                                        {reg.full_name}
                                                    </div>
                                                    <div className="text-[10px] text-slate-400">
                                                        {reg.email} &middot; {reg.ticket_code}
                                                    </div>
                                                </td>
                                                <td className="p-2.5 text-slate-600 dark:text-slate-300">
                                                    {reg.ticket_type?.name ?? 'Standard'}
                                                </td>
                                                <td className="p-2.5">
                                                    <span className={`text-[10px] font-semibold px-2 py-0.5 rounded-full ${
                                                        reg.status === 'checked_in'
                                                            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300'
                                                            : 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300'
                                                    }`}>
                                                        {reg.status}
                                                    </span>
                                                </td>
                                                <td className="p-2.5">
                                                    <span className="text-[10px] font-medium text-slate-500">
                                                        {m.is_manual ? 'Manual Pin' : 'Dynamic Match'}
                                                    </span>
                                                </td>
                                                <td className="p-2.5 text-right">
                                                    <button
                                                        onClick={() => removeMember(reg.id)}
                                                        className="p-1 text-slate-400 hover:text-rose-600"
                                                        title="Remove from group"
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

                    <div className="flex justify-end pt-2">
                        <Button variant="secondary" onClick={() => setRosterModalOpen(false)}>
                            Close
                        </Button>
                    </div>
                </div>
            </Modal>

            {/* Confirm Delete Group Modal */}
            <ConfirmModal
                isOpen={!!deleteTarget}
                title="Delete Participant Cohort?"
                message={`Are you sure you want to delete the group "${deleteTarget?.name}"?`}
                confirmLabel="Delete Group"
                variant="danger"
                onConfirm={handleDeleteGroup}
                onCancel={() => setDeleteTarget(null)}
            />
        </div>
    );
}
