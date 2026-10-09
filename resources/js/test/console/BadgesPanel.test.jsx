import { beforeEach, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import BadgesPanel from '@/Pages/Tenant/Events/panels/BadgesPanel';
import csrfFetch, { csrfFetchFormData } from '@/lib/csrfFetch';
const permissions = vi.hoisted(() => ({ current: {} }));
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { auth: { can: permissions.current } } }),
}));
vi.mock('@/lib/csrfFetch', () => ({ default: vi.fn(), csrfFetchFormData: vi.fn() }));
const field = {
    x: 0.1,
    y: 0.2,
    width: 0.8,
    height: 0.2,
    visible: true,
    font_family: 'DejaVu Sans',
    font_size: 22,
    color: '#123456',
};
const response = (data) => ({ ok: true, json: async () => data });
const dataFor = (count) => ({
    badges: Array.from({ length: count }, (_, index) => ({
        id: `id-${index}`,
        full_name: `Person ${index}`,
        ticket_code: `CODE-${index}`,
        seat_label: `Seat ${index}`,
        qr_image: `qr-${index}`,
        badge_tier: 'vip',
        print_count: 0,
    })),
    template: {
        width_mm: 100,
        height_mm: 70,
        layout: { attendee_name: field, ticket_code: field, seat_label: field, qr: field },
        tier_styles: { vip: { text_color: '#abcdef' } },
    },
    recent_prints: [],
});
beforeEach(() => {
    permissions.current = {
        read_badge_template: true,
        update_badge_template: true,
        update_event: true,
    };
    URL.createObjectURL = vi.fn(() => 'blob:pdf');
    URL.revokeObjectURL = vi.fn();
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
});
it.each([100, 101, 250])('downloads only the selected batch for %i matches', async (count) => {
    const data = dataFor(count);
    csrfFetch.mockImplementation((url, options) =>
        options
            ? Promise.resolve({
                  ok: true,
                  headers: { get: () => 'application/pdf' },
                  blob: async () => new Blob(['pdf']),
              })
            : Promise.resolve(response(data))
    );
    render(<BadgesPanel event={{ id: 'event', name: 'Conference' }} />);
    await screen.findByText('Person 0');
    expect(screen.getByText(/matching badges/).textContent).toContain(String(count));
    if (count > 100)
        fireEvent.change(screen.getByLabelText('Badge batch'), { target: { value: '1' } });
    fireEvent.click(screen.getByRole('button', { name: /Download .* PDF/ }));
    await waitFor(() =>
        expect(csrfFetch.mock.calls.some(([, options]) => options?.method === 'POST')).toBe(true)
    );
    const payload = JSON.parse(
        csrfFetch.mock.calls.find(([, options]) => options?.method === 'POST')[1].body
    );
    expect(payload.registration_ids).toHaveLength(count === 100 ? 100 : Math.min(count - 100, 100));
    expect(payload.registration_ids[0]).toBe(count === 100 ? 'id-0' : 'id-100');
    expect(payload.export_reference).toBeTruthy();
    await waitFor(() => expect(csrfFetch).toHaveBeenCalledTimes(3));
    await waitFor(() =>
        expect(screen.getByRole('button', { name: /Download .* PDF/ }).disabled).toBe(false)
    );
});
it('uses saved design and actual attendee fields, resets changed filters, and preserves batch after history refresh', async () => {
    const data = dataFor(250);
    let loads = 0;
    csrfFetch.mockImplementation((url, options) => {
        if (options)
            return Promise.resolve({
                ok: true,
                headers: { get: () => 'application/pdf' },
                blob: async () => new Blob(['pdf']),
            });
        const refreshed = {
            ...data,
            badges: data.badges.map((badge) => ({ ...badge, print_count: loads > 0 ? 1 : 0 })),
        };
        loads += 1;
        return Promise.resolve(response(refreshed));
    });
    render(<BadgesPanel event={{ id: 'event', name: 'Conference' }} />);
    expect((await screen.findByText('Person 0')).style.color).toBe('rgb(171, 205, 239)');
    expect(screen.getByText('CODE-0')).toBeTruthy();
    expect(screen.getByText('Seat 0')).toBeTruthy();
    expect(screen.getAllByAltText('Attendee QR')[0].getAttribute('src')).toBe('qr-0');
    fireEvent.change(screen.getByLabelText('Badge batch'), { target: { value: '2' } });
    fireEvent.click(screen.getByRole('button', { name: /Download 50 badges PDF/ }));
    await waitFor(() => expect(csrfFetch).toHaveBeenCalledTimes(3));
    await screen.findByText(/Person 200 — printed 1×/);
    expect(screen.getByLabelText('Badge batch').value).toBe('2');
    fireEvent.change(screen.getByPlaceholderText('Search by name or entry code'), {
        target: { value: 'CODE-0' },
    });
    await waitFor(() =>
        expect(screen.getByRole('button', { name: /Download 1 badge PDF/ })).toBeTruthy()
    );
    fireEvent.click(screen.getByRole('button', { name: /Download 1 badge PDF/ }));
    await waitFor(() =>
        expect(csrfFetch.mock.calls.filter(([, options]) => options).length).toBe(2)
    );
    const requests = csrfFetch.mock.calls
        .filter(([, options]) => options)
        .map(([, options]) => JSON.parse(options.body));
    expect(requests[1].registration_ids).toEqual(['id-0']);
    expect(requests[1].export_reference).not.toBe(requests[0].export_reference);
    await waitFor(() => expect(loads).toBe(3));
    expect(screen.getByPlaceholderText('Search by name or entry code').value).toBe('CODE-0');
});
it('retries a lost response with the same reference and blocks concurrent downloads', async () => {
    let rejectRequest;
    csrfFetch
        .mockResolvedValueOnce(response(dataFor(1)))
        .mockImplementationOnce(
            () =>
                new Promise((resolve, reject) => {
                    rejectRequest = reject;
                })
        )
        .mockResolvedValueOnce({
            ok: true,
            headers: { get: () => 'application/pdf' },
            blob: async () => new Blob(['pdf']),
        })
        .mockResolvedValue(response(dataFor(1)));
    render(<BadgesPanel event={{ id: 'event' }} />);
    await screen.findByText('Person 0');
    fireEvent.click(screen.getByRole('button', { name: /Download 1 badge PDF/ }));
    expect(screen.getByRole('button', { name: 'Generating…' }).disabled).toBe(true);
    rejectRequest(new Error('Lost response'));
    await screen.findByText('Lost response');
    fireEvent.click(screen.getByRole('button', { name: /Retry/ }));
    await waitFor(() => expect(csrfFetch).toHaveBeenCalledTimes(4));
    const requests = csrfFetch.mock.calls
        .filter(([, options]) => options)
        .map(([, options]) => JSON.parse(options.body));
    expect(requests[0].export_reference).toBe(requests[1].export_reference);
});

