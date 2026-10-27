import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import StoreCollections from '@/Pages/Tenant/Store/Collections/Index';
import StoreHandoverPass from '@/Components/Marketplace/StoreHandoverPass';
import csrfFetch from '@/lib/csrfFetch';
vi.mock('@/Layouts/ConsoleLayout', () => ({ default: ({ children }) => children }));
vi.mock('@/lib/csrfFetch', () => ({ default: vi.fn() }));
vi.mock('@/Components/Marketplace/StoreCollectionScanner', () => ({
    default: () => <p>Camera available on request</p>,
}));
// Role queries over the complete console/dialog tree are slow on the shared local runner.
vi.setConfig({ testTimeout: 20000 });
beforeEach(() => {
    csrfFetch.mockReset();
});
afterEach(() => {
    vi.restoreAllMocks();
});
const summary = {
    id: 'order-1',
    number: 'TEST01',
    buyer: 'bu•••@example.test',
    method: 'collection',
    fulfilment_state: 'ready',
    label: 'Ready for collection',
    item_count: 1,
};
const detail = {
    ...summary,
    lines: [{ id: 'line-1', title: 'Association shirt', quantity: 1, options: { Size: 'M' } }],
    can_confirm: true,
    can_ready: false,
    can_prepare: true,
    can_dispatch: false,
    can_return: false,
    events: [],
    collection: null,
    instructions: 'Collect at the office',
};
it('keeps code matching separate from explicit whole-order confirmation', async () => {
    csrfFetch
        .mockResolvedValueOnce({ ok: true, json: async () => ({ order: detail, enabled: true }) })
        .mockResolvedValueOnce({ ok: true, json: async () => ({ order: detail }) });
    render(
        <StoreCollections orders={{ data: [summary], last_page: 1, current_page: 1 }} enabled />
    );
    fireEvent.click(screen.getByRole('button', { name: /Order TEST01/ }));
    await screen.findByText('Association shirt');
    fireEvent.change(screen.getByLabelText('Collection or delivery code'), {
        target: { value: '7K2M-4Q9R-8H3V' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Check code' }));
    await screen.findByRole('button', { name: 'Confirm full handover' });
    expect(csrfFetch.mock.calls.some(([url]) => url.endsWith('/confirm'))).toBe(false);
    fireEvent.click(screen.getByRole('button', { name: 'Confirm full handover' }));
    expect(screen.getByRole('button', { name: 'Record collection' }).disabled).toBe(true);
    fireEvent.click(screen.getByLabelText('All items are being handed over'));
    expect(screen.getByRole('button', { name: 'Record collection' }).disabled).toBe(false);
    csrfFetch.mockResolvedValueOnce({
        ok: true,
        json: async () => ({
            order: {
                ...detail,
                fulfilment_state: 'collected',
                label: 'Collected',
                can_confirm: false,
                can_prepare: false,
                collection: { handed_over_at: new Date().toISOString() },
            },
        }),
    });
    fireEvent.click(screen.getByRole('button', { name: 'Record collection' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(screen.getByRole('button', { name: /Order TEST01.*Collected/ })).toBeTruthy();
    expect(document.activeElement).toBe(screen.getByRole('heading', { name: 'Order TEST01' }));
});
it('shows empty and paused states without inventing an order or taking payment', () => {
    render(
        <StoreCollections orders={{ data: [], last_page: 1, current_page: 1 }} enabled={false} />
    );
    expect(screen.getByText(/Fulfilment is paused/)).toBeTruthy();
    expect(screen.getByText(/No orders to prepare/)).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Confirm full handover' })).toBeNull();
});
it('creates a buyer pass only on explicit request and clears it when the screen is hidden', async () => {
    csrfFetch.mockResolvedValueOnce({
        ok: true,
        json: async () => ({
            pass_id: 'pass-1',
            manual_code: '7K2M-4Q9R-8H3V',
            qr_svg: 'data:image/svg+xml;base64,PHN2Zy8+',
            expires_at: new Date(Date.now() + 900000).toISOString(),
        }),
    });
    render(
        <StoreHandoverPass
            order={{
                id: 'order-1',
                fulfilment_method: 'collection',
                can_issue_pass: true,
                fulfilment_state: 'ready',
            }}
            verifiedUntil={Date.now() / 1000 + 3600}
        />
    );
    expect(csrfFetch).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Show collection pass' }));
    await screen.findByText('7K2M-4Q9R-8H3V');
    expect(screen.getByText(/Anyone you share this pass with/)).toBeTruthy();
    vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden');
    act(() => document.dispatchEvent(new Event('visibilitychange')));
    expect(screen.queryByText('7K2M-4Q9R-8H3V')).toBeNull();
    expect(screen.queryByAltText('Collection pass QR code')).toBeNull();
});

it('discards a pass response when email verification expires while it is loading', async () => {
    let resolve;
    csrfFetch.mockImplementationOnce(
        () =>
            new Promise((done) => {
                resolve = done;
            })
    );
    const verifiedUntil = Math.floor(Date.now() / 1000) + 1;
    const view = render(
        <StoreHandoverPass
            order={{ id: 'order-1', can_issue_pass: true, fulfilment_method: 'collection' }}
            verifiedUntil={verifiedUntil}
        />
    );
    fireEvent.click(screen.getByRole('button', { name: 'Show collection pass' }));
    view.rerender(
        <StoreHandoverPass
            order={{ id: 'order-1', can_issue_pass: true, fulfilment_method: 'collection' }}
            verifiedUntil={Math.floor(Date.now() / 1000) - 1}
        />
    );
    await act(async () =>
        resolve({
            ok: true,
            json: async () => ({
                manual_code: 'PRIVATE',
                qr_svg: 'data:image/svg+xml;base64,PHN2Zy8+',
                expires_at: new Date(Date.now() + 900000).toISOString(),
            }),
        })
    );
    expect(screen.queryByText('PRIVATE')).toBeNull();
});

it('loads the protected selected detail when opened through a direct order URL', async () => {
    csrfFetch.mockResolvedValueOnce({
        ok: true,
        json: async () => ({ order: detail, enabled: true }),
    });
    render(
        <StoreCollections
            orderId="order-1"
            orders={{ data: [summary], last_page: 1, current_page: 1 }}
            enabled
        />
    );
    await screen.findByText('Association shirt');
    expect(csrfFetch.mock.calls[0][0]).toBe('/store/collections/order-1');
});

it('does not let window focus abort an in-flight code validation', async () => {
    let validationSignal;
    let finishValidation;
    csrfFetch.mockImplementation(async (url, options) => {
        if (url.endsWith('/validate')) {
            validationSignal = options.signal;
            return new Promise((resolve, reject) => {
                finishValidation = () =>
                    resolve({ ok: true, json: async () => ({ order: detail }) });
                options.signal.addEventListener('abort', () =>
                    reject(new DOMException('Aborted', 'AbortError'))
                );
            });
        }
        return {
            ok: true,
            json: async () => ({ order: detail, orders: { data: [summary] }, enabled: true }),
        };
    });
    render(<StoreCollections orderId="order-1" orders={{ data: [summary] }} enabled />);
    await screen.findByText('Association shirt');
    fireEvent.change(screen.getByLabelText('Collection or delivery code'), {
        target: { value: '7K2M-4Q9R-8H3V' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Check code' }));
    await waitFor(() => expect(finishValidation).toBeTypeOf('function'));
    act(() => window.dispatchEvent(new Event('focus')));
    expect(validationSignal.aborted).toBe(false);
    await act(async () => finishValidation());
    await screen.findByText(/Code matched/);
    expect(document.activeElement).toBe(screen.getByText(/Code matched/));
});
