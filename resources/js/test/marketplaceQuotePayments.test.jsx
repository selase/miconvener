import { expect, test, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { router } from '@inertiajs/react';
import Quote from '@/Pages/Public/Marketplace/Quotes/Show';
import MarketplaceIndex from '@/Pages/Public/Marketplace/Index';
import VenueShow from '@/Pages/Public/Marketplace/Venues/Show';

vi.mock('@/Layouts/MarketplaceLayout', () => ({ default: ({ children }) => <>{children}</> }));
vi.mock('@/Components/Marketplace/VenueBookingDrawer', () => ({ default: () => null }));
vi.mock('@/Components/Marketplace/VendorRfqModal', () => ({ default: () => null }));

test.each(['equipment', 'service'])(
    'the %s enquiry page does not claim its payments are paused',
    (kind) => {
        render(
            <VenueShow
                venue={{
                    title: 'Test Listing',
                    slug: 'test-listing',
                    listing_kind: kind,
                    is_bookable: false,
                    shop: quote.shop,
                    capacity_breakdown: {},
                    media: [],
                    amenities: [],
                }}
            />
        );
        expect(screen.getByRole('button', { name: 'Request Itemized Quote (RFQ)' })).toBeTruthy();
        expect(screen.queryByText(/Online reservations and payments are paused/)).toBeNull();
    }
);

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
