import { it, expect, vi } from 'vitest';
import { registerAttendeeServiceWorker } from '@/lib/registerServiceWorker';
it('controls the exact canonical /my path and retires only the older attendee scope', async () => {
    window.history.replaceState({}, '', '/my');
    const unregister = vi.fn(async () => true);
    const otherUnregister = vi.fn();
    const register = vi.fn(async () => ({}));
    vi.stubGlobal('navigator', {
        serviceWorker: {
            register,
            getRegistrations: async () => [
                {
                    scope: 'https://app.test/my/',
                    active: { scriptURL: 'https://app.test/attendee-worker.js' },
                    unregister,
                },
                {
                    scope: 'https://app.test/staff/',
                    active: { scriptURL: 'https://app.test/staff-worker.js' },
                    unregister: otherUnregister,
                },
            ],
        },
    });
    vi.spyOn(document, 'readyState', 'get').mockReturnValue('complete');
    try {
        registerAttendeeServiceWorker();
        await vi.waitFor(() =>
            expect(register).toHaveBeenCalledWith('/attendee-worker.js', { scope: '/my' })
        );
        await vi.waitFor(() => expect(unregister).toHaveBeenCalledOnce());
        expect(otherUnregister).not.toHaveBeenCalled();
    } finally {
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
        window.history.replaceState({}, '', '/');
    }
});
