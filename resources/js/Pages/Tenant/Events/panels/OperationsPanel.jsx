import { useState, useEffect } from 'react';
import Button from '@/Components/Console/Button';
import Modal from '@/Components/Console/Modal';
import Input from '@/Components/Console/Input';
import Select from '@/Components/Console/Select';
import ConfirmModal from '@/Components/Console/ConfirmModal';
import csrfFetch from '@/lib/csrfFetch';
import {
    Plus,
    CheckCircle2,
    Clock,
    AlertTriangle,
    Layers,
    DollarSign,
    Calendar,
    User,
    ArrowRight,
    Trash2,
    Edit2,
    RefreshCw,
    Search,
    ChevronDown,
    Link as LinkIcon,
    Kanban,
    List,
    AlertCircle,
    FolderPlus
} from 'lucide-react';

const PRIORITY_BADGES = {
    low: { bg: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300', label: 'Low' },
    medium: { bg: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300', label: 'Medium' },
    high: { bg: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300', label: 'High' },
    urgent: { bg: 'bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-300', label: 'Urgent' },
};

const STATUS_CONFIG = {
    not_started: { label: 'Not Started', color: 'text-slate-500', bg: 'bg-slate-100 dark:bg-slate-800' },
    in_progress: { label: 'In Progress', color: 'text-blue-600', bg: 'bg-blue-50 dark:bg-blue-900/20' },
    blocked: { label: 'Blocked', color: 'text-rose-600', bg: 'bg-rose-50 dark:bg-rose-900/20' },
    done: { label: 'Done', color: 'text-emerald-600', bg: 'bg-emerald-50 dark:bg-emerald-900/20' },
};

export default function OperationsPanel({ event }) {
    const [loading, setLoading] = useState(true);
    const [pillars, setPillars] = useState([]);
    const [summary, setSummary] = useState(null);
    const [teamMembers, setTeamMembers] = useState([]);
    const [activePillarFilter, setActivePillarFilter] = useState('all');
    const [search, setSearch] = useState('');
    const [viewMode, setViewMode] = useState('board'); // 'board' or 'list'

    // Pillar Modal
    const [pillarModalOpen, setPillarModalOpen] = useState(false);
    const [editingPillar, setEditingPillar] = useState(null);
    const [pillarForm, setPillarForm] = useState({ name: '', color: '#3B82F6', icon: 'folder' });
    const [savingPillar, setSavingPillar] = useState(false);
    const [deletePillarTarget, setDeletePillarTarget] = useState(null);

    // Task Modal
    const [taskModalOpen, setTaskModalOpen] = useState(false);
    const [editingTask, setEditingTask] = useState(null);
    const [savingTask, setSavingTask] = useState(false);
    const [deleteTaskTarget, setDeleteTaskTarget] = useState(null);
    const [taskForm, setTaskForm] = useState({
        pillar_id: '',
        title: '',
        description: '',
        owner_id: '',
        owner_name: '',
        due_date: '',
        priority: 'medium',
        status: 'not_started',
        dependency_task_id: '',
        estimated_budget: '',
        actual_budget: '',
    });

    const loadData = async () => {
        setLoading(true);
        try {
            const res = await csrfFetch(route('tenant.events.operations.index', { event: event.id }));
            if (res.ok) {
                const data = await res.json();
                setPillars(data.pillars || []);
                setSummary(data.summary || null);
                setTeamMembers(data.team_members || []);
            }
        } catch (err) {
            console.error('Failed to load operations data:', err);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        loadData();
    }, [event.id]);

    // Handle Pillar Save
    const handleSavePillar = async (e) => {
        e.preventDefault();
        setSavingPillar(true);
        try {
            const url = editingPillar
                ? route('tenant.events.operations.pillars.update', { event: event.id, pillar: editingPillar.id })
                : route('tenant.events.operations.pillars.store', { event: event.id });
            const method = editingPillar ? 'PUT' : 'POST';

            const res = await csrfFetch(url, {
                method,
                body: JSON.stringify(pillarForm),
            });

            if (res.ok) {
                setPillarModalOpen(false);
                setEditingPillar(null);
                await loadData();
            }
        } catch (err) {
            console.error('Failed to save pillar:', err);
        } finally {
            setSavingPillar(false);
        }
    };

    // Handle Delete Pillar
    const handleDeletePillar = async () => {
        if (!deletePillarTarget) return;
        try {
            const res = await csrfFetch(route('tenant.events.operations.pillars.destroy', { event: event.id, pillar: deletePillarTarget.id }), {
                method: 'DELETE',
            });
            if (res.ok) {
                setDeletePillarTarget(null);
                await loadData();
            }
        } catch (err) {
            console.error('Failed to delete pillar:', err);
        }
    };

    // Open Task Create Modal
    const openCreateTask = (defaultPillarId = null) => {
        setEditingTask(null);
        setTaskForm({
            pillar_id: defaultPillarId || (pillars.length > 0 ? pillars[0].id : ''),
            title: '',
            description: '',
            owner_id: '',
            owner_name: '',
            due_date: '',
            priority: 'medium',
            status: 'not_started',
            dependency_task_id: '',
            estimated_budget: '',
            actual_budget: '',
        });
        setTaskModalOpen(true);
    };

    // Open Task Edit Modal
    const openEditTask = (task) => {
        setEditingTask(task);
        setTaskForm({
            pillar_id: task.pillar_id,
            title: task.title,
            description: task.description || '',
            owner_id: task.owner_id || '',
            owner_name: task.owner_name || '',
            due_date: task.due_date ? task.due_date.split('T')[0] : '',
            priority: task.priority || 'medium',
            status: task.status || 'not_started',
            dependency_task_id: task.dependency_task_id || '',
            estimated_budget: task.estimated_budget ? (task.estimated_budget / 100).toFixed(2) : '',
            actual_budget: task.actual_budget ? (task.actual_budget / 100).toFixed(2) : '',
        });
        setTaskModalOpen(true);
    };

    // Handle Task Save
    const handleSaveTask = async (e) => {
        e.preventDefault();
        setSavingTask(true);
        try {
            const url = editingTask
                ? route('tenant.events.operations.tasks.update', { event: event.id, task: editingTask.id })
                : route('tenant.events.operations.tasks.store', { event: event.id });
            const method = editingTask ? 'PUT' : 'POST';

            const payload = {
                ...taskForm,
                estimated_budget: taskForm.estimated_budget ? parseFloat(taskForm.estimated_budget) : 0,
                actual_budget: taskForm.actual_budget ? parseFloat(taskForm.actual_budget) : 0,
            };

            const res = await csrfFetch(url, {
                method,
                body: JSON.stringify(payload),
            });

            if (res.ok) {
                setTaskModalOpen(false);
                setEditingTask(null);
                await loadData();
            }
        } catch (err) {
            console.error('Failed to save task:', err);
        } finally {
            setSavingTask(false);
        }
    };

    // Quick Update Task Status
    const handleQuickStatusUpdate = async (task, newStatus) => {
        try {
            const res = await csrfFetch(route('tenant.events.operations.tasks.status', { event: event.id, task: task.id }), {
                method: 'PATCH',
                body: JSON.stringify({ status: newStatus }),
            });
            if (res.ok) {
                await loadData();
            }
        } catch (err) {
            console.error('Failed to update task status:', err);
        }
    };

    // Handle Delete Task
    const handleDeleteTask = async () => {
        if (!deleteTaskTarget) return;
        try {
            const res = await csrfFetch(route('tenant.events.operations.tasks.destroy', { event: event.id, task: deleteTaskTarget.id }), {
                method: 'DELETE',
            });
            if (res.ok) {
                setDeleteTaskTarget(null);
                await loadData();
            }
        } catch (err) {
            console.error('Failed to delete task:', err);
        }
    };

    // Filter tasks
    const allPillarsTasks = pillars.flatMap(p => p.tasks || []);
    const filteredPillars = activePillarFilter === 'all'
        ? pillars
        : pillars.filter(p => p.id === activePillarFilter);

    const matchSearch = (task) => {
        if (!search.trim()) return true;
        const q = search.toLowerCase();
        return (
            task.title?.toLowerCase().includes(q) ||
            task.description?.toLowerCase().includes(q) ||
            task.owner_name?.toLowerCase().includes(q)
        );
    };

    const completionRate = summary && summary.total_tasks > 0
        ? Math.round((summary.done_tasks / summary.total_tasks) * 100)
        : 0;

    return (
        <div className="space-y-6">
            {/* Header and Quick Stats */}
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <h2 className="text-xl font-bold text-slate-900 dark:text-white flex items-center gap-2">
                        <Layers className="w-5 h-5 text-indigo-600" />
                        8-Pillar Conference Operations & Project Management
                    </h2>
                    <p className="text-sm text-slate-500 dark:text-slate-400">
                        Orchestrate academic programme, technology, venue, protocol, budget and custom operational pillars.
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    <Button
                        variant="secondary"
                        size="sm"
                        onClick={() => {
                            setEditingPillar(null);
                            setPillarForm({ name: '', color: '#6366F1', icon: 'folder' });
                            setPillarModalOpen(true);
                        }}
                    >
                        <FolderPlus className="w-4 h-4 mr-1" />
                        Add Custom Pillar
                    </Button>
                    <Button
                        variant="primary"
                        size="sm"
                        onClick={() => openCreateTask()}
                    >
                        <Plus className="w-4 h-4 mr-1" />
                        New Task
                    </Button>
                </div>
            </div>

            {/* Metrics Cards */}
            {summary && (
                <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-4 shadow-sm">
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-semibold text-slate-500 uppercase tracking-wider">Completion Rate</span>
                            <CheckCircle2 className="w-4 h-4 text-emerald-500" />
                        </div>
                        <div className="mt-2 flex items-baseline gap-2">
                            <span className="text-2xl font-black text-slate-900 dark:text-white">{completionRate}%</span>
                            <span className="text-xs text-slate-500">({summary.done_tasks}/{summary.total_tasks} done)</span>
                        </div>
                        <div className="mt-2 w-full bg-slate-100 dark:bg-slate-800 h-1.5 rounded-full overflow-hidden">
                            <div className="bg-emerald-500 h-full rounded-full transition-all duration-500" style={{ width: `${completionRate}%` }}></div>
                        </div>
                    </div>

                    <div className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-4 shadow-sm">
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-semibold text-slate-500 uppercase tracking-wider">In Progress & Blocked</span>
                            <Clock className="w-4 h-4 text-blue-500" />
                        </div>
                        <div className="mt-2 flex items-baseline gap-2">
                            <span className="text-2xl font-black text-blue-600">{summary.in_progress_tasks}</span>
                            <span className="text-xs text-slate-500">active</span>
                            {summary.blocked_tasks > 0 && (
                                <span className="text-xs font-semibold text-rose-500 bg-rose-50 dark:bg-rose-900/30 px-1.5 py-0.5 rounded">
                                    {summary.blocked_tasks} blocked
                                </span>
                            )}
                        </div>
                        <p className="mt-2 text-xs text-slate-400">{summary.not_started_tasks} queued tasks</p>
                    </div>

                    <div className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-4 shadow-sm">
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-semibold text-slate-500 uppercase tracking-wider">Budget Variance</span>
                            <DollarSign className="w-4 h-4 text-emerald-500" />
                        </div>
                        <div className="mt-2 flex items-baseline gap-2">
                            <span className="text-2xl font-black text-slate-900 dark:text-white">
                                {event.currency || 'USD'} {(summary.total_actual_budget / 100).toFixed(2)}
                            </span>
                        </div>
                        <p className="mt-2 text-xs text-slate-500">
                            Est: {event.currency || 'USD'} {(summary.total_estimated_budget / 100).toFixed(2)}
                        </p>
                    </div>

                    <div className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-4 shadow-sm">
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-semibold text-slate-500 uppercase tracking-wider">Operational Pillars</span>
                            <Layers className="w-4 h-4 text-indigo-500" />
                        </div>
                        <div className="mt-2 flex items-baseline gap-2">
                            <span className="text-2xl font-black text-slate-900 dark:text-white">{summary.pillars_count}</span>
                            <span className="text-xs text-slate-500">active pillars</span>
                        </div>
                        <p className="mt-2 text-xs text-indigo-600 dark:text-indigo-400 font-medium">Extensible On The Fly</p>
                    </div>
                </div>
            )}

            {/* Filter Bar */}
            <div className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-3 flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-2 overflow-x-auto pb-1 max-w-full">
                    <button
                        onClick={() => setActivePillarFilter('all')}
                        className={`px-3 py-1.5 text-xs font-semibold rounded-lg transition whitespace-nowrap ${
                            activePillarFilter === 'all'
                                ? 'bg-indigo-600 text-white shadow-sm'
                                : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200'
                        }`}
                    >
                        All Pillars ({allPillarsTasks.length})
                    </button>
                    {pillars.map(pillar => {
                        const count = pillar.tasks ? pillar.tasks.length : 0;
                        return (
                            <button
                                key={pillar.id}
                                onClick={() => setActivePillarFilter(pillar.id)}
                                className={`px-3 py-1.5 text-xs font-semibold rounded-lg transition flex items-center gap-1.5 whitespace-nowrap ${
                                    activePillarFilter === pillar.id
                                        ? 'bg-slate-900 dark:bg-slate-100 text-white dark:text-slate-900 shadow-sm'
                                        : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200'
                                }`}
                            >
                                <span className="w-2 h-2 rounded-full" style={{ backgroundColor: pillar.color }}></span>
                                {pillar.name} ({count})
                            </button>
                        );
                    })}
                </div>

                <div className="flex items-center gap-2">
                    <div className="relative">
                        <Search className="w-4 h-4 text-slate-400 absolute left-3 top-2.5" />
                        <input
                            type="text"
                            placeholder="Search tasks..."
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            className="text-xs pl-9 pr-3 py-1.5 rounded-lg border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-800 text-slate-900 dark:text-white focus:outline-none focus:ring-1 focus:ring-indigo-500 w-48"
                        />
                    </div>
                    <div className="flex border border-slate-200 dark:border-slate-800 rounded-lg p-0.5 bg-slate-100 dark:bg-slate-800">
                        <button
                            onClick={() => setViewMode('board')}
                            className={`p-1.5 rounded-md ${viewMode === 'board' ? 'bg-white dark:bg-slate-700 shadow-xs' : 'text-slate-500'}`}
                            title="Board View"
                        >
                            <Kanban className="w-4 h-4" />
                        </button>
                        <button
                            onClick={() => setViewMode('list')}
                            className={`p-1.5 rounded-md ${viewMode === 'list' ? 'bg-white dark:bg-slate-700 shadow-xs' : 'text-slate-500'}`}
                            title="List View"
                        >
                            <List className="w-4 h-4" />
                        </button>
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

            {/* Pillars & Tasks Display */}
            {loading && pillars.length === 0 ? (
                <div className="p-12 text-center text-slate-500">Loading operational pillars...</div>
            ) : viewMode === 'board' ? (
                /* Board View: Columns for Pillars */
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 items-start">
                    {filteredPillars.map(pillar => {
                        const tasks = (pillar.tasks || []).filter(matchSearch);
                        const pillarEst = tasks.reduce((sum, t) => sum + (t.estimated_budget || 0), 0);
                        const pillarAct = tasks.reduce((sum, t) => sum + (t.actual_budget || 0), 0);

                        return (
                            <div key={pillar.id} className="bg-slate-50 dark:bg-slate-900/60 border border-slate-200 dark:border-slate-800 rounded-xl flex flex-col max-h-[750px] shadow-xs">
                                {/* Pillar Header */}
                                <div className="p-3.5 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                                    <div className="flex items-center gap-2 overflow-hidden">
                                        <span className="w-3 h-3 rounded-full flex-shrink-0" style={{ backgroundColor: pillar.color }}></span>
                                        <h3 className="text-sm font-bold text-slate-900 dark:text-white truncate" title={pillar.name}>
                                            {pillar.name}
                                        </h3>
                                        <span className="text-xs bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-semibold px-1.5 py-0.5 rounded-full">
                                            {tasks.length}
                                        </span>
                                    </div>
                                    <div className="flex items-center gap-1">
                                        <button
                                            onClick={() => openCreateTask(pillar.id)}
                                            className="p-1 text-slate-400 hover:text-indigo-600 dark:hover:text-indigo-400 transition"
                                            title="Add task to pillar"
                                        >
                                            <Plus className="w-4 h-4" />
                                        </button>
                                        <button
                                            onClick={() => {
                                                setEditingPillar(pillar);
                                                setPillarForm({ name: pillar.name, color: pillar.color, icon: pillar.icon || 'folder' });
                                                setPillarModalOpen(true);
                                            }}
                                            className="p-1 text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 transition"
                                            title="Edit pillar"
                                        >
                                            <Edit2 className="w-3.5 h-3.5" />
                                        </button>
                                        {!pillar.is_default && (
                                            <button
                                                onClick={() => setDeletePillarTarget(pillar)}
                                                className="p-1 text-slate-400 hover:text-rose-600 transition"
                                                title="Delete custom pillar"
                                            >
                                                <Trash2 className="w-3.5 h-3.5" />
                                            </button>
                                        )}
                                    </div>
                                </div>

                                {/* Task Cards Container */}
                                <div className="p-3 space-y-2.5 overflow-y-auto flex-1">
                                    {tasks.length === 0 ? (
                                        <div className="py-6 text-center text-xs text-slate-400 italic">
                                            No tasks in this pillar
                                        </div>
                                    ) : (
                                        tasks.map(task => {
                                            const status = STATUS_CONFIG[task.status] || STATUS_CONFIG.not_started;
                                            const priority = PRIORITY_BADGES[task.priority] || PRIORITY_BADGES.medium;

                                            return (
                                                <div
                                                    key={task.id}
                                                    className="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg p-3 shadow-xs hover:border-indigo-400 transition group"
                                                >
                                                    <div className="flex items-start justify-between gap-2">
                                                        <span className={`text-[10px] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded ${priority.bg}`}>
                                                            {priority.label}
                                                        </span>
                                                        <div className="opacity-0 group-hover:opacity-100 flex items-center gap-1 transition">
                                                            <button
                                                                onClick={() => openEditTask(task)}
                                                                className="text-slate-400 hover:text-indigo-600 p-0.5"
                                                            >
                                                                <Edit2 className="w-3.5 h-3.5" />
                                                            </button>
                                                            <button
                                                                onClick={() => setDeleteTaskTarget(task)}
                                                                className="text-slate-400 hover:text-rose-600 p-0.5"
                                                            >
                                                                <Trash2 className="w-3.5 h-3.5" />
                                                            </button>
                                                        </div>
                                                    </div>

                                                    <h4 className="text-xs font-bold text-slate-900 dark:text-white mt-1.5 line-clamp-2">
                                                        {task.title}
                                                    </h4>

                                                    {task.description && (
                                                        <p className="text-[11px] text-slate-500 dark:text-slate-400 mt-1 line-clamp-2">
                                                            {task.description}
                                                        </p>
                                                    )}

                                                    {/* Dependency Badge */}
                                                    {task.dependency_task && (
                                                        <div className="mt-2 flex items-center gap-1 text-[10px] text-slate-500 bg-slate-50 dark:bg-slate-900 px-2 py-1 rounded border border-slate-200 dark:border-slate-800 truncate">
                                                            <LinkIcon className="w-3 h-3 text-indigo-500 flex-shrink-0" />
                                                            <span className="truncate">After: {task.dependency_task.title}</span>
                                                        </div>
                                                    )}

                                                    {/* Meta: Due date & Owner */}
                                                    <div className="mt-2.5 pt-2 border-t border-slate-100 dark:border-slate-700/60 flex items-center justify-between text-[11px] text-slate-500">
                                                        <div className="flex items-center gap-1">
                                                            <User className="w-3 h-3 text-slate-400" />
                                                            <span className="truncate max-w-[90px]">
                                                                {task.owner_name || 'Unassigned'}
                                                            </span>
                                                        </div>
                                                        {task.due_date && (
                                                            <div className="flex items-center gap-1">
                                                                <Calendar className="w-3 h-3 text-slate-400" />
                                                                <span>{task.due_date.split('T')[0]}</span>
                                                            </div>
                                                        )}
                                                    </div>

                                                    {/* Status Dropdown */}
                                                    <div className="mt-2">
                                                        <select
                                                            value={task.status}
                                                            onChange={(e) => handleQuickStatusUpdate(task, e.target.value)}
                                                            className={`w-full text-[11px] font-semibold py-1 px-2 rounded border border-slate-200 dark:border-slate-700 ${status.bg} ${status.color} focus:outline-none`}
                                                        >
                                                            <option value="not_started">⚪ Not Started</option>
                                                            <option value="in_progress">🔵 In Progress</option>
                                                            <option value="blocked">🔴 Blocked</option>
                                                            <option value="done">🟢 Done</option>
                                                        </select>
                                                    </div>
                                                </div>
                                            );
                                        })
                                    )}
                                </div>

                                {/* Pillar Footer / Budget summary */}
                                <div className="p-2.5 bg-slate-100/60 dark:bg-slate-800/40 border-t border-slate-200 dark:border-slate-800 text-[11px] text-slate-500 flex items-center justify-between">
                                    <span>Spend: {(pillarAct / 100).toFixed(0)}</span>
                                    <span>Budget: {(pillarEst / 100).toFixed(0)}</span>
                                </div>
                            </div>
                        );
                    })}
                </div>
            ) : (
                /* List View */
                <div className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl overflow-hidden shadow-xs">
                    <table className="w-full text-left text-xs">
                        <thead className="bg-slate-50 dark:bg-slate-800/70 border-b border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 font-bold uppercase tracking-wider">
                            <tr>
                                <th className="p-3.5">Task</th>
                                <th className="p-3.5">Pillar</th>
                                <th className="p-3.5">Owner</th>
                                <th className="p-3.5">Due Date</th>
                                <th className="p-3.5">Priority</th>
                                <th className="p-3.5">Status</th>
                                <th className="p-3.5 text-right">Budget (Est / Act)</th>
                                <th className="p-3.5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800 text-slate-700 dark:text-slate-300">
                            {allPillarsTasks.filter(matchSearch).map(task => {
                                const pillar = pillars.find(p => p.id === task.pillar_id);
                                const status = STATUS_CONFIG[task.status] || STATUS_CONFIG.not_started;
                                const priority = PRIORITY_BADGES[task.priority] || PRIORITY_BADGES.medium;

                                return (
                                    <tr key={task.id} className="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition">
                                        <td className="p-3.5 font-semibold text-slate-900 dark:text-white">
                                            {task.title}
                                            {task.dependency_task && (
                                                <div className="text-[10px] text-indigo-600 dark:text-indigo-400 font-normal">
                                                    Dep: {task.dependency_task.title}
                                                </div>
                                            )}
                                        </td>
                                        <td className="p-3.5">
                                            <span className="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[11px] font-medium bg-slate-100 dark:bg-slate-800">
                                                <span className="w-2 h-2 rounded-full" style={{ backgroundColor: pillar?.color || '#3B82F6' }}></span>
                                                {pillar?.name || 'General'}
                                            </span>
                                        </td>
                                        <td className="p-3.5 text-slate-600 dark:text-slate-400">
                                            {task.owner_name || '—'}
                                        </td>
                                        <td className="p-3.5">
                                            {task.due_date ? task.due_date.split('T')[0] : '—'}
                                        </td>
                                        <td className="p-3.5">
                                            <span className={`text-[10px] font-bold px-1.5 py-0.5 rounded ${priority.bg}`}>
                                                {priority.label}
                                            </span>
                                        </td>
                                        <td className="p-3.5">
                                            <select
                                                value={task.status}
                                                onChange={(e) => handleQuickStatusUpdate(task, e.target.value)}
                                                className={`text-[11px] font-semibold py-0.5 px-2 rounded border border-slate-200 dark:border-slate-700 ${status.bg} ${status.color} focus:outline-none`}
                                            >
                                                <option value="not_started">⚪ Not Started</option>
                                                <option value="in_progress">🔵 In Progress</option>
                                                <option value="blocked">🔴 Blocked</option>
                                                <option value="done">🟢 Done</option>
                                            </select>
                                        </td>
                                        <td className="p-3.5 text-right font-mono">
                                            {(task.estimated_budget / 100).toFixed(2)} / {(task.actual_budget / 100).toFixed(2)}
                                        </td>
                                        <td className="p-3.5 text-right space-x-1">
                                            <button
                                                onClick={() => openEditTask(task)}
                                                className="p-1 text-slate-400 hover:text-indigo-600"
                                            >
                                                <Edit2 className="w-3.5 h-3.5" />
                                            </button>
                                            <button
                                                onClick={() => setDeleteTaskTarget(task)}
                                                className="p-1 text-slate-400 hover:text-rose-600"
                                            >
                                                <Trash2 className="w-3.5 h-3.5" />
                                            </button>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}

            {/* Pillar Create/Edit Modal */}
            <Modal
                isOpen={pillarModalOpen}
                onClose={() => setPillarModalOpen(false)}
                title={editingPillar ? 'Edit Operational Pillar' : 'Create Custom Operational Pillar'}
            >
                <form onSubmit={handleSavePillar} className="space-y-4">
                    <div>
                        <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                            Pillar Name *
                        </label>
                        <Input
                            type="text"
                            required
                            placeholder="e.g. VIP Protocol & Security, Media Centre"
                            value={pillarForm.name}
                            onChange={(e) => setPillarForm({ ...pillarForm, name: e.target.value })}
                        />
                    </div>
                    <div>
                        <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                            Pillar Color Tag
                        </label>
                        <div className="flex items-center gap-2">
                            <input
                                type="color"
                                value={pillarForm.color}
                                onChange={(e) => setPillarForm({ ...pillarForm, color: e.target.value })}
                                className="w-9 h-9 rounded border border-slate-300 p-0.5 cursor-pointer"
                            />
                            <Input
                                type="text"
                                value={pillarForm.color}
                                onChange={(e) => setPillarForm({ ...pillarForm, color: e.target.value })}
                                className="flex-1 font-mono text-xs"
                            />
                        </div>
                    </div>
                    <div className="flex justify-end gap-2 pt-2">
                        <Button variant="secondary" type="button" onClick={() => setPillarModalOpen(false)}>
                            Cancel
                        </Button>
                        <Button variant="primary" type="submit" disabled={savingPillar}>
                            {savingPillar ? 'Saving...' : (editingPillar ? 'Update Pillar' : 'Create Pillar')}
                        </Button>
                    </div>
                </form>
            </Modal>

            {/* Task Create/Edit Modal */}
            <Modal
                isOpen={taskModalOpen}
                onClose={() => setTaskModalOpen(false)}
                title={editingTask ? 'Edit Task' : 'New Operational Task'}
            >
                <form onSubmit={handleSaveTask} className="space-y-4 max-h-[75vh] overflow-y-auto pr-1">
                    <div>
                        <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                            Pillar *
                        </label>
                        <Select
                            value={taskForm.pillar_id}
                            onChange={(e) => setTaskForm({ ...taskForm, pillar_id: e.target.value })}
                            required
                        >
                            {pillars.map(p => (
                                <option key={p.id} value={p.id}>{p.name}</option>
                            ))}
                        </Select>
                    </div>

                    <div>
                        <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                            Task Title *
                        </label>
                        <Input
                            type="text"
                            required
                            placeholder="e.g. Finalize Keynote audiovisual tech rider"
                            value={taskForm.title}
                            onChange={(e) => setTaskForm({ ...taskForm, title: e.target.value })}
                        />
                    </div>

                    <div>
                        <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                            Description
                        </label>
                        <textarea
                            rows={3}
                            className="w-full text-xs rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 p-2.5 text-slate-900 dark:text-white focus:ring-2 focus:ring-indigo-500 focus:outline-none"
                            placeholder="Detailed operational steps, deliverables, or checklist..."
                            value={taskForm.description}
                            onChange={(e) => setTaskForm({ ...taskForm, description: e.target.value })}
                        />
                    </div>

                    <div className="grid grid-cols-2 gap-3">
                        <div>
                            <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                Assigned Owner
                            </label>
                            <Select
                                value={taskForm.owner_id}
                                onChange={(e) => {
                                    const member = teamMembers.find(m => String(m.id) === String(e.target.value));
                                    setTaskForm({
                                        ...taskForm,
                                        owner_id: e.target.value,
                                        owner_name: member ? member.name : '',
                                    });
                                }}
                            >
                                <option value="">Select Team Member</option>
                                {teamMembers.map(m => (
                                    <option key={m.id} value={m.id}>{m.name} ({m.email})</option>
                                ))}
                            </Select>
                        </div>
                        <div>
                            <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                Due Date
                            </label>
                            <Input
                                type="date"
                                value={taskForm.due_date}
                                onChange={(e) => setTaskForm({ ...taskForm, due_date: e.target.value })}
                            />
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-3">
                        <div>
                            <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                Priority
                            </label>
                            <Select
                                value={taskForm.priority}
                                onChange={(e) => setTaskForm({ ...taskForm, priority: e.target.value })}
                            >
                                <option value="low">Low</option>
                                <option value="medium">Medium</option>
                                <option value="high">High</option>
                                <option value="urgent">Urgent</option>
                            </Select>
                        </div>
                        <div>
                            <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                Status
                            </label>
                            <Select
                                value={taskForm.status}
                                onChange={(e) => setTaskForm({ ...taskForm, status: e.target.value })}
                            >
                                <option value="not_started">Not Started</option>
                                <option value="in_progress">In Progress</option>
                                <option value="blocked">Blocked</option>
                                <option value="done">Done</option>
                            </Select>
                        </div>
                    </div>

                    <div>
                        <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                            Dependency (Must be completed after)
                        </label>
                        <Select
                            value={taskForm.dependency_task_id}
                            onChange={(e) => setTaskForm({ ...taskForm, dependency_task_id: e.target.value })}
                        >
                            <option value="">No Dependency</option>
                            {allPillarsTasks
                                .filter(t => !editingTask || t.id !== editingTask.id)
                                .map(t => (
                                    <option key={t.id} value={t.id}>
                                        {t.title} ({t.status})
                                    </option>
                                ))}
                        </Select>
                    </div>

                    <div className="grid grid-cols-2 gap-3">
                        <div>
                            <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                Estimated Budget ({event.currency || 'USD'})
                            </label>
                            <Input
                                type="number"
                                step="0.01"
                                placeholder="0.00"
                                value={taskForm.estimated_budget}
                                onChange={(e) => setTaskForm({ ...taskForm, estimated_budget: e.target.value })}
                            />
                        </div>
                        <div>
                            <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                Actual Budget Spend ({event.currency || 'USD'})
                            </label>
                            <Input
                                type="number"
                                step="0.01"
                                placeholder="0.00"
                                value={taskForm.actual_budget}
                                onChange={(e) => setTaskForm({ ...taskForm, actual_budget: e.target.value })}
                            />
                        </div>
                    </div>

                    <div className="flex justify-end gap-2 pt-3 border-t border-slate-100 dark:border-slate-800">
                        <Button variant="secondary" type="button" onClick={() => setTaskModalOpen(false)}>
                            Cancel
                        </Button>
                        <Button variant="primary" type="submit" disabled={savingTask}>
                            {savingTask ? 'Saving...' : (editingTask ? 'Update Task' : 'Create Task')}
                        </Button>
                    </div>
                </form>
            </Modal>

            {/* Confirm Delete Pillar Modal */}
            <ConfirmModal
                isOpen={!!deletePillarTarget}
                title="Delete Operational Pillar?"
                message={`Are you sure you want to delete the pillar "${deletePillarTarget?.name}"? All associated tasks will also be deleted.`}
                confirmLabel="Delete Pillar"
                variant="danger"
                onConfirm={handleDeletePillar}
                onCancel={() => setDeletePillarTarget(null)}
            />

            {/* Confirm Delete Task Modal */}
            <ConfirmModal
                isOpen={!!deleteTaskTarget}
                title="Delete Task?"
                message={`Are you sure you want to delete task "${deleteTaskTarget?.title}"?`}
                confirmLabel="Delete Task"
                variant="danger"
                onConfirm={handleDeleteTask}
                onCancel={() => setDeleteTaskTarget(null)}
            />
        </div>
    );
}
