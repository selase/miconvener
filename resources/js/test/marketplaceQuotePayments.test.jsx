import { expect, test, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { router } from '@inertiajs/react';
import Quote from '@/Pages/Public/Marketplace/Quotes/Show';
import MarketplaceIndex from '@/Pages/Public/Marketplace/Index';

vi.mock('@/Layouts/MarketplaceLayout', () => ({ default: ({ children }) => <>{children}</> }));

const quote = {
    quote_reference: 'RFQ-TEST',
    status: 'quoted',
    is_expired: false,
    venue_payments_paused: true,
    items: [],
    formatted_due_now: 'GHS 100.00',
    shop: { name: 'Test Venue', slug: 'test-venue', email: 'host@example.test' },
};

test('paused venue proposals remain readable with host contact but no payment action', () => {
    render(<Quote quote={quote} />);
    expect(screen.queryByRole('button', { name: /Accept and pay/ })).toBeNull();
    expect(screen.getByText(/Online payments are paused for this venue/)).toBeTruthy();
    expect(screen.getByRole('link', { name: /host@example.test/ }).getAttribute('href')).toBe(
        'mailto:host@example.test'
    );
});

test('available proposals retain their payment action', () => {
    render(<Quote quote={{ ...quote, venue_payments_paused: false }} />);
    fireEvent.click(screen.getByRole('button', { name: /Accept and pay/ }));
    expect(router.post).toHaveBeenCalledWith(
        expect.stringContaining('marketplace.quotes.checkout'),
        {},
        expect.any(Object)
    );
});

test('marketplace copy invites browsing and enquiries without promising bookings', () => {
    render(<MarketplaceIndex />);
    expect(
        screen.getByRole('heading', { name: 'Discover event spaces across Ghana.' })
    ).toBeTruthy();
    expect(screen.getByText(/Browse listings and contact hosts/)).toBeTruthy();
    expect(screen.queryByRole('heading', { name: /Why book/ })).toBeNull();
});
