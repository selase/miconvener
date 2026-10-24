import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import StoreCollectionScanner from '@/Components/Marketplace/StoreCollectionScanner';
const camera = vi.hoisted(() => ({
    start: vi.fn(),
    stop: vi.fn(),
    clear: vi.fn(),
    callback: null,
}));
vi.mock('html5-qrcode', () => ({
    Html5Qrcode: class {
        isScanning = true;
        start(...args) {
            camera.callback = args[2];
            return camera.start(...args);
        }
        stop() {
            return camera.stop();
        }
        clear() {
            return camera.clear();
        }
    },
}));
vi.setConfig({ testTimeout: 20000 });
beforeEach(() => {
    camera.start.mockReset();
    camera.stop.mockReset();
    camera.clear.mockReset();
    Object.defineProperty(navigator, 'mediaDevices', {
        configurable: true,
        value: { getUserMedia: vi.fn() },
    });
});
it('starts only on request, rejects event codes and stops after a store credential', async () => {
    Object.defineProperty(navigator, 'mediaDevices', {
        configurable: true,
        value: { getUserMedia: vi.fn() },
    });
    const accept = vi.fn();
    render(<StoreCollectionScanner onCredential={accept} />);
    expect(camera.start).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Start camera' }));
    await waitFor(() => expect(camera.start).toHaveBeenCalledOnce());
    act(() => camera.callback('https://example.test/checkin/event-ticket'));
    expect(accept).not.toHaveBeenCalled();
    expect(screen.getByText(/store handover QR/)).toBeTruthy();
    const credential = {
        purpose: 'miconvener.store.collection.v1',
        pass_id: 'pass',
        secret: 'secret',
    };
    await act(async () => camera.callback(JSON.stringify(credential)));
    expect(accept).toHaveBeenCalledWith(credential);
    expect(camera.stop).toHaveBeenCalled();
});
it('stops a camera whose permission prompt resolves after unmount', async () => {
    let resolve;
    camera.start.mockImplementationOnce(
        () =>
            new Promise((done) => {
                resolve = done;
            })
    );
    const accept = vi.fn();
    const view = render(<StoreCollectionScanner onCredential={accept} />);
    fireEvent.click(screen.getByRole('button', { name: 'Start camera' }));
    await waitFor(() => expect(resolve).toBeTypeOf('function'));
    view.unmount();
    await act(async () => resolve());
    camera.callback(
        JSON.stringify({
            purpose: 'miconvener.store.delivery.v1',
            pass_id: 'pass',
            secret: 'secret',
        })
    );
    expect(accept).not.toHaveBeenCalled();
    expect(camera.stop).toHaveBeenCalled();
});