it('starts a new reference for an intentional reprint after success', async () => {
    csrfFetch.mockImplementation((url, options) =>
        options
            ? Promise.resolve({
                  ok: true,
                  headers: { get: () => 'application/pdf' },
                  blob: async () => new Blob(['pdf']),
              })
            : Promise.resolve(response(dataFor(1)))
    );
    render(<BadgesPanel event={{ id: 'event' }} />);
    await screen.findByText('Person 0');
    fireEvent.click(screen.getByRole('button', { name: /Download 1 badge PDF/ }));
    await waitFor(() => expect(csrfFetch).toHaveBeenCalledTimes(3));
    fireEvent.click(screen.getByRole('button', { name: /Download 1 badge PDF/ }));
    await waitFor(() => expect(csrfFetch).toHaveBeenCalledTimes(5));
    const requests = csrfFetch.mock.calls
        .filter(([, options]) => options)
        .map(([, options]) => JSON.parse(options.body));
    expect(requests[0].registration_ids).toEqual(requests[1].registration_ids);
    expect(requests[0].export_reference).not.toBe(requests[1].export_reference);
});
it('retries a failed PDF body with its stable reference and resets batches for changed matching IDs', async () => {
    let data = dataFor(250);
    csrfFetch.mockImplementation((url, options) =>
        options
            ? Promise.resolve({
                  ok: true,
                  headers: { get: () => 'application/pdf' },
                  blob: async () => {
                      throw new Error('Body failed');
                  },
              })
            : Promise.resolve(response(data))
    );
    const { rerender } = render(<BadgesPanel event={{ id: 'event' }} />);
    await screen.findByText('Person 0');
    fireEvent.change(screen.getByLabelText('Badge batch'), { target: { value: '1' } });
    fireEvent.click(screen.getByRole('button', { name: /Download 100 badges PDF/ }));
    await screen.findByText('Body failed');
    expect(csrfFetch).toHaveBeenCalledTimes(2);
    fireEvent.click(screen.getByRole('button', { name: /Retry/ }));
    await waitFor(() => expect(csrfFetch).toHaveBeenCalledTimes(3));
    const requests = csrfFetch.mock.calls
        .filter(([, options]) => options)
        .map(([, options]) => JSON.parse(options.body));
    expect(requests[0].export_reference).toBe(requests[1].export_reference);
    data = dataFor(101);
    rerender(<BadgesPanel event={{ id: 'other-event' }} />);
    await waitFor(() => expect(screen.getByLabelText('Badge batch').value).toBe('0'));
    expect(screen.getByText(/matching badges/).textContent).toContain('101');
});

