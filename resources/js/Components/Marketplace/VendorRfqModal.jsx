import { useForm } from '@inertiajs/react';
import { X, Send, Sparkles, Building2, Calendar, Users, MapPin, Mail, Phone, User, FileText } from 'lucide-react';

export default function VendorRfqModal({ isOpen, onClose, listing }) {
    if (!isOpen || !listing) return null;

    const { data, setData, post, processing, errors, reset } = useForm({
        planner_name: '',
        planner_email: '',
        planner_phone: '',
        event_title: '',
        event_date: '',
        guest_count: '',
        location_address: '',
        requirements_description: '',
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        post(route('marketplace.quotes.rfq', { slug: listing.slug }), {
            onSuccess: () => {
                reset();
                onClose();
            },
        });
    };

    return (
        <div className="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 sm:p-6 animate-in fade-in duration-200">
            <div className="relative w-full max-w-2xl bg-white dark:bg-slate-900 rounded-2xl shadow-2xl border border-slate-200 dark:border-slate-800 overflow-hidden my-8">
                {/* Header */}
                <div className="flex items-center justify-between p-6 border-b border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-900/50">
                    <div className="flex items-center gap-3">
                        <div className="w-10 h-10 rounded-xl bg-primary-100 dark:bg-primary-950/60 flex items-center justify-center text-primary-600 dark:text-primary-400">
                            <Sparkles className="w-5 h-5" />
                        </div>
                        <div>
                            <h3 className="text-lg font-bold text-slate-900 dark:text-white">
                                Request a Customized Quote
                            </h3>
                            <p className="text-xs text-slate-500 dark:text-slate-400">
                                {listing.title} • {listing.shop?.name || 'Verified Vendor'}
                            </p>
                        </div>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="p-2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg transition-colors"
                    >
                        <X className="w-5 h-5" />
                    </button>
                </div>

                {/* Form */}
                <form onSubmit={handleSubmit} className="p-6 space-y-5">
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label className="block text-xs font-semibold uppercase tracking-wider text-slate-700 dark:text-slate-300 mb-1.5">
                                Your Full Name <span className="text-red-500">*</span>
                            </label>
                            <div className="relative">
                                <User className="w-4 h-4 absolute left-3 top-3 text-slate-400" />
                                <input
                                    type="text"
                                    required
                                    value={data.planner_name}
                                    onChange={(e) => setData('planner_name', e.target.value)}
                                    placeholder="e.g. Ama Mensah"
                                    className="w-full pl-9 pr-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 dark:text-white"
                                />
                            </div>
                            {errors.planner_name && (
                                <p className="text-xs text-red-500 mt-1">{errors.planner_name}</p>
                            )}
                        </div>

                        <div>
                            <label className="block text-xs font-semibold uppercase tracking-wider text-slate-700 dark:text-slate-300 mb-1.5">
                                Email Address <span className="text-red-500">*</span>
                            </label>
                            <div className="relative">
                                <Mail className="w-4 h-4 absolute left-3 top-3 text-slate-400" />
                                <input
                                    type="email"
                                    required
                                    value={data.planner_email}
                                    onChange={(e) => setData('planner_email', e.target.value)}
                                    placeholder="ama@example.com"
                                    className="w-full pl-9 pr-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 dark:text-white"
                                />
                            </div>
                            {errors.planner_email && (
                                <p className="text-xs text-red-500 mt-1">{errors.planner_email}</p>
                            )}
                        </div>
                    </div>

                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label className="block text-xs font-semibold uppercase tracking-wider text-slate-700 dark:text-slate-300 mb-1.5">
                                Phone Number
                            </label>
                            <div className="relative">
                                <Phone className="w-4 h-4 absolute left-3 top-3 text-slate-400" />
                                <input
                                    type="tel"
                                    value={data.planner_phone}
                                    onChange={(e) => setData('planner_phone', e.target.value)}
                                    placeholder="+233 24 123 4567"
                                    className="w-full pl-9 pr-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 dark:text-white"
                                />
                            </div>
                            {errors.planner_phone && (
                                <p className="text-xs text-red-500 mt-1">{errors.planner_phone}</p>
                            )}
                        </div>

                        <div>
                            <label className="block text-xs font-semibold uppercase tracking-wider text-slate-700 dark:text-slate-300 mb-1.5">
                                Event Date
                            </label>
                            <div className="relative">
                                <Calendar className="w-4 h-4 absolute left-3 top-3 text-slate-400" />
                                <input
                                    type="date"
                                    value={data.event_date}
                                    onChange={(e) => setData('event_date', e.target.value)}
                                    className="w-full pl-9 pr-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 dark:text-white"
                                />
                            </div>
                            {errors.event_date && (
                                <p className="text-xs text-red-500 mt-1">{errors.event_date}</p>
                            )}
                        </div>
                    </div>

                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label className="block text-xs font-semibold uppercase tracking-wider text-slate-700 dark:text-slate-300 mb-1.5">
                                Event Title
                            </label>
                            <input
                                type="text"
                                value={data.event_title}
                                onChange={(e) => setData('event_title', e.target.value)}
                                placeholder="e.g. Accra Tech Summit 2026"
                                className="w-full px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 dark:text-white"
                            />
                            {errors.event_title && (
                                <p className="text-xs text-red-500 mt-1">{errors.event_title}</p>
                            )}
                        </div>

                        <div>
                            <label className="block text-xs font-semibold uppercase tracking-wider text-slate-700 dark:text-slate-300 mb-1.5">
                                Expected Guest Count
                            </label>
                            <div className="relative">
                                <Users className="w-4 h-4 absolute left-3 top-3 text-slate-400" />
                                <input
                                    type="number"
                                    min="1"
                                    value={data.guest_count}
                                    onChange={(e) => setData('guest_count', e.target.value)}
                                    placeholder="500"
                                    className="w-full pl-9 pr-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 dark:text-white"
                                />
                            </div>
                            {errors.guest_count && (
                                <p className="text-xs text-red-500 mt-1">{errors.guest_count}</p>
                            )}
                        </div>
                    </div>

                    <div>
                        <label className="block text-xs font-semibold uppercase tracking-wider text-slate-700 dark:text-slate-300 mb-1.5">
                            Event Location / Venue Address
                        </label>
                        <div className="relative">
                            <MapPin className="w-4 h-4 absolute left-3 top-3 text-slate-400" />
                            <input
                                type="text"
                                value={data.location_address}
                                onChange={(e) => setData('location_address', e.target.value)}
                                placeholder="e.g. Accra International Conference Centre"
                                className="w-full pl-9 pr-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 dark:text-white"
                            />
                        </div>
                        {errors.location_address && (
                            <p className="text-xs text-red-500 mt-1">{errors.location_address}</p>
                        )}
                    </div>

                    <div>
                        <label className="block text-xs font-semibold uppercase tracking-wider text-slate-700 dark:text-slate-300 mb-1.5">
                            Requirements & Specific Items Needed <span className="text-red-500">*</span>
                        </label>
                        <textarea
                            required
                            rows="4"
                            value={data.requirements_description}
                            onChange={(e) => setData('requirements_description', e.target.value)}
                            placeholder="Describe your requirements: specific equipment packages, setup times, rigging needs, generator capacities, or catering preferences..."
                            className="w-full px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 dark:text-white"
                        />
                        {errors.requirements_description && (
                            <p className="text-xs text-red-500 mt-1">{errors.requirements_description}</p>
                        )}
                    </div>

                    {/* Footer Actions */}
                    <div className="flex items-center justify-end gap-3 pt-3 border-t border-slate-100 dark:border-slate-800">
                        <button
                            type="button"
                            onClick={onClose}
                            className="px-5 py-2.5 text-sm font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-xl transition-colors"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            disabled={processing}
                            className="inline-flex items-center gap-2 px-6 py-2.5 bg-primary-600 hover:bg-primary-700 text-white font-semibold text-sm rounded-xl shadow-lg shadow-primary-600/20 disabled:opacity-50 transition-colors"
                        >
                            {processing ? (
                                <span>Submitting RFQ...</span>
                            ) : (
                                <>
                                    <Send className="w-4 h-4" />
                                    <span>Send Request for Quote</span>
                                </>
                            )}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}
