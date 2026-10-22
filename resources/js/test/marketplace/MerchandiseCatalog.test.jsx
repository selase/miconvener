import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import { router } from '@inertiajs/react';
import Item from '@/Pages/Public/Marketplace/Items/Show';
import MerchandiseCard from '@/Components/Marketplace/MerchandiseCard';
import CatalogStock from '@/Components/Marketplace/CatalogStock';
import { sendJson } from '@/lib/dues';
import csrfFetch, { csrfFetchFormData } from '@/lib/csrfFetch';
import CatalogPhotos from '@/Components/Marketplace/CatalogPhotos';
import CatalogForm from '@/Pages/Tenant/Store/Catalog/Form';
import Storefront from '@/Pages/Public/Marketplace/Storefront/Show';

vi.mock('@/Layouts/ConsoleLayout', () => ({ default: ({ children }) => children }));
vi.mock('@/lib/csrfFetch', async (original) => ({
    ...(await original()),
    default: vi.fn(),
    csrfFetchFormData: vi.fn(),
}));
vi.mock('@/Layouts/MarketplaceLayout', () => ({ default: ({ children }) => children }));
vi.mock('@/lib/dues', async (original) => ({ ...(await original()), sendJson: vi.fn() }));

const item = {
    id: 'shirt',
    slug: 'shirt',
    title: 'Association shirt',
    description: 'A cotton shirt.',
    currency: 'GHS',
    seller: { name: 'Teacher Association', slug: 'teachers' },
    visibility: 'public',
    photos: [],
    collection_instructions: 'Collect from the office.',
    purchasing_enabled: false,
    variants: [
        {
            id: 'm',
            options: { Size: 'M' },
            sku: 'SHIRT-M',
            price_pesewas: 1450,
            available: true,
            on_hand: 3,
            is_active: true,
        },
        {
            id: 'l',
            options: { Size: 'L' },
            sku: 'SHIRT-L',
            price_pesewas: 1650,
            available: false,
            on_hand: 0,
            is_active: true,
        },
    ],
    movements: [],
};

it('shows sale options, unavailable stock and an honest purchasing-disabled state', () => {
    render(<Item item={item} storefrontUrl="/marketplace/teachers" />);
    expect(screen.getByText('GHS 14.50')).toBeTruthy();
    fireEvent.click(screen.getByRole('radio', { name: /Size: L/ }));
    expect(screen.getByText('GHS 16.50')).toBeTruthy();
    expect(screen.getByText('Currently unavailable')).toBeTruthy();
    expect(screen.getByText('Purchasing is not enabled yet')).toBeTruthy();
    expect(screen.queryByRole('button', { name: /checkout|buy now/i })).toBeNull();
    expect(screen.queryByLabelText(/date|guest count|capacity/i)).toBeNull();
});

it('renders a simple default variant without an empty option selector', () => {
    render(
        <Item
            item={{ ...item, variants: [{ ...item.variants[0], options: {} }] }}
            storefrontUrl="/marketplace/teachers"
        />
    );
    expect(screen.queryByRole('radio')).toBeNull();
    expect(screen.getByText('In stock')).toBeTruthy();
});

it('links merchandise to the item route and uses a missing-photo placeholder', () => {
    render(<MerchandiseCard item={item} />);
    expect(screen.getByRole('link', { name: /Association shirt/ }).getAttribute('href')).toContain(
        'marketplace.items.show'
    );
    expect(screen.getByLabelText('No product photo')).toBeTruthy();
});

