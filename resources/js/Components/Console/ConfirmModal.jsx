import Modal from './Modal';
import Button from './Button';

export default function ConfirmModal({
    open,
    onClose,
    onConfirm,
    title,
    description,
    confirmLabel = 'Confirm',
    cancelLabel = 'Cancel',
    danger = false,
    processing = false,
    hideCancel = false,
}) {
    return (
        <Modal open={open} onClose={onClose} title={title}>
            {description && <p className="text-sm text-ink-secondary">{description}</p>}

            <div className="mt-6 flex justify-end gap-3">
                {!hideCancel && <Button onClick={onClose}>{cancelLabel}</Button>}
                <Button
                    onClick={onConfirm ?? onClose}
                    disabled={processing}
                    className={danger ? 'border-danger-fg/40 text-danger-fg hover:bg-danger-bg' : ''}
                >
                    {confirmLabel}
                </Button>
            </div>
        </Modal>
    );
}