it('supports UUID references on HTTP browsers without crypto.randomUUID', async () => {
    const uuid = crypto.randomUUID;
    Object.defineProperty(crypto, 'randomUUID', { configurable: true, value: undefined });
    try {
        csrfFetch.mockImplementation((url, options) =>
            options
                ? Promise.resolve({
                      ok: true,
                      headers: { get: () => 'application/pdf' },
                      blob: async () => new Blob(['pdf']),
                  })
                : Promise.resolve(response(dataFor(1)))
        );
        render(<BadgesPanel event={{ id: 'event' }} />);
        await screen.findByText('Person 0');
        fireEvent.click(screen.getByRole('button', { name: /Download 1 badge PDF/ }));
        await waitFor(() => expect(csrfFetch).toHaveBeenCalledTimes(3));
        const payload = JSON.parse(csrfFetch.mock.calls.find(([, options]) => options)[1].body);
        expect(payload.export_reference).toMatch(
            /^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/
        );
    } finally {
        Object.defineProperty(crypto, 'randomUUID', { configurable: true, value: uuid });
    }
});

const failures = [
    ['403', () => Promise.resolve({ ok: false, status: 403, json: async () => ({}) })],
    [
        '422',
        () =>
            Promise.resolve({
                ok: false,
                status: 422,
                json: async () => ({ errors: { width_mm: ['Width does not fit on the sheet.'] } }),
            }),
    ],
    ['500', () => Promise.resolve({ ok: false, status: 500, json: async () => ({}) })],
    [
        'non-JSON',
        () =>
            Promise.resolve({
                ok: false,
                status: 500,
                json: async () => {
                    throw new Error('HTML');
                },
            }),
    ],
    ['network', () => Promise.reject(new Error('Connection lost'))],
];
it.each(failures)('reports %s load failures and clears them after retry', async (_, failure) => {
    csrfFetch.mockReset();
    csrfFetch.mockImplementationOnce(failure).mockResolvedValue(response(dataFor(1)));
    render(<BadgesPanel event={{ id: 'event' }} />);
    await screen.findByRole('alert');
    fireEvent.click(screen.getByRole('button', { name: 'Retry loading badges' }));
    await screen.findByText('Person 0');
    expect(screen.queryByRole('alert')).toBeNull();
});
it.each(failures)(
    'keeps badge design draft and shows %s save failures in the dialog',
    async (_, failure) => {
        csrfFetch.mockReset();
        csrfFetch.mockResolvedValue(response(dataFor(1)));
        csrfFetchFormData.mockImplementationOnce(failure);
        render(<BadgesPanel event={{ id: 'event' }} />);
        await screen.findByText('Person 0');
        fireEvent.click(screen.getByRole('button', { name: 'Design badges' }));
        fireEvent.change(screen.getByLabelText('Width (mm)'), { target: { value: '90' } });
        fireEvent.submit(screen.getByLabelText('Width (mm)').closest('form'));
        const { within } = await import('@testing-library/react');
        await within(screen.getByRole('dialog')).findByRole('alert');
        expect(screen.getByLabelText('Width (mm)').value).toBe('90');
    }
);
it.each(failures)('reports %s PDF failure without recording client success', async (_, failure) => {
    csrfFetch.mockReset();
    csrfFetch.mockResolvedValueOnce(response(dataFor(1))).mockImplementationOnce(failure);
    render(<BadgesPanel event={{ id: 'event' }} />);
    await screen.findByText('Person 0');
    fireEvent.click(screen.getByRole('button', { name: /Download 1 badge PDF/ }));
    await screen.findByRole('alert');
    expect(csrfFetch).toHaveBeenCalledTimes(2);
});
it('gates design and download using explicit capabilities', async () => {
    permissions.current = {};
    csrfFetch.mockReset();
    csrfFetch.mockResolvedValue(response(dataFor(1)));
    render(<BadgesPanel event={{ id: 'event' }} />);
    await screen.findByText('Person 0');
    expect(screen.queryByRole('button', { name: 'Design badges' })).toBeNull();
    expect(screen.queryByRole('button', { name: /Download .*PDF/ })).toBeNull();
});
it('refreshes saved logo metadata and keeps imported fonts when the designer reopens', async () => {
    const initial = dataFor(1);
    initial.template.layout.tenant_logo = { ...field, visible: true };
    const updated = { ...initial, badge_logo: 'data:image/png;base64,new-logo', custom_logo: 'data:image/png;base64,new-logo' };
    let saved = false;
    csrfFetch.mockImplementation(() => Promise.resolve(response(saved ? updated : initial)));
    csrfFetchFormData.mockImplementation(() => {
        saved = true;
        return Promise.resolve(response({ template: updated.template }));
    });
    render(<BadgesPanel event={{ id: 'event', name: 'Conference' }} />);
    await screen.findByText('Person 0');
    fireEvent.click(screen.getByRole('button', { name: 'Design badges' }));
    fireEvent.click(screen.getByRole('button', { name: 'Save design' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(screen.getByAltText('Badge logo').getAttribute('src')).toBe(updated.badge_logo);
    fireEvent.click(screen.getByRole('button', { name: 'Design badges' }));
    fireEvent.click(screen.getByText('Add your own font'));
    csrfFetchFormData.mockResolvedValue(response({ font: { id: 'custom-id', family: 'artifact-id', name: 'Brand Font', weights: [400], faces: [] } }));
    fireEvent.change(screen.getByLabelText('Google Fonts family or link'), { target: { value: 'Lato' } });
    fireEvent.click(screen.getByLabelText('I have permission to use and embed this font'));
    fireEvent.click(screen.getByRole('button', { name: 'Import font' }));
    await screen.findByText('Brand Font is ready to use.');
    fireEvent.click(screen.getByRole('button', { name: 'Cancel', exact: true }));
    fireEvent.click(screen.getByRole('button', { name: 'Design badges' }));
    expect(screen.getByRole('option', { name: 'Brand Font' })).toBeTruthy();
});