it('keeps stock errors visible and does not invent a successful correction', async () => {
    sendJson.mockResolvedValueOnce({
        ok: false,
        status: 422,
        errors: { delta: 'Stock cannot become negative.' },
        message: 'Check the correction.',
    });
    const changed = vi.fn();
    render(<CatalogStock item={item} onChange={changed} />);
    fireEvent.change(screen.getByLabelText('Quantity change'), { target: { value: '-5' } });
    fireEvent.change(screen.getByLabelText('Reason for correction'), {
        target: { value: 'Damaged' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Record correction' }));
    expect((await screen.findByRole('alert')).textContent).toContain(
        'Stock cannot become negative.'
    );
    await waitFor(() => expect(changed).not.toHaveBeenCalled());
});

it('clears a private item before rechecking access when the tab regains focus', async () => {
    csrfFetch.mockResolvedValueOnce({ ok: false });
    render(
        <Item item={{ ...item, visibility: 'members' }} storefrontUrl="/marketplace/teachers" />
    );
    fireEvent(window, new Event('focus'));
    await waitFor(() => expect(screen.queryByRole('heading', { name: item.title })).toBeNull());
    expect(await screen.findByText(/no longer available/)).toBeTruthy();
});

it('removes member cards when verified-email proof expires without removing public cards', async () => {
    vi.useFakeTimers();
    try {
        const visibleItems = [
            item,
            { ...item, id: 'private', title: 'Private notebook', visibility: 'members' },
        ];
        csrfFetch.mockResolvedValueOnce({
            ok: true,
            json: async () => ({
                merchandise: visibleItems,
                catalogEnabled: true,
                verifiedUntil: Math.floor(Date.now() / 1000) + 2,
            }),
        });
        render(
            <Storefront
                shop={{ name: 'Teacher Association', slug: 'teachers' }}
                catalogEnabled
                merchandise={[
                    item,
                    { ...item, id: 'private', title: 'Private notebook', visibility: 'members' },
                ]}
                verifiedUntil={Math.floor(Date.now() / 1000) + 2}
            />
        );
        await act(async () => {
            await Promise.resolve();
        });
        expect(screen.getByText('Private notebook')).toBeTruthy();
        act(() => vi.advanceTimersByTime(2100));
        expect(screen.queryByText('Private notebook')).toBeNull();
        expect(screen.getByText('Association shirt')).toBeTruthy();
    } finally {
        vi.useRealTimers();
    }
});

it('shows field errors and focuses the invalid input after a failed item save', async () => {
    sendJson.mockResolvedValueOnce({
        ok: false,
        status: 422,
        errors: { title: 'Choose a clearer item name.' },
        message: 'Validation failed.',
    });
    render(<CatalogForm />);
    const name = screen.getByLabelText('Item name');
    fireEvent.change(name, { target: { value: 'Shirt' } });
    fireEvent.change(screen.getByLabelText('Item address'), { target: { value: 'shirt' } });
    fireEvent.change(screen.getByLabelText('SKU'), { target: { value: 'SHIRT' } });
    fireEvent.change(screen.getByLabelText('Price (GHS)'), { target: { value: '14.50' } });
    fireEvent.submit(screen.getByRole('button', { name: 'Save draft' }).closest('form'));
    expect((await screen.findByRole('alert')).textContent).toContain('Choose a clearer item name.');
    await waitFor(() => expect(document.activeElement).toBe(name));
    expect(sendJson.mock.calls[0][2].variants[0].price_pesewas).toBe(1450);
});

it('reuses the correction token after a lost response and reports success only after the server accepts it', async () => {
    sendJson
        .mockResolvedValueOnce({ ok: false, status: 0, errors: {}, message: 'Connection lost.' })
        .mockResolvedValueOnce({ ok: true, body: { listing: item }, errors: {} });
    const changed = vi.fn();
    render(<CatalogStock item={item} onChange={changed} />);
    fireEvent.change(screen.getByLabelText('Quantity change'), { target: { value: '1' } });
    fireEvent.change(screen.getByLabelText('Reason for correction'), {
        target: { value: 'Receipt' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Record correction' }));
    await screen.findByRole('alert');
    fireEvent.click(screen.getByRole('button', { name: 'Record correction' }));
    await screen.findByText('Stock correction recorded.');
    expect(sendJson.mock.calls[0][2].idempotency_key).toBe(
        sendJson.mock.calls[1][2].idempotency_key
    );
    expect(changed).toHaveBeenCalledTimes(1);
});

it('requires confirmation before removing a product photo', async () => {
    sendJson.mockResolvedValueOnce({ ok: true, errors: {}, body: { listing: item } });
    const changed = vi.fn();
    render(
        <CatalogPhotos
            item={{ ...item, photos: [{ id: 'photo', url: '/protected/photo' }] }}
            onChange={changed}
        />
    );
    fireEvent.click(screen.getByRole('button', { name: 'Remove product photo 1' }));
    expect(screen.getByRole('dialog')).toBeTruthy();
    expect(sendJson).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Remove photo' }));
    await waitFor(() => expect(changed).toHaveBeenCalledTimes(1));
    expect(sendJson.mock.calls[0][0]).toBe('DELETE');
});

it('uploads a selected image through the protected seller endpoint', async () => {
    csrfFetchFormData.mockResolvedValueOnce({ ok: true, json: async () => ({ listing: item }) });
    const changed = vi.fn();
    render(<CatalogPhotos item={item} onChange={changed} />);
    const file = new File(['image'], 'shirt.jpg', { type: 'image/jpeg' });
    fireEvent.change(screen.getByLabelText('Choose a product photo'), {
        target: { files: [file] },
    });
    fireEvent.submit(screen.getByRole('button', { name: 'Upload photo' }).closest('form'));
    await waitFor(() => expect(changed).toHaveBeenCalledTimes(1));
    expect(csrfFetchFormData.mock.calls[0][1].get('photo')).toBe(file);
});

it('moves a newly saved draft to its canonical edit address', async () => {
    sendJson.mockResolvedValueOnce({ ok: true, body: { listing: item }, errors: {} });
    render(<CatalogForm />);
    fireEvent.change(screen.getByLabelText('Item name'), { target: { value: 'Shirt' } });
    fireEvent.change(screen.getByLabelText('Item address'), { target: { value: 'shirt' } });
    fireEvent.change(screen.getByLabelText('SKU'), { target: { value: 'SHIRT' } });
    fireEvent.change(screen.getByLabelText('Price (GHS)'), { target: { value: '14.50' } });
    fireEvent.submit(screen.getByRole('button', { name: 'Save draft' }).closest('form'));
    await waitFor(() =>
        expect(router.visit).toHaveBeenCalledWith(
            expect.stringContaining('tenant.store.catalog.show'),
            { replace: true, preserveScroll: true }
        )
    );
});

it('selects a remaining photo when the gallery changes during access refresh', async () => {
    const photos = [
        { id: 'one', url: '/photo/one' },
        { id: 'two', url: '/photo/two' },
    ];
    csrfFetch.mockResolvedValueOnce({
        ok: true,
        json: async () => ({ item: { ...item, photos: [photos[0]] }, verifiedUntil: null }),
    });
    render(<Item item={{ ...item, photos }} storefrontUrl="/marketplace/teachers" />);
    fireEvent.click(screen.getByRole('button', { name: 'Photo 2' }));
    expect(screen.getByAltText(item.title).getAttribute('src')).toBe('/photo/two');
    fireEvent(window, new Event('focus'));
    expect((await screen.findByAltText(item.title)).getAttribute('src')).toBe('/photo/one');
});

it('does not render private history props before checking current access', () => {
    csrfFetch.mockResolvedValueOnce({ ok: false });
    render(
        <Item item={{ ...item, visibility: 'members' }} storefrontUrl="/marketplace/teachers" />
    );
    expect(screen.queryByRole('heading', { name: item.title })).toBeNull();
});

it('revalidates expired history using renewed proof instead of aborting the fresh request', async () => {
    vi.useFakeTimers();
    try {
        let resolveRequest;
        csrfFetch.mockReturnValueOnce(
            new Promise((resolve) => {
                resolveRequest = resolve;
            })
        );
        const privateItem = { ...item, visibility: 'members' };
        render(
            <Item
                item={privateItem}
                storefrontUrl="/marketplace/teachers"
                verifiedUntil={Math.floor(Date.now() / 1000) - 1}
            />
        );
        act(() => vi.advanceTimersByTime(0));
        await act(async () => {
            resolveRequest({
                ok: true,
                json: async () => ({
                    item: privateItem,
                    verifiedUntil: Math.floor(Date.now() / 1000) + 3600,
                }),
            });
        });
        expect(screen.getByRole('heading', { name: item.title })).toBeTruthy();
    } finally {
        vi.useRealTimers();
    }
});
