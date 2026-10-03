import { useState, useEffect } from 'react';
import { router } from '@inertiajs/react';
import Modal from '@/Components/Console/Modal';
import Button from '@/Components/Console/Button';
import { AlertTriangle, Trash2 } from 'lucide-react';

export default function DeleteEventModal({
    open,
    onClose,
    event,
    isForcePurge = false,
}) {
    const [confirmName, setConfirmName] = useState('');
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState(null);

    useEffect(() => {
        if (open) {
            setConfirmName('');
            setError(null);
            setProcessing(false);
        }
    }, [open]);

    if (!event) return null;

    const isMatch = confirmName.trim() === event.name?.trim();

    const handleSubmit = (e) => {
        e.preventDefault();
        if (!isMatch || processing) return;

        setProcessing(true);
        setError(null);

        const routeUrl = isForcePurge
            ? route('tenant.events.force-purge', { event: event.id })
            : route('tenant.events.destroy', { event: event.id });

        router.delete(routeUrl, {
            data: { confirm_name: confirmName },
            preserveScroll: true,
            onSuccess: () => {
                setProcessing(false);
                onClose();
            },
            onError: (errs) => {
                setProcessing(false);
                setError(errs.confirm_name || Object.values(errs)[0] || 'Failed to delete event.');
            },
        });
    };

    return (
        <Modal
            open={open}
            onClose={onClose}
            title={isForcePurge ? 'Permanently Purge Event' : 'Delete Event'}
            className="max-w-md"
        >
            <form onSubmit={handleSubmit} className="space-y-4">
                <div className="flex items-start gap-3 rounded-lg border border-rose-500/20 bg-rose-500/10 p-3 text-xs text-rose-700 dark:text-rose-400">
                    <AlertTriangle className="h-4 w-4 shrink-0 mt-0.5" />
                    <div>
                        {isForcePurge ? (
                            <p>
                                <strong>Warning: Permanent Destruction.</strong> This will immediately and permanently erase <strong>"{event.name}"</strong>, including all registration records, materials, and configurations. This action cannot be undone.
                            </p>
                        ) : (
                            <p>
                                <strong>6-Hour Recovery Safeguard Active:</strong> Deleting this event will suspend public access immediately. You will have a <strong>6-hour recovery window</strong> to restore this event with one click before permanent automated purge.
                            </p>
                        )}
                    </div>
                </div>

                <div className="space-y-1.5">
                    <label className="block text-xs font-medium text-ink">
                        Please type <strong className="text-ink select-all">"{event.name}"</strong> to confirm:
                    </label>
                    <input
                        type="text"
                        value={confirmName}
                        onChange={(e) => setConfirmName(e.target.value)}
                        placeholder="Type event title exactly"
                        className="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink placeholder:text-ink-tertiary focus:border-ink focus:outline-none"
                        autoFocus
                    />
                    {error && <div className="text-[11px] text-rose-600 dark:text-rose-400">{error}</div>}
                </div>

                <div className="flex justify-end gap-2 pt-2 border-t border-border">
                    <Button type="button" onClick={onClose} variant="default">
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        variant="primary"
                        disabled={!isMatch || processing}
                        icon={Trash2}
                    >
                        {processing
                            ? 'Processing...'
                            : isForcePurge
                            ? 'Permanently Purge'
                            : 'Delete Event'}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
