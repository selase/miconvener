import { useEffect, useState } from 'react';
import Modal from '@/Components/Console/Modal';
import csrfFetch from '@/lib/csrfFetch';
import { AlertCircle, Check, Loader2 } from 'lucide-react';

export default function EditTributeModal({ open, onClose, contribution, onSuccess }) {
    const [tributeMessage, setTributeMessage] = useState('');
    const [isAnonymous, setIsAnonymous] = useState(false);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);

    useEffect(() => {
        if (contribution) {
            setTributeMessage(contribution.tribute_message || '');
            setIsAnonymous(Boolean(contribution.is_anonymous));
            setError(null);
        }
    }, [contribution, open]);

    if (!contribution) {
        return null;
    }

    const handleSubmit = async (e) => {
        e.preventDefault();
        setLoading(true);
        setError(null);

        try {
            const url = window.route
                ? route('attendee.my.contributions.tribute', { contribution: contribution.id })
                : `/my/contributions/${contribution.id}/tribute`;

            const res = await csrfFetch(url, {
                method: 'PATCH',
                body: JSON.stringify({
                    tribute_message: tributeMessage,
                    is_anonymous: isAnonymous,
                }),
            });

            if (!res.ok) {
                const data = await res.json().catch(() => ({}));
                setError(data.message || 'Failed to update tribute message.');
                setLoading(false);
                return;
            }

            const data = await res.json();
            if (onSuccess) {
                onSuccess(data.contribution);
            }
            onClose();
        } catch {
            setError('A network error occurred. Please check your connection.');
        } finally {
            setLoading(false);
        }
    };

    return (
        <Modal open={open} onClose={onClose} title="Edit Message & Preferences" className="max-w-lg">
            <form onSubmit={handleSubmit} className="space-y-4">
                {error && (
                    <div className="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 p-3 text-xs text-red-700 dark:border-red-900/50 dark:bg-red-950/20 dark:text-red-400">
                        <AlertCircle className="h-4 w-4 shrink-0 mt-0.5" />
                        <span>{error}</span>
                    </div>
                )}

                <div className="space-y-1.5">
                    <div className="flex items-center justify-between">
                        <label
                            htmlFor="tribute_message"
                            className="block text-xs font-semibold text-ink"
                        >
                            Tribute / Condolence / Blessing Note
                        </label>
                        <span className="text-[11px] text-ink-secondary">
                            {tributeMessage.length}/1000 characters
                        </span>
                    </div>
                    <textarea
                        id="tribute_message"
                        rows={4}
                        maxLength={1000}
                        value={tributeMessage}
                        onChange={(e) => setTributeMessage(e.target.value)}
                        placeholder="Leave a word of prayer, remembrance, or heartfelt note to display on the event wall..."
                        className="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink placeholder-ink-secondary/60 focus:border-accent focus:outline-hidden focus:ring-1 focus:ring-accent"
                    />
                    <p className="text-[11px] text-ink-secondary">
                        Organizers may review condolence and tribute messages before they appear on the public wall.
                    </p>
                </div>

                <div className="rounded-lg border border-border bg-surface-subtle p-3.5 space-y-2">
                    <label className="flex items-start gap-2.5 cursor-pointer">
                        <input
                            type="checkbox"
                            checked={isAnonymous}
                            onChange={(e) => setIsAnonymous(e.target.checked)}
                            className="mt-0.5 h-4 w-4 rounded border-border text-accent focus:ring-accent cursor-pointer"
                        />
                        <div className="space-y-0.5">
                            <span className="block text-xs font-medium text-ink">
                                Display contribution as Anonymous
                            </span>
                            <span className="block text-[11px] text-ink-secondary">
                                When checked, your name is hidden from the public tribute wall and donor listings. Your email remains verified on your official receipt.
                            </span>
                        </div>
                    </label>
                </div>

                <div className="flex items-center justify-end gap-2.5 pt-2 border-t border-border">
                    <button
                        type="button"
                        onClick={onClose}
                        disabled={loading}
                        className="rounded-lg border border-border px-3.5 py-2 text-xs font-medium text-ink hover:bg-surface-subtle transition-colors cursor-pointer"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        disabled={loading}
                        className="inline-flex items-center gap-1.5 rounded-lg bg-accent px-4 py-2 text-xs font-medium text-white shadow-xs hover:bg-accent/90 transition-colors disabled:opacity-50 cursor-pointer"
                    >
                        {loading ? (
                            <>
                                <Loader2 className="h-3.5 w-3.5 animate-spin" />
                                <span>Saving...</span>
                            </>
                        ) : (
                            <>
                                <Check className="h-3.5 w-3.5" />
                                <span>Save Changes</span>
                            </>
                        )}
                    </button>
                </div>
            </form>
        </Modal>
    );
}

