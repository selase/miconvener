import { beforeEach, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import BadgeTemplatePurchase from '@/Pages/Tenant/Events/Badges/BadgeTemplatePurchase';
import csrfFetch from '@/lib/csrfFetch';
vi.mock('@/lib/csrfFetch', () => ({ default: vi.fn() }));
const json = (body) => ({ ok: true, json: async () => body });
const props = {
    event: { id: 'event', name: 'Conference' },
    versionId: 'version',
    draft: {
        expected_version: 1,
        width_mm: 54,
        height_mm: 85,
        category_labels: {},
        composition: 'portrait',
    },
    canPurchase: true,
    checkoutReady: true,
    onPurchased: vi.fn(),
};
beforeEach(() => {
    vi.clearAllMocks();
    csrfFetch.mockImplementation((url) =>
        Promise.resolve(
            json(
                url.includes('payer')
                    ? { verified_email: 'payer@example.test' }
                    : url.includes('draft')
                      ? { draft: null }
                      : {
                            purchase_id: 'purchase',
                            state: 'pending',
                            terms_hash: 'hash',
                            version_id: 'version',
                            terms: {
                                event_name: 'Conference',
                                total_pesewas: 5000,
                                licence: 'One event, unlimited reprints',
                                refund_terms: 'Seven days while unused',
                            },
                        }
            )
        )
    );
});
it('requires the accepted event licence before opening payment', async () => {
    render(<BadgeTemplatePurchase {...props} />);
    fireEvent.click(await screen.findByRole('button', { name: 'Review GH₵50 purchase' }));
    expect(await screen.findByText('One event, unlimited reprints')).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Pay GH₵50' }).disabled).toBe(true);
    fireEvent.click(screen.getByRole('checkbox', { name: /accept/ }));
    expect(screen.getByRole('button', { name: 'Pay GH₵50' }).disabled).toBe(false);
    expect(props.onPurchased).not.toHaveBeenCalled();
});
it('explains purchase permission and paused checkout', () => {
    const { rerender } = render(<BadgeTemplatePurchase {...props} canPurchase={false} />);
    expect(screen.getByText(/authorised purchaser/)).toBeTruthy();
    rerender(<BadgeTemplatePurchase {...props} checkoutReady={false} />);
    expect(screen.getByText(/unavailable/)).toBeTruthy();
});
it('keeps an unknown payment recoverable without offering another payment', async () => {
    csrfFetch.mockImplementation((url) =>
        Promise.resolve(
            json(
                url.includes('payer')
                    ? { verified_email: 'payer@example.test' }
                    : url.includes('draft')
                      ? { draft: { purchase_id: 'purchase', version_id: 'version' } }
                      : {
                            purchase_id: 'purchase',
                            state: 'pending',
                            attempt_state: 'unknown',
                            terms: {},
                        }
            )
        )
    );
    render(<BadgeTemplatePurchase {...props} />);
    expect(await screen.findByText(/being checked/)).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Pay GH₵50' })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Refresh payment status' }));
    await waitFor(() =>
        expect(csrfFetch.mock.calls.filter(([url]) => url.includes('show')).length).toBeGreaterThan(
            1
        )
    );
});

it('offers a new review after a refunded licence without reusing its checkout', async () => {
    csrfFetch.mockImplementation((url) =>
        Promise.resolve(
            json(
                url.includes('payer')
                    ? { verified_email: 'payer@example.test' }
                    : url.includes('draft')
                      ? { draft: { purchase_id: 'purchase', version_id: 'version' } }
                      : {
                            purchase_id: 'purchase',
                            state: 'refunded',
                            licence_state: 'refunded',
                            terms: {},
                        }
            )
        )
    );
    render(<BadgeTemplatePurchase {...props} />);
    expect(await screen.findByRole('button', { name: 'Review a new GH₵50 licence' })).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Pay GH₵50' })).toBeNull();
});

it('keeps a suspended refund out of the new-purchase flow', async () => {
    csrfFetch.mockImplementation((url) =>
        Promise.resolve(
            json(
                url.includes('payer')
                    ? { verified_email: 'payer@example.test' }
                    : url.includes('draft')
                      ? { draft: { purchase_id: 'purchase', version_id: 'version' } }
                      : {
                            purchase_id: 'purchase',
                            state: 'paid',
                            licence_state: 'suspended',
                            attempt_state: 'confirmed',
                            payment_state: 'refund_pending',
                            expires_at: '2020-01-01',
                            terms: {},
                        }
            )
        )
    );
    render(<BadgeTemplatePurchase {...props} />);
    expect(await screen.findByText(/being checked/)).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Review a new GH₵50 licence' })).toBeNull();
});

it('rotates the payment key only after a definitive failed initialisation', async () => {
    const quote = {
        purchase_id: 'purchase',
        state: 'pending',
        version_id: 'version',
        terms_hash: 'hash',
        terms: {},
    };
    csrfFetch.mockImplementation((url) =>
        Promise.resolve(
            json(
                url.includes('payer')
                    ? { verified_email: 'payer@example.test' }
                    : url.includes('checkout')
                      ? { status: 'failed' }
                      : url.includes('draft')
                        ? { draft: null }
                        : url.includes('show')
                          ? { ...quote, attempt_state: 'failed' }
                          : quote
            )
        )
    );
    render(<BadgeTemplatePurchase {...props} />);
    fireEvent.click(await screen.findByRole('button', { name: 'Review GH₵50 purchase' }));
    fireEvent.click(await screen.findByRole('checkbox', { name: /accept/ }));
    fireEvent.click(screen.getByRole('button', { name: 'Pay GH₵50' }));
    await screen.findByText(/Payment could not start/);
    await waitFor(() =>
        expect(screen.getByRole('button', { name: 'Pay GH₵50' }).disabled).toBe(false)
    );
    fireEvent.click(screen.getByRole('button', { name: 'Pay GH₵50' }));
    await waitFor(() =>
        expect(csrfFetch.mock.calls.filter(([url]) => url.includes('checkout')).length).toBe(2)
    );
    const keys = csrfFetch.mock.calls
        .filter(([url]) => url.includes('checkout'))
        .map(([, options]) => JSON.parse(options.body).operation_key);
    expect(keys[0]).not.toBe(keys[1]);
});
