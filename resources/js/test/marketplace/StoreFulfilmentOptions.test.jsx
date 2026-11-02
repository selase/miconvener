import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import csrfFetch from '@/lib/csrfFetch';
vi.mock('@/lib/csrfFetch', () => ({ default: vi.fn() }));
import StoreFulfilmentChoice from '@/Components/Marketplace/StoreFulfilmentChoice';

const options = {
    policy_id: 'policy-1',
    methods: ['collection', 'delivery'],
    collection_location: 'Association office',
    collection_hours: '9–5',
    delivery_countries: ['GH'],
    delivery_timing: 'Within three working days',
};
it('shows office pickup and delivery included in price without preselecting for the buyer', () => {
    const changed = vi.fn();
    render(<StoreFulfilmentChoice options={options} value={null} onChange={changed} />);
    expect(screen.getByLabelText(/Collect from/).checked).toBe(false);
    expect(screen.getByLabelText(/Delivery.*included/).checked).toBe(false);
    fireEvent.click(screen.getByLabelText(/Collect from/));
    expect(changed).toHaveBeenCalledWith({ policy_id: 'policy-1', method: 'collection' });
    expect(screen.getByText('Association office')).toBeTruthy();
});
it('collects delivery details only for delivery and explains the order has no ready deadline', () => {
    const changed = vi.fn();
    render(
        <StoreFulfilmentChoice
            options={options}
            value={{ policy_id: 'policy-1', method: 'delivery', destination: {} }}
            onChange={changed}
        />
    );
    fireEvent.change(screen.getByLabelText('Recipient name'), { target: { value: 'Test Buyer' } });
    expect(changed).toHaveBeenCalledWith(
        expect.objectContaining({
            destination: expect.objectContaining({ recipient: 'Test Buyer' }),
        })
    );
    expect(screen.getByText(/remains available until collected or delivered/)).toBeTruthy();
    expect(screen.getByLabelText('Address')).toBeTruthy();
});

it('allows a buyer to change the accepted option while the order is still quoted', async () => {
    const { StoreFulfilmentSelector } =
        await import('@/Components/Marketplace/StoreFulfilmentChoice');
    render(
        <StoreFulfilmentSelector
            order={{
                id: 'order-1',
                state: 'quoted',
                fulfilment_method: 'collection',
                fulfilment_options: options,
            }}
        />
    );
    fireEvent.click(screen.getByRole('button', { name: 'Change collection or delivery' }));
    expect(screen.getByLabelText(/Delivery.*included/)).toBeTruthy();
});

it('ignores a delayed buyer acceptance response after the screen unmounts', async () => {
    const { StoreFulfilmentSelector } =
        await import('@/Components/Marketplace/StoreFulfilmentChoice');
    let resolve;
    csrfFetch.mockReturnValueOnce(
        new Promise((done) => {
            resolve = done;
        })
    );
    const accepted = vi.fn();
    const view = render(
        <StoreFulfilmentSelector
            order={{ id: 'order-1', state: 'quoted', fulfilment_options: options }}
            onAccepted={accepted}
        />
    );
    fireEvent.click(screen.getByLabelText(/Collect from/));
    fireEvent.click(screen.getByRole('button', { name: 'Accept fulfilment option' }));
    await waitFor(() => expect(csrfFetch).toHaveBeenCalled());
    view.unmount();
    await act(async () =>
        resolve({ ok: true, json: async () => ({ order_id: 'order-1', method: 'collection' }) })
    );
    expect(accepted).not.toHaveBeenCalled();
});

it('keeps a typed country separator until submission and normalizes the offered countries then', async () => {
    const { default: PolicyForm } =
        await import('@/Components/Marketplace/StoreFulfilmentPolicyForm');
    csrfFetch.mockReset();
    csrfFetch.mockResolvedValueOnce({ ok: true, json: async () => ({}) });
    render(<PolicyForm initial={{ methods: ['delivery'], delivery_countries: ['GH'] }} />);
    const input = screen.getByLabelText('Delivery country codes (comma separated)');
    fireEvent.change(input, { target: { value: 'GH,' } });
    expect(input.value).toBe('GH,');
    fireEvent.change(input, { target: { value: 'GH, ng' } });
    fireEvent.submit(screen.getByText('Save fulfilment options').closest('form'));
    await waitFor(() => expect(csrfFetch).toHaveBeenCalledOnce());
    expect(JSON.parse(csrfFetch.mock.calls[0][1].body).policy.delivery_countries).toEqual([
        'GH',
        'NG',
    ]);
});
