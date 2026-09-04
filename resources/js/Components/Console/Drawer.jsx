import { useEffect } from 'react';
import { X } from 'lucide-react';

export default function Drawer({ open, onClose, children }) {
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
        <>
            {/* Backdrop — overlay/sheet mode only, below the xl breakpoint */}
            <div className="fixed inset-0 z-20 bg-black/30 xl:hidden" onClick={onClose} />

            <aside
                className={`
                    fixed inset-y-0 right-0 z-30 w-full max-w-120 overflow-y-auto border-l border-border
                    bg-surface p-6 shadow-float transition-transform duration-200 ease-out
                    xl:relative xl:z-10 xl:w-120 xl:max-w-none xl:shrink-0 xl:shadow-none xl:transition-none
                `}
            >
                <button
                    type="button"
                    onClick={onClose}
                    aria-label="Close panel"
                    className="absolute right-4 top-4 grid h-9 w-9 place-items-center rounded-md text-ink-secondary hover:bg-surface-hover hover:text-ink"
                >
                    <X className="h-4 w-4" strokeWidth={1.9} />
                </button>

                {children}
            </aside>
        </>
    );
}
