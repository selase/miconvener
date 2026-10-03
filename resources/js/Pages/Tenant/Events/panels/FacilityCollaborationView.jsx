import { useState, useEffect } from 'react';
import Button from '@/Components/Console/Button';

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
    Building2,
    CalendarDays,
    ClipboardCheck,
    MessageSquare,
    FileText,
    Check,
    Send,
    Paperclip,
    Clock,
    Phone,
    Mail,
    MapPin,
    AlertCircle,
} from 'lucide-react';
import axios from 'axios';

export default function FacilityCollaborationView({ event }) {
    const [loading, setLoading] = useState(true);
    const [data, setData] = useState(null);
    const [activeTab, setActiveTab] = useState('tasks');

    // Message input state
    const [messageText, setMessageText] = useState('');
    const [messageAttachment, setMessageAttachment] = useState(null);
    const [isSendingMessage, setIsSendingMessage] = useState(false);

    // Inspection sign-off state
    const [activeInspectionType, setActiveInspectionType] = useState('check_in');
    const [inspectorName, setInspectorName] = useState('');
    const [isSigningInspection, setIsSigningInspection] = useState(false);

    const loadCollaborationData = async () => {
        try {
            setLoading(true);
            const res = await axios.get(route('tenant.events.venue-collaboration.show', event.id));
            setData(res.data);
        } catch (err) {
            console.error('Failed to load facility collaboration data', err);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        loadCollaborationData();
    }, [event.id]);

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
                route('tenant.events.venue-collaboration.messages.store', event.id),
                formData,
                {
                    headers: { 'Content-Type': 'multipart/form-data' },
                }
            );
            if (res.data?.success && res.data.message) {
                setData((prev) => ({
                    ...prev,
                    messages: [...prev.messages, res.data.message],
                }));
                setMessageText('');
                setMessageAttachment(null);
            }
        } catch (err) {
            alert(err.response?.data?.message || 'Failed to send message');
        } finally {
            setIsSendingMessage(false);
        }
    };

    const handleSignInspection = async () => {
        if (!inspectorName.trim()) {
            alert('Please enter your name to sign the inspection.');
            return;
        }

        setIsSigningInspection(true);
        try {
            const res = await axios.post(
                route('tenant.events.venue-collaboration.inspections.store', event.id),
                {
                    type: activeInspectionType,
                    inspector_name: inspectorName,
                    sign_now: true,
                }
            );
            if (res.data?.success && res.data.inspection) {
                setData((prev) => ({
                    ...prev,
                    inspections: [
                        ...prev.inspections.filter((i) => i.type !== activeInspectionType),
                        res.data.inspection,
                    ],
                }));
            }
        } catch (err) {
            alert(err.response?.data?.message || 'Failed to sign inspection');
        } finally {
            setIsSigningInspection(false);
        }
    };

    if (loading) {
        return (
            <div className="p-8 text-center text-sm text-gray-500">
                Loading venue facility collaboration workspace...
            </div>
        );
    }

    if (!data?.has_venue) {
        return (
            <Card className="p-8 text-center">
                <Building2 className="h-10 w-10 text-gray-300 dark:text-gray-600 mx-auto mb-2" />
                <h3 className="text-base font-semibold text-gray-900 dark:text-white">
                    No Venue Marketplace Booking Attached
                </h3>
                <p className="text-xs text-gray-500 mt-1 max-w-md mx-auto">
                    When you book a rentable space through the MiConvener Venue Marketplace, your
                    facility management workspace (air conditioning, podiums, AV checks, and
                    check-in inspections) will appear here.
                </p>
            </Card>
        );
    }

    const { booking, tasks = [], messages = [], inspections = [], defaultChecklists } = data;
    const activeInspection = inspections.find((i) => i.type === activeInspectionType);

    return (
        <div className="space-y-6">
            {/* Host Partner Header Card */}
            <Card className="p-5 bg-gradient-to-r from-blue-50/40 to-indigo-50/40 dark:from-blue-950/20 dark:to-indigo-950/20 border-blue-100 dark:border-blue-900/30">
                <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-2">
                            <Building2 className="h-5 w-5 text-blue-600 dark:text-blue-400" />
                            <h3 className="text-lg font-bold text-gray-900 dark:text-white">
                                {booking.venue_name}
                            </h3>
                            <Badge variant="success">Venue Host Partner</Badge>
                        </div>
                        <div className="text-xs text-gray-600 dark:text-gray-400 mt-1 flex flex-wrap items-center gap-3">
                            <span className="font-medium text-gray-900 dark:text-white">
                                {booking.space_title}
                            </span>
                            <span>•</span>
                            <span>Booking #{booking.booking_reference}</span>
                            <span>•</span>
                            <span className="flex items-center gap-1">
                                <Clock className="h-3.5 w-3.5 text-gray-400" />
                                {new Date(booking.starts_at).toLocaleDateString()}
                            </span>
                        </div>
                    </div>

                    <div className="flex flex-col md:items-end text-xs text-gray-500 space-y-1">
                        {booking.contact?.phone && (
                            <div className="flex items-center gap-1">
                                <Phone className="h-3.5 w-3.5" />
                                {booking.contact.phone}
                            </div>
                        )}
                        {booking.contact?.email && (
                            <div className="flex items-center gap-1">
                                <Mail className="h-3.5 w-3.5" />
                                {booking.contact.email}
                            </div>
                        )}
                        {booking.contact?.address && (
                            <div className="flex items-center gap-1">
                                <MapPin className="h-3.5 w-3.5" />
                                {booking.contact.address}
                            </div>
                        )}
                    </div>
                </div>
            </Card>

            {/* Navigation Tabs */}
            <div className="flex items-center gap-2 border-b border-gray-200 dark:border-gray-800">
                <button
                    onClick={() => setActiveTab('tasks')}
                    className={`px-4 py-2.5 text-sm font-medium border-b-2 transition-colors flex items-center gap-2 ${
                        activeTab === 'tasks'
                            ? 'border-blue-600 text-blue-600 dark:text-blue-400'
                            : 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300'
                    }`}
                >
                    <ClipboardCheck className="h-4 w-4" />
                    Facility Setup Tasks
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
                    Host Coordination Chat
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
                    Walkthrough Inspection Logs
                    <Badge variant="neutral">{inspections.length}/2</Badge>
                </button>
            </div>

            {/* TAB CONTENT: TASKS */}
            {activeTab === 'tasks' && (
                <div className="space-y-4">
                    <Card className="divide-y divide-gray-100 dark:divide-gray-800">
                        {tasks.length === 0 ? (
                            <div className="p-8 text-center">
                                <ClipboardCheck className="h-10 w-10 text-gray-300 dark:text-gray-600 mx-auto mb-2" />
                                <div className="text-sm font-medium text-gray-900 dark:text-white">
                                    No facility tasks recorded
                                </div>
                                <p className="text-xs text-gray-500 mt-1 max-w-sm mx-auto">
                                    Tasks added under the Venue & Logistics operational pillar will
                                    be coordinated here with {booking.venue_name}.
                                </p>
                            </div>
                        ) : (
                            tasks.map((task) => (
                                <div
                                    key={task.id}
                                    className="p-4 flex items-center justify-between gap-4 hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors"
                                >
                                    <div className="flex items-start gap-3">
                                        <div
                                            className={`mt-0.5 h-5 w-5 rounded border flex items-center justify-center ${
                                                task.status === 'done'
                                                    ? 'bg-emerald-600 border-emerald-600 text-white'
                                                    : 'border-gray-300 dark:border-gray-600'
                                            }`}
                                        >
                                            {task.status === 'done' && (
                                                <Check className="h-3.5 w-3.5" />
                                            )}
                                        </div>
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
                                        {task.is_venue_task && (
                                            <Badge variant="primary">Assigned to Venue Host</Badge>
                                        )}
                                        {task.priority === 'urgent' && (
                                            <Badge variant="danger">Urgent</Badge>
                                        )}
                                        {task.priority === 'high' && (
                                            <Badge variant="warning">High Priority</Badge>
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
                <div className="space-y-4">
                    <Card className="p-4">
                        <div className="h-96 overflow-y-auto space-y-4 pr-2">
                            {messages.length === 0 ? (
                                <div className="h-full flex flex-col items-center justify-center text-center">
                                    <MessageSquare className="h-10 w-10 text-gray-300 dark:text-gray-600 mb-2" />
                                    <div className="text-sm font-medium text-gray-900 dark:text-white">
                                        No messages in coordination thread
                                    </div>
                                    <p className="text-xs text-gray-500 mt-1 max-w-sm">
                                        Chat directly with {booking.venue_name} about stage
                                        dimensions, sound checks, loading dock access, and event day
                                        timing.
                                    </p>
                                </div>
                            ) : (
                                messages.map((msg) => {
                                    const isMe = msg.sender_type === 'planner';
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
                                    placeholder={`Message ${booking.venue_name} facility team...`}
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
                <div className="space-y-4">
                    <div className="flex items-center gap-2 border-b border-gray-100 dark:border-gray-800 pb-3">
                        <button
                            onClick={() => setActiveInspectionType('check_in')}
                            className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors ${
                                activeInspectionType === 'check_in'
                                    ? 'bg-blue-600 text-white'
                                    : 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300'
                            }`}
                        >
                            Pre-Event Check-In Walkthrough
                        </button>
                        <button
                            onClick={() => setActiveInspectionType('check_out')}
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

                        {/* Checklist Display */}
                        <div className="divide-y divide-gray-100 dark:divide-gray-800 border border-gray-100 dark:border-gray-800 rounded-xl overflow-hidden">
                            {(
                                activeInspection?.checklist ||
                                defaultChecklists?.[activeInspectionType] ||
                                []
                            ).map((item, index) => (
                                <div
                                    key={index}
                                    className="p-3 flex items-center justify-between gap-4 text-xs"
                                >
                                    <div className="font-medium text-gray-900 dark:text-white">
                                        {item.item}
                                    </div>
                                    <div>
                                        {item.status === 'good' && (
                                            <Badge variant="success">Good / Ready</Badge>
                                        )}
                                        {item.status === 'damaged' && (
                                            <Badge variant="danger">Defect / Flagged</Badge>
                                        )}
                                        {item.status === 'not_applicable' && (
                                            <Badge variant="neutral">N/A</Badge>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>

                        {activeInspection?.general_notes && (
                            <div className="p-3 rounded-lg bg-gray-50 dark:bg-gray-800/50 text-xs">
                                <span className="font-semibold text-gray-700 dark:text-gray-300">
                                    Notes:{' '}
                                </span>
                                <span className="text-gray-600 dark:text-gray-400">
                                    {activeInspection.general_notes}
                                </span>
                            </div>
                        )}

                        {/* Organizer Sign-off action */}
                        {!activeInspection?.is_signed && (
                            <div className="pt-2 border-t border-gray-100 dark:border-gray-800 flex items-center gap-3">
                                <input
                                    type="text"
                                    value={inspectorName}
                                    onChange={(e) => setInspectorName(e.target.value)}
                                    placeholder="Enter your full name to sign inspection report..."
                                    className="flex-1 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 px-3 py-2 text-xs text-gray-900 dark:text-white"
                                />
                                <Button
                                    variant="primary"
                                    onClick={handleSignInspection}
                                    disabled={isSigningInspection}
                                >
                                    Sign Walkthrough Report
                                </Button>
                            </div>
                        )}
                    </Card>
                </div>
            )}
        </div>
    );
}
