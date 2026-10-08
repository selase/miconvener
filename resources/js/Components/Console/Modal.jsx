import { useEffect, useId, useRef } from 'react';
import { X } from 'lucide-react';

export default function Modal({ open, onClose, title, children, className = 'max-w-md' }) {
    const dialog = useRef(null);
    const closeHandler = useRef(onClose);
    closeHandler.current = onClose;
    const titleId = useId();
    useEffect(() => {
        if (!open) {
            return undefined;
        }

        const previousFocus = document.activeElement;
        const focusable = () =>
            Array.from(
                dialog.current?.querySelectorAll(
                    'button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), a[href], [tabindex="0"]'
                ) || []
            );
        focusable()[0]?.focus();
        const onKeyDown = (event) => {
            if (event.key === 'Escape') {
                closeHandler.current();
            }
            if (event.key === 'Tab') {
                const items = focusable();
                const first = items[0];
                const last = items.at(-1);
                if (
                    event.shiftKey &&
                    (document.activeElement === first ||
                        !dialog.current?.contains(document.activeElement))
                ) {
                    event.preventDefault();
                    last?.focus();
                } else if (
                    !event.shiftKey &&
                    (document.activeElement === last ||
                        !dialog.current?.contains(document.activeElement))
                ) {
                    event.preventDefault();
                    first?.focus();
                }
            }
        };

        document.addEventListener('keydown', onKeyDown);

        return () => {
            document.removeEventListener('keydown', onKeyDown);
            if (previousFocus?.isConnected) previousFocus.focus();
        };
    }, [open]);

    if (!open) {
        return null;
    }

    return (
        <div className="fixed inset-0 z-40 flex items-center justify-center p-4">
            <div className="fixed inset-0 bg-black/30" onClick={onClose} />

            <div
                ref={dialog}
                role="dialog"
                aria-modal="true"
                aria-labelledby={title ? titleId : undefined}
                aria-label={title ? undefined : 'Dialog'}
                className={`relative max-h-[85vh] w-full overflow-y-auto rounded-lg border border-border bg-surface p-4 sm:p-6 shadow-float ${className}`}
            >
                <button
                    type="button"
                    onClick={onClose}
                    aria-label="Close"
                    className="absolute right-4 top-4 grid h-9 w-9 place-items-center rounded-md text-ink-secondary hover:bg-surface-hover hover:text-ink"
                >
                    <X className="h-4 w-4" strokeWidth={1.9} />
                </button>

                {title && (
                    <h2 id={titleId} className="mb-5 pr-8 text-lg font-bold text-ink">
                        {title}
                    </h2>
                )}

                {children}
            </div>
        </div>
    );
}
