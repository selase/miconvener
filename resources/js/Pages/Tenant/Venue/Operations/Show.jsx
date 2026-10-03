import { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import PageHeader from '@/Components/Console/PageHeader';
import Button from '@/Components/Console/Button';
import Modal from '@/Components/Console/Modal';

function Badge({ variant = 'neutral', children }) {
    const variants = {
        success:
            'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300 border-emerald-200 dark:border-emerald-800',
        danger: 'bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300 border-rose-200 dark:border-rose-800',
        warning:
            'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300 border-amber-200 dark:border-amber-800',
        primary:
            'bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300 border-blue-200 dark:border-blue-800',
        neutral:
            'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300 border-gray-200 dark:border-gray-700',
    };
    return (
        <span
            className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium border ${variants[variant] || variants.neutral}`}
        >
            {children}
        </span>
    );
}

function Card({ className = '', children, ...props }) {
    return (
        <div
            className={`rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-xs ${className}`}
            {...props}
        >
            {children}
        </div>
    );
}
import {
    CalendarDays,
    ClipboardCheck,
    MessageSquare,
    CheckCircle2,
    Clock,
    AlertTriangle,
    ArrowLeft,
    Send,
    Plus,
    User,
    Building2,
    FileText,
    Check,
    AlertCircle,
    Paperclip,
} from 'lucide-react';
import axios from 'axios';

export default function OperationsShow({
    booking,
    tasks: initialTasks,
    messages: initialMessages,
    inspections: initialInspections,
    defaultChecklists,
}) {
    const [activeTab, setActiveTab] = useState('tasks');
    const [tasks, setTasks] = useState(initialTasks || []);
    const [messages, setMessages] = useState(initialMessages || []);
    const [inspections, setInspections] = useState(initialInspections || []);

    // Create Task Modal state
    const [isTaskModalOpen, setIsTaskModalOpen] = useState(false);
    const [taskForm, setTaskForm] = useState({
        title: '',
        description: '',
        priority: 'medium',
        due_date: '',
    });
    const [isSubmittingTask, setIsSubmittingTask] = useState(false);

    // Message input state
    const [messageText, setMessageText] = useState('');
    const [messageAttachment, setMessageAttachment] = useState(null);
    const [isSendingMessage, setIsSendingMessage] = useState(false);

    // Walkthrough inspection state
    const [activeInspectionType, setActiveInspectionType] = useState('check_in');
    const [currentChecklist, setCurrentChecklist] = useState(defaultChecklists?.check_in || []);
    const [inspectorName, setInspectorName] = useState('');
    const [generalNotes, setGeneralNotes] = useState('');
    const [isSavingInspection, setIsSavingInspection] = useState(false);

    // Filter inspection for current type
    const activeInspection = inspections.find((i) => i.type === activeInspectionType);

    const handleCreateTask = async (e) => {
        e.preventDefault();
        if (!taskForm.title.trim()) return;

        setIsSubmittingTask(true);
        try {
            const res = await axios.post(
                route('tenant.venue.operations.tasks.create', booking.id),
                taskForm
            );
            if (res.data?.success && res.data.task) {
                setTasks([...tasks, res.data.task]);
                setTaskForm({ title: '', description: '', priority: 'medium', due_date: '' });
                setIsTaskModalOpen(false);
            }
        } catch (err) {
            alert(err.response?.data?.message || 'Failed to create task');
        } finally {
            setIsSubmittingTask(false);
        }
    };

    const handleToggleTaskStatus = async (taskId, currentStatus) => {
        const nextStatus = currentStatus === 'done' ? 'in_progress' : 'done';
        try {
            const res = await axios.patch(
                route('tenant.venue.operations.tasks.update-status', [booking.id, taskId]),
                {
                    status: nextStatus,
                }
            );
            if (res.data?.success) {
                setTasks(
                    tasks.map((t) =>
                        t.id === taskId
                            ? { ...t, status: nextStatus, completed_at: res.data.task.completed_at }
                            : t
                    )
                );
            }
        } catch (err) {
            console.error('Failed to update task status', err);
        }
    };

    const handleSendMessage = async (e) => {
        e.preventDefault();
        if (!messageText.trim() && !messageAttachment) return;

        setIsSendingMessage(true);
        const formData = new FormData();
        formData.append('message', messageText);
        if (messageAttachment) {
            formData.append('attachment', messageAttachment);
        }

        try {
            const res = await axios.post(
                route('tenant.venue.operations.messages.store', booking.id),
                formData,
                {
                    headers: { 'Content-Type': 'multipart/form-data' },
                }
            );
            if (res.data?.success && res.data.message) {
                setMessages([...messages, res.data.message]);
                setMessageText('');
                setMessageAttachment(null);
            }
        } catch (err) {
            alert(err.response?.data?.message || 'Failed to send message');
        } finally {
            setIsSendingMessage(false);
        }
    };

    const handleSaveInspection = async (signNow = false) => {
        if (!inspectorName.trim()) {
            alert('Please specify the inspector name.');
            return;
        }

        setIsSavingInspection(true);
        try {
            const res = await axios.post(
                route('tenant.venue.operations.inspections.store', booking.id),
                {
                    type: activeInspectionType,
                    inspector_name: inspectorName,
                    checklist: currentChecklist,
                    general_notes: generalNotes,
                    sign_now: signNow,
                }
            );
            if (res.data?.success && res.data.inspection) {
                setInspections([
                    ...inspections.filter((i) => i.type !== activeInspectionType),
                    res.data.inspection,
                ]);
            }
        } catch (err) {
            alert(err.response?.data?.message || 'Failed to record inspection log');
        } finally {
            setIsSavingInspection(false);
        }
    };

    const updateChecklistItem = (index, status, notes = null) => {
        const next = [...currentChecklist];
        next[index] = { ...next[index], status, notes: notes !== null ? notes : next[index].notes };
        setCurrentChecklist(next);
    };

    return (
        <ConsoleLayout>
            <Head title={`Facility Workspace: ${booking.booking_reference}`} />

            <div className="mb-4">
                <Link
                    href={route('tenant.venue.operations.index')}
                    className="inline-flex items-center text-xs font-medium text-gray-500 hover:text-gray-700 dark:hover:text-gray-300"
                >
                    <ArrowLeft className="h-3.5 w-3.5 mr-1" />
                    Back to Facility Operations Hub
                </Link>
            </div>

            <PageHeader
                title={`Facility Workspace: ${booking.booking_reference}`}
                actions={
                    <div className="flex items-center gap-2">
                        {booking.status === 'confirmed' ? (
                            <Badge variant="success">Confirmed Booking</Badge>
                        ) : (
                            <Badge variant="neutral">{booking.status}</Badge>
                        )}
                    </div>
                }
            />

            {/* Event Specs Banner */}
            <Card className="p-4 mt-6 bg-gradient-to-r from-blue-50/50 to-indigo-50/50 dark:from-blue-950/20 dark:to-indigo-950/20 border-blue-100 dark:border-blue-900/30">
                <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div>
                        <h2 className="text-lg font-bold text-gray-900 dark:text-white">
                            {booking.event?.name ||
                                `${booking.planner.name}'s ${booking.event_type}`}
                        </h2>
                        <div className="flex flex-wrap items-center gap-4 text-xs text-gray-600 dark:text-gray-400 mt-1">
                            <span className="flex items-center gap-1 font-medium text-gray-900 dark:text-white">
                                <Building2 className="h-3.5 w-3.5 text-blue-600" />
                                {booking.listing?.title || 'Main Space'}
                            </span>
                            <span>•</span>
                            <span className="flex items-center gap-1">
                                <CalendarDays className="h-3.5 w-3.5 text-gray-400" />
                                {new Date(booking.starts_at).toLocaleDateString(undefined, {
                                    weekday: 'short',
                                    month: 'short',
                                    day: 'numeric',
                                    year: 'numeric',
                                })}
                            </span>
                            <span>•</span>
                            <span className="flex items-center gap-1">
                                <Clock className="h-3.5 w-3.5 text-gray-400" />
                                {new Date(booking.starts_at).toLocaleTimeString([], {
                                    hour: '2-digit',
                                    minute: '2-digit',
                                })}{' '}
                                -{' '}
                                {new Date(booking.ends_at).toLocaleTimeString([], {
                                    hour: '2-digit',
                                    minute: '2-digit',
                                })}
                            </span>
                            <span>•</span>
                            <span>
                                {booking.guest_count} guests ({booking.layout_style})
                            </span>
                        </div>
                    </div>

                    <div className="text-xs text-right">
                        <div className="text-gray-500">Organizer Contact</div>
                        <div className="font-semibold text-gray-900 dark:text-white mt-0.5">
                            {booking.planner.name}
                        </div>
                        <div className="text-gray-500">
                            {booking.planner.email} • {booking.planner.phone}
                        </div>
                    </div>
                </div>
            </Card>

            {/* Navigation Tabs */}
            <div className="flex items-center gap-2 border-b border-gray-200 dark:border-gray-800 mt-6">
                <button
                    onClick={() => setActiveTab('tasks')}
                    className={`px-4 py-2.5 text-sm font-medium border-b-2 transition-colors flex items-center gap-2 ${
                        activeTab === 'tasks'
                            ? 'border-blue-600 text-blue-600 dark:text-blue-400'
                            : 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300'
                    }`}
                >
                    <ClipboardCheck className="h-4 w-4" />
                    Facility & Setup Tasks
                    <Badge variant="neutral">{tasks.length}</Badge>
                </button>
                <button
                    onClick={() => setActiveTab('messages')}
                    className={`px-4 py-2.5 text-sm font-medium border-b-2 transition-colors flex items-center gap-2 ${
                        activeTab === 'messages'
                            ? 'border-blue-600 text-blue-600 dark:text-blue-400'
                            : 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300'
                    }`}
                >
                    <MessageSquare className="h-4 w-4" />
                    Coordination Thread
                    <Badge variant="neutral">{messages.length}</Badge>
                </button>
                <button
                    onClick={() => setActiveTab('inspections')}
                    className={`px-4 py-2.5 text-sm font-medium border-b-2 transition-colors flex items-center gap-2 ${
                        activeTab === 'inspections'
                            ? 'border-blue-600 text-blue-600 dark:text-blue-400'
                            : 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300'
                    }`}
                >
                    <FileText className="h-4 w-4" />
                    Walkthrough Inspections
                    <Badge variant="neutral">{inspections.length}/2</Badge>
                </button>
            </div>

            {/* TAB CONTENT: TASKS */}
            {activeTab === 'tasks' && (
                <div className="space-y-4 mt-6">
                    <div className="flex items-center justify-between">
                        <div>
                            <h3 className="text-base font-semibold text-gray-900 dark:text-white">
                                Venue & Logistics Checklist
                            </h3>
                            <p className="text-xs text-gray-500">
                                Pre-event setup tasks delegated to your facility management team
                            </p>
                        </div>
                        <Button variant="default" onClick={() => setIsTaskModalOpen(true)}>
                            <Plus className="h-4 w-4 mr-1.5" />
                            Add Facility Task
                        </Button>
                    </div>

                    <Card className="divide-y divide-gray-100 dark:divide-gray-800">
                        {tasks.length === 0 ? (
                            <div className="p-8 text-center">
                                <ClipboardCheck className="h-10 w-10 text-gray-300 dark:text-gray-600 mx-auto mb-2" />
                                <div className="text-sm font-medium text-gray-900 dark:text-white">
                                    No facility tasks added yet
                                </div>
                                <p className="text-xs text-gray-500 mt-1 max-w-sm mx-auto">
                                    Track hall air conditioning pre-cooling, mic tests, stage setup,
                                    and podium preparations for this event.
                                </p>
                            </div>
                        ) : (
                            tasks.map((task) => (
                                <div
                                    key={task.id}
                                    className="p-4 flex items-center justify-between gap-4 hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors"
                                >
                                    <div className="flex items-start gap-3">
                                        <button
                                            onClick={() =>
                                                handleToggleTaskStatus(task.id, task.status)
                                            }
                                            className={`mt-0.5 h-5 w-5 rounded border flex items-center justify-center transition-colors ${
                                                task.status === 'done'
                                                    ? 'bg-emerald-600 border-emerald-600 text-white'
                                                    : 'border-gray-300 dark:border-gray-600 hover:border-blue-600'
                                            }`}
                                        >
                                            {task.status === 'done' && (
                                                <Check className="h-3.5 w-3.5" />
                                            )}
                                        </button>
                                        <div>
                                            <div
                                                className={`text-sm font-medium ${
                                                    task.status === 'done'
                                                        ? 'line-through text-gray-400 dark:text-gray-500'
                                                        : 'text-gray-900 dark:text-white'
                                                }`}
                                            >
                                                {task.title}
                                            </div>
                                            {task.description && (
                                                <div className="text-xs text-gray-500 mt-0.5">
                                                    {task.description}
                                                </div>
                                            )}
                                        </div>
                                    </div>

                                    <div className="flex items-center gap-3">
                                        {task.priority === 'urgent' && (
                                            <Badge variant="danger">Urgent</Badge>
                                        )}
                                        {task.priority === 'high' && (
                                            <Badge variant="warning">High Priority</Badge>
                                        )}
                                        {task.due_date && (
                                            <span className="text-xs text-gray-500 flex items-center gap-1">
                                                <Clock className="h-3 w-3" />
                                                Due {task.due_date}
                                            </span>
                                        )}
                                        {task.status === 'done' ? (
                                            <Badge variant="success">Completed</Badge>
                                        ) : (
                                            <Badge variant="neutral">In Progress</Badge>
                                        )}
                                    </div>
                                </div>
                            ))
                        )}
                    </Card>
                </div>
            )}

            {/* TAB CONTENT: MESSAGES */}
            {activeTab === 'messages' && (
                <div className="space-y-4 mt-6">
                    <Card className="p-4">
                        <div className="h-96 overflow-y-auto space-y-4 pr-2">
                            {messages.length === 0 ? (
                                <div className="h-full flex flex-col items-center justify-center text-center">
                                    <MessageSquare className="h-10 w-10 text-gray-300 dark:text-gray-600 mb-2" />
                                    <div className="text-sm font-medium text-gray-900 dark:text-white">
                                        Coordination thread is empty
                                    </div>
                                    <p className="text-xs text-gray-500 mt-1 max-w-sm">
                                        Send updates, floor plan confirmations, and special
                                        equipment notices directly to the organizer.
                                    </p>
                                </div>
                            ) : (
                                messages.map((msg) => {
                                    const isMe = msg.sender_type === 'host';
                                    return (
                                        <div
                                            key={msg.id}
                                            className={`flex flex-col ${isMe ? 'items-end' : 'items-start'}`}
                                        >
                                            <div className="flex items-center gap-1.5 text-xs text-gray-500 mb-1">
                                                <span className="font-semibold text-gray-700 dark:text-gray-300">
                                                    {msg.sender_name}
                                                </span>
                                                <span>•</span>
                                                <span>
                                                    {new Date(msg.created_at).toLocaleTimeString(
                                                        [],
                                                        { hour: '2-digit', minute: '2-digit' }
                                                    )}
                                                </span>
                                            </div>
                                            <div
                                                className={`max-w-lg p-3 rounded-2xl text-sm ${
                                                    isMe
                                                        ? 'bg-blue-600 text-white rounded-br-xs'
                                                        : 'bg-gray-100 dark:bg-gray-800 text-gray-900 dark:text-white rounded-bl-xs'
                                                }`}
                                            >
                                                <p className="whitespace-pre-wrap">{msg.message}</p>
                                                {msg.attachment_url && (
                                                    <a
                                                        href={msg.attachment_url}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className={`inline-flex items-center gap-1 text-xs underline mt-2 ${
                                                            isMe
                                                                ? 'text-blue-100'
                                                                : 'text-blue-600 dark:text-blue-400'
                                                        }`}
                                                    >
                                                        <Paperclip className="h-3.5 w-3.5" />
                                                        View Attachment
                                                    </a>
                                                )}
                                            </div>
                                        </div>
                                    );
                                })
                            )}
                        </div>

                        <form
                            onSubmit={handleSendMessage}
                            className="mt-4 pt-4 border-t border-gray-100 dark:border-gray-800"
                        >
                            <div className="flex items-center gap-2">
                                <input
                                    type="text"
                                    value={messageText}
                                    onChange={(e) => setMessageText(e.target.value)}
                                    placeholder="Type message to the event organizer..."
                                    className="flex-1 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 px-3 py-2 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-600"
                                />
                                <label className="cursor-pointer p-2 rounded-lg text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">
                                    <Paperclip className="h-5 w-5" />
                                    <input
                                        type="file"
                                        className="hidden"
                                        onChange={(e) =>
                                            setMessageAttachment(e.target.files?.[0] || null)
                                        }
                                    />
                                </label>
                                <Button variant="primary" type="submit" disabled={isSendingMessage}>
                                    <Send className="h-4 w-4 mr-1.5" />
                                    Send
                                </Button>
                            </div>
                            {messageAttachment && (
                                <div className="text-xs text-blue-600 dark:text-blue-400 mt-2 flex items-center gap-1">
                                    <Paperclip className="h-3 w-3" />
                                    Attached: {messageAttachment.name}
                                    <button
                                        type="button"
                                        onClick={() => setMessageAttachment(null)}
                                        className="text-red-500 ml-2 hover:underline"
                                    >
                                        Remove
                                    </button>
                                </div>
                            )}
                        </form>
                    </Card>
                </div>
            )}

            {/* TAB CONTENT: INSPECTIONS */}
            {activeTab === 'inspections' && (
                <div className="space-y-4 mt-6">
                    <div className="flex items-center gap-2 border-b border-gray-100 dark:border-gray-800 pb-3">
                        <button
                            onClick={() => {
                                setActiveInspectionType('check_in');
                                setCurrentChecklist(
                                    inspections.find((i) => i.type === 'check_in')?.checklist ||
                                        defaultChecklists?.check_in ||
                                        []
                                );
                            }}
                            className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors ${
                                activeInspectionType === 'check_in'
                                    ? 'bg-blue-600 text-white'
                                    : 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300'
                            }`}
                        >
                            Pre-Event Check-In Walkthrough
                        </button>
                        <button
                            onClick={() => {
                                setActiveInspectionType('check_out');
                                setCurrentChecklist(
                                    inspections.find((i) => i.type === 'check_out')?.checklist ||
                                        defaultChecklists?.check_out ||
                                        []
                                );
                            }}
                            className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors ${
                                activeInspectionType === 'check_out'
                                    ? 'bg-blue-600 text-white'
                                    : 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300'
                            }`}
                        >
                            Post-Event Check-Out Walkthrough
                        </button>
                    </div>

                    <Card className="p-4 space-y-4">
                        <div className="flex items-center justify-between">
                            <div>
                                <h4 className="text-sm font-bold text-gray-900 dark:text-white">
                                    {activeInspectionType === 'check_in'
                                        ? 'Pre-Event Room Handover & Equipment Check'
                                        : 'Post-Event Space Handover & Condition Log'}
                                </h4>
                                <p className="text-xs text-gray-500">
                                    Joint facility verification between venue operations and
                                    organizer
                                </p>
                            </div>
                            {activeInspection?.is_signed ? (
                                <Badge variant="success">
                                    Signed by {activeInspection.signed_by_name} (
                                    {new Date(activeInspection.signed_at).toLocaleDateString()})
                                </Badge>
                            ) : (
                                <Badge variant="warning">Awaiting Joint Sign-Off</Badge>
                            )}
                        </div>

                        {/* Checklist Items */}
                        <div className="divide-y divide-gray-100 dark:divide-gray-800 border border-gray-100 dark:border-gray-800 rounded-xl overflow-hidden">
                            {currentChecklist.map((item, index) => (
                                <div
                                    key={index}
                                    className="p-3 flex items-center justify-between gap-4 text-xs"
                                >
                                    <div className="font-medium text-gray-900 dark:text-white flex items-center gap-2">
                                        <span>{item.item}</span>
                                    </div>
                                    <div className="flex items-center gap-1.5">
                                        <button
                                            type="button"
                                            disabled={activeInspection?.is_signed}
                                            onClick={() => updateChecklistItem(index, 'good')}
                                            className={`px-2.5 py-1 rounded text-xs font-medium transition-colors ${
                                                item.status === 'good'
                                                    ? 'bg-emerald-600 text-white'
                                                    : 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400'
                                            }`}
                                        >
                                            Good / Ready
                                        </button>
                                        <button
                                            type="button"
                                            disabled={activeInspection?.is_signed}
                                            onClick={() => updateChecklistItem(index, 'damaged')}
                                            className={`px-2.5 py-1 rounded text-xs font-medium transition-colors ${
                                                item.status === 'damaged'
                                                    ? 'bg-rose-600 text-white'
                                                    : 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400'
                                            }`}
                                        >
                                            Defect / Flagged
                                        </button>
                                        <button
                                            type="button"
                                            disabled={activeInspection?.is_signed}
                                            onClick={() =>
                                                updateChecklistItem(index, 'not_applicable')
                                            }
                                            className={`px-2.5 py-1 rounded text-xs font-medium transition-colors ${
                                                item.status === 'not_applicable'
                                                    ? 'bg-gray-600 text-white'
                                                    : 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400'
                                            }`}
                                        >
                                            N/A
                                        </button>
                                    </div>
                                </div>
                            ))}
                        </div>

                        {/* General Notes & Sign-off */}
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4 pt-2">
                            <div>
                                <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                    General Observations / Defect Notes
                                </label>
                                <textarea
                                    disabled={activeInspection?.is_signed}
                                    value={generalNotes || activeInspection?.general_notes || ''}
                                    onChange={(e) => setGeneralNotes(e.target.value)}
                                    rows={3}
                                    placeholder="e.g. Scratches on podium front panel noted prior to event start..."
                                    className="w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 p-2.5 text-xs text-gray-900 dark:text-white"
                                />
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                    Inspector / Facility Supervisor Name
                                </label>
                                <input
                                    type="text"
                                    disabled={activeInspection?.is_signed}
                                    value={inspectorName || activeInspection?.inspector_name || ''}
                                    onChange={(e) => setInspectorName(e.target.value)}
                                    placeholder="e.g. Kwame Mensah (UGMC Operations Lead)"
                                    className="w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 p-2.5 text-xs text-gray-900 dark:text-white"
                                />

                                {!activeInspection?.is_signed && (
                                    <div className="flex items-center gap-2 mt-4">
                                        <Button
                                            variant="default"
                                            onClick={() => handleSaveInspection(false)}
                                            disabled={isSavingInspection}
                                        >
                                            Save Draft Log
                                        </Button>
                                        <Button
                                            variant="primary"
                                            onClick={() => handleSaveInspection(true)}
                                            disabled={isSavingInspection}
                                        >
                                            Sign & Finalize Walkthrough
                                        </Button>
                                    </div>
                                )}
                            </div>
                        </div>
                    </Card>
                </div>
            )}

            {/* Create Task Modal */}
            <Modal
                open={isTaskModalOpen}
                onClose={() => setIsTaskModalOpen(false)}
                title="Create Facility Setup Task"
            >
                <form onSubmit={handleCreateTask} className="space-y-4">
                    <div>
                        <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                            Task Title
                        </label>
                        <input
                            type="text"
                            required
                            placeholder="e.g. Pre-cool Auditorium A to 20C by 06:00"
                            value={taskForm.title}
                            onChange={(e) => setTaskForm({ ...taskForm, title: e.target.value })}
                            className="w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 px-3 py-2 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-600"
                        />
                    </div>

                    <div>
                        <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                            Description / Instructions
                        </label>
                        <textarea
                            rows={3}
                            placeholder="Specific instructions for facility technicians..."
                            value={taskForm.description}
                            onChange={(e) =>
                                setTaskForm({ ...taskForm, description: e.target.value })
                            }
                            className="w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 px-3 py-2 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-600"
                        />
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                                Priority
                            </label>
                            <select
                                value={taskForm.priority}
                                onChange={(e) =>
                                    setTaskForm({ ...taskForm, priority: e.target.value })
                                }
                                className="w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 px-3 py-2 text-sm text-gray-900 dark:text-white"
                            >
                                <option value="low">Low</option>
                                <option value="medium">Medium</option>
                                <option value="high">High</option>
                                <option value="urgent">Urgent</option>
                            </select>
                        </div>
                        <div>
                            <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                                Due Date
                            </label>
                            <input
                                type="date"
                                value={taskForm.due_date}
                                onChange={(e) =>
                                    setTaskForm({ ...taskForm, due_date: e.target.value })
                                }
                                className="w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 px-3 py-2 text-sm text-gray-900 dark:text-white"
                            />
                        </div>
                    </div>

                    <div className="flex justify-end gap-2 pt-2 border-t border-gray-100 dark:border-gray-800">
                        <Button
                            variant="default"
                            type="button"
                            onClick={() => setIsTaskModalOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button variant="primary" type="submit" disabled={isSubmittingTask}>
                            {isSubmittingTask ? 'Creating...' : 'Create Task'}
                        </Button>
                    </div>
                </form>
            </Modal>
        </ConsoleLayout>
    );
}
