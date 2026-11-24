import { useEffect, useId, useRef } from 'react';
import { X } from 'lucide-react';

export default function Modal({
    open,
    onClose,
    title,
    children,
    className = 'max-w-md',
    fullScreenOnMobile = false,
    footer = null,
}) {
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
            ).filter(
                (element) =>
                    !(
                        window.matchMedia?.('(max-width: 1023px)').matches &&
                        element.closest('[data-mobile-hidden="true"]')
                    )
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

    useEffect(() => {
        if (!open) {
            return undefined;
        }
        lockBodyScroll();
        return unlockBodyScroll;
    }, [open]);

    if (!open) {
        return null;
    }

    const closeButton = (
        <button
            type="button"
            onClick={onClose}
            aria-label="Close"
            className={`grid h-9 w-9 shrink-0 place-items-center rounded-md text-ink-secondary hover:bg-surface-hover hover:text-ink ${
                title ? '-mr-2' : 'absolute right-3 top-3 z-10'
            }`}
        >
            <X className="h-4 w-4" strokeWidth={1.9} />
        </button>
    );

    return (
        <div
            className={`fixed inset-0 z-40 flex items-center justify-center ${fullScreenOnMobile ? 'p-0 sm:p-4' : 'p-4'}`}
        >
            <div
                className="fixed inset-0 bg-scrim backdrop-blur-[2px] motion-safe:animate-[scrim-in_150ms_ease-out]"
                onClick={onClose}
            />

            <div
                ref={dialog}
                role="dialog"
                aria-modal="true"
                aria-labelledby={title ? titleId : undefined}
                aria-label={title ? undefined : 'Dialog'}
                className={`relative flex ${fullScreenOnMobile ? 'h-dvh max-h-dvh rounded-none sm:h-auto sm:max-h-[90vh] sm:rounded-lg' : 'max-h-[85vh] rounded-lg'} w-full flex-col border border-border-strong bg-surface-raised shadow-float motion-safe:animate-[dialog-in_180ms_ease-out] ${className}`}
            >
                {title ? (
                    <div className="flex shrink-0 items-start justify-between gap-4 border-b border-border px-4 py-4 sm:px-6">
                        <h2 id={titleId} className="pt-1.5 text-lg font-bold text-ink">
                            {title}
                        </h2>
                        {closeButton}
                    </div>
                ) : (
                    closeButton
                )}

                <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 py-5 sm:px-6">
                    {children}
                </div>

                {footer && (
                    <div className="shrink-0 border-t border-border px-4 py-4 sm:px-6">
                        {footer}
                    </div>
                )}
            </div>
        </div>
    );
}

/*
 * Stops the page behind an open dialog from scrolling. Counted, so a dialog
 * opened from another dialog does not unlock the page when it closes.
 */
let openDialogs = 0;
let previousOverflow = '';

function lockBodyScroll() {
    if (openDialogs === 0) {
        previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
    }
    openDialogs += 1;
}

function unlockBodyScroll() {
    openDialogs = Math.max(0, openDialogs - 1);
    if (openDialogs === 0) {
        document.body.style.overflow = previousOverflow;
    }
}
