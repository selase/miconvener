import { useEffect } from 'react';
import { X } from 'lucide-react';

export default function Modal({ open, onClose, title, children, className = 'max-w-md' }) {
    useEffect(() => {
        if (!open) {
            return undefined;
        }

        const onKeyDown = (event) => {
            if (event.key === 'Escape') {
                onClose();
            }
        };

        document.addEventListener('keydown', onKeyDown);

        return () => document.removeEventListener('keydown', onKeyDown);
    }, [open, onClose]);

    if (!open) {
        return null;
    }

    return (
        <div className="fixed inset-0 z-40 flex items-center justify-center p-4">
            <div className="fixed inset-0 bg-black/30" onClick={onClose} />

            <div className={`relative max-h-[85vh] w-full overflow-y-auto rounded-lg border border-border bg-surface p-6 shadow-float ${className}`}>
                <button
                    type="button"
                    onClick={onClose}
                    aria-label="Close"
                    className="absolute right-4 top-4 grid h-9 w-9 place-items-center rounded-md text-ink-secondary hover:bg-surface-hover hover:text-ink"
                >
                    <X className="h-4 w-4" strokeWidth={1.9} />
                </button>

                {title && <h2 className="mb-5 pr-8 text-lg font-bold text-ink">{title}</h2>}

                {children}
            </div>
        </div>
    );
}
