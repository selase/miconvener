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
    await act(async () => camera.callback('https://example.test/checkin/event-ticket'));
    expect(accept).not.toHaveBeenCalled();
    expect(screen.getByRole('alert').textContent).toMatch(/store handover QR/);
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

it('does not offer restart until the previous camera has finished stopping', async () => {
    let finishStop;
    camera.stop.mockImplementationOnce(
        () =>
            new Promise((resolve) => {
                finishStop = resolve;
            })
    );
    render(<StoreCollectionScanner onCredential={vi.fn()} />);
    fireEvent.click(screen.getByRole('button', { name: 'Start camera' }));
    await waitFor(() => expect(camera.start).toHaveBeenCalledOnce());
    fireEvent.click(screen.getByRole('button', { name: 'Stop camera' }));
    expect(screen.getByRole('button', { name: 'Start camera' }).disabled).toBe(true);
    await waitFor(() => expect(finishStop).toBeTypeOf('function'));
    await act(async () => finishStop());
    await waitFor(() =>
        expect(screen.getByRole('button', { name: 'Start camera' }).disabled).toBe(false)
    );
    fireEvent.click(screen.getByRole('button', { name: 'Start camera' }));
    await waitFor(() => expect(camera.start).toHaveBeenCalledTimes(2));
});

it('keeps restart disabled while a cancelled camera permission request is unresolved', async () => {
    let finishStart;
    camera.start.mockImplementationOnce(
        () =>
            new Promise((resolve) => {
                finishStart = resolve;
            })
    );
    render(<StoreCollectionScanner onCredential={vi.fn()} />);
    fireEvent.click(screen.getByRole('button', { name: 'Start camera' }));
    await waitFor(() => expect(camera.start).toHaveBeenCalledOnce());
    fireEvent.click(screen.getByRole('button', { name: 'Stop camera' }));
    expect(screen.getByRole('button', { name: 'Start camera' }).disabled).toBe(true);
    await act(async () => finishStart());
    await waitFor(() =>
        expect(screen.getByRole('button', { name: 'Start camera' }).disabled).toBe(false)
    );
    expect(camera.stop).toHaveBeenCalledOnce();
});

it.each(['sync', 'async'])(
    'stops the camera and reports a %s verification failure separately from QR parsing',
    async (mode) => {
        const accept =
            mode === 'sync'
                ? () => {
                      throw new Error('Consumer failure');
                  }
                : async () => {
                      throw new Error('Consumer failure');
                  };
        render(<StoreCollectionScanner onCredential={accept} />);
        fireEvent.click(screen.getByRole('button', { name: 'Start camera' }));
        await waitFor(() => expect(camera.start).toHaveBeenCalledOnce());
        await act(async () =>
            camera.callback(
                JSON.stringify({
                    purpose: 'miconvener.store.collection.v1',
                    pass_id: 'pass',
                    secret: 'secret',
                })
            )
        );
        await waitFor(() => expect(camera.stop).toHaveBeenCalled());
        expect(screen.getByRole('alert').textContent).toMatch(/verification could not start/i);
    }
);
