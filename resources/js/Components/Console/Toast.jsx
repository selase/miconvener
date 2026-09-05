import { createContext, useCallback, useContext, useRef, useState } from 'react';
import { X } from 'lucide-react';

const ToastContext = createContext(null);

export function ToastProvider({ children }) {
    const [message, setMessage] = useState(null);
    const timerRef = useRef(null);

    const showToast = useCallback((text) => {
        setMessage(text);
        clearTimeout(timerRef.current);
        timerRef.current = setTimeout(() => setMessage(null), 4000);
    }, []);

    return (
        <ToastContext.Provider value={showToast}>
            {children}

            <div
                className={`fixed bottom-6 right-6 z-50 flex items-center gap-4 bg-inverse px-4.5 py-3.5 text-sm font-medium text-inverse-ink shadow-float transition-all duration-200 ease-out ${
                    message ? 'translate-y-0 opacity-100' : 'pointer-events-none translate-y-3 opacity-0'
                }`}
            >
                <span>{message}</span>
                <button type="button" onClick={() => setMessage(null)} aria-label="Dismiss">
                    <X className="h-4 w-4" strokeWidth={2} />
                </button>
            </div>
        </ToastContext.Provider>
    );
}

export function useToast() {
    return useContext(ToastContext);
}
