import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import StoreCollections from '@/Pages/Tenant/Store/Collections/Index';
import csrfFetch from '@/lib/csrfFetch';

vi.mock('@/Layouts/ConsoleLayout', () => ({ default: ({ children }) => children }));
vi.mock('@/lib/csrfFetch', () => ({ default: vi.fn() }));
const camera = vi.hoisted(() => ({ start: vi.fn(), stop: vi.fn(), clear: vi.fn(), decoded: null }));
vi.mock('html5-qrcode', () => ({
    Html5Qrcode: class {
        isScanning = true;
        start(...args) {
            camera.decoded = args[2];
            return camera.start();
        }
        stop() {
            return camera.stop();
        }
        clear() {
            return camera.clear();
        }
    },
}));
beforeEach(() => {
    csrfFetch.mockReset();
    camera.start.mockReset();
    camera.stop.mockReset();
    camera.clear.mockReset();
    Object.defineProperty(navigator, 'mediaDevices', {
        configurable: true,
        value: { getUserMedia: vi.fn() },
    });
});

it('hands a real scanner result to the real page once, including early decoding and focus transitions', async () => {
    const order = {
        id: 'order-1',
        number: 'CAMERA',
        buyer: 'test@example.test',
        label: 'Ready for collection',
        method: 'collection',
        fulfilment_state: 'ready',
        item_count: 1,
        can_confirm: true,
        lines: [{ id: 'line-1', title: 'Test item', quantity: 1, options: {} }],
        events: [],
    };
    let finishStart;
    let finishValidation;
    let validationSignal;
    camera.start.mockImplementationOnce(
        () =>
            new Promise((resolve) => {
                finishStart = resolve;
            })
    );
    csrfFetch.mockImplementation(async (url, options) => {
        if (url.endsWith('/validate')) {
            validationSignal = options.signal;
            return new Promise((resolve, reject) => {
                finishValidation = () => resolve({ ok: true, json: async () => ({ order }) });
                options.signal.addEventListener('abort', () =>
                    reject(new DOMException('Aborted', 'AbortError'))
                );
            });
        }
        return {
            ok: true,
            json: async () => ({ order, enabled: true, orders: { data: [order] } }),
        };
    });
    render(<StoreCollections orders={{ data: [order] }} orderId="order-1" enabled />);
    await screen.findByText('Test item');
    fireEvent.click(screen.getByRole('button', { name: 'Start camera' }));
    await waitFor(() => expect(finishStart).toBeTypeOf('function'));
    const requestsBeforeFocus = csrfFetch.mock.calls.length;
    act(() => window.dispatchEvent(new Event('focus')));
    expect(csrfFetch.mock.calls).toHaveLength(requestsBeforeFocus);
    const proof = {
        purpose: 'miconvener.store.collection.v1',
        pass_id: '00000000-0000-4000-8000-000000000001',
        secret: 'a'.repeat(64),
    };
    let decoded;
    act(() => {
        decoded = camera.decoded(JSON.stringify(proof));
    });
    expect(csrfFetch.mock.calls.filter(([url]) => url.endsWith('/validate'))).toHaveLength(0);
    await act(async () => finishStart());
    await waitFor(() => expect(finishValidation).toBeTypeOf('function'));
    act(() => window.dispatchEvent(new Event('focus')));
    expect(validationSignal.aborted).toBe(false);
    await act(async () => {
        finishValidation();
        await decoded;
    });
    const matched = await screen.findByText(/Code matched/);
    expect(document.activeElement).toBe(matched);
    expect(camera.stop).toHaveBeenCalledOnce();
    const validations = csrfFetch.mock.calls.filter(([url]) => url.endsWith('/validate'));
    expect(validations).toHaveLength(1);
    expect(JSON.parse(validations[0][1].body).credential).toEqual(proof);
    expect(csrfFetch.mock.calls.some(([url]) => url.endsWith('/confirm'))).toBe(false);
});
