import { beforeEach, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import BadgeDesignLibrary from '@/Pages/Tenant/Events/Badges/BadgeDesignLibrary';
import csrfFetch from '@/lib/csrfFetch';
vi.mock('@/lib/csrfFetch', () => ({ default: vi.fn() }));
const json = (body) => ({ ok: true, json: async () => body });
const design = {
    id: 'design',
    name: 'Conference',
    scope: 'organisation',
    price_pesewas: 0,
    versions: [{ id: 'version', version: 1, width_mm: 54, height_mm: 85, orientation: 'portrait' }],
};
const onApplied = vi.fn();
beforeEach(() => {
    vi.clearAllMocks();
    csrfFetch.mockImplementation((url, options) => {
        if (options) return Promise.resolve(json({ template: { design_version: 2 } }));
        if (url.includes('preview'))
            return Promise.resolve(
                json({
                    template: {
                        width_mm: 54,
                        height_mm: 85,
                        layout: {},
                        categories: [{ ticket_name: 'Nurse', label: 'Nurse' }],
                    },
                })
            );
        return Promise.resolve(
            json({
                designs: [design],
                revisions: [{ id: 'revision', design_version: 1 }],
                can_share: true,
            })
        );
    });
});
it('previews a saved design and requires explicit replacement with destination categories', async () => {
    render(
        <BadgeDesignLibrary
            event={{ id: 'event', name: 'Conference' }}
            template={{ design_version: 1 }}
            ticketTypes={[{ id: 'target', name: 'Nurse' }]}
            badges={[]}
            onApplied={onApplied}
        />
    );
    fireEvent.click(await screen.findByRole('button', { name: 'Preview Conference' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Replace event design' }));
    await waitFor(() => expect(onApplied).toHaveBeenCalled());
    const payload = JSON.parse(
        csrfFetch.mock.calls.find(([, options]) => options?.method === 'POST')[1].body
    );
    expect(payload).toMatchObject({
        version_id: 'version',
        expected_version: 1,
        category_labels: { target: 'Nurse' },
    });
});
it('saves an event-private design and offers organisation sharing explicitly', async () => {
    render(
        <BadgeDesignLibrary
            event={{ id: 'event' }}
            template={{ design_version: 1 }}
            ticketTypes={[]}
            badges={[]}
            onApplied={onApplied}
        />
    );
    fireEvent.change(await screen.findByLabelText('Design name'), {
        target: { value: 'My design' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Save current design' }));
    await waitFor(() =>
        expect(
            csrfFetch.mock.calls.some(([, options]) => options?.body?.includes('My design'))
        ).toBe(true)
    );
    const payload = JSON.parse(
        csrfFetch.mock.calls.find(([, options]) => options?.method === 'POST')[1].body
    );
    expect(payload.scope).toBe('event');
});
it('shows load failure with retry and never offers paid clean application', async () => {
    csrfFetch.mockResolvedValueOnce({
        ok: false,
        status: 500,
        json: async () => ({ message: 'Unavailable' }),
    });
    render(
        <BadgeDesignLibrary
            event={{ id: 'event' }}
            template={{ design_version: 1 }}
            ticketTypes={[]}
            badges={[]}
            onApplied={onApplied}
        />
    );
    fireEvent.click(await screen.findByRole('button', { name: 'Retry loading designs' }));
    expect(await screen.findByRole('button', { name: 'Preview Conference' })).toBeTruthy();
});
it('shows a watermarked premium preview without an apply action', async () => {
    csrfFetch.mockImplementation((url) =>
        Promise.resolve(
            json(
                url.includes('preview')
                    ? { preview_image: 'data:image/png;base64,c2FtcGxl' }
                    : { designs: [{ ...design, price_pesewas: 5000 }], revisions: [] }
            )
        )
    );
    render(
        <BadgeDesignLibrary
            event={{ id: 'event' }}
            template={{ design_version: 1 }}
            ticketTypes={[]}
            badges={[]}
            onApplied={onApplied}
        />
    );
    fireEvent.click(await screen.findByRole('button', { name: 'Preview Conference' }));
    expect(await screen.findByAltText('Watermarked paid design preview')).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Replace event design' })).toBeNull();
});
it('restores a revision with the current optimistic version', async () => {
    render(
        <BadgeDesignLibrary
            event={{ id: 'event' }}
            template={{ design_version: 4 }}
            ticketTypes={[]}
            badges={[]}
            onApplied={onApplied}
        />
    );
    fireEvent.change(await screen.findByRole('combobox', { name: 'Previous event design' }), {
        target: { value: 'revision' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Restore selected revision' }));
    await waitFor(() => expect(onApplied).toHaveBeenCalled());
    const payload = JSON.parse(
        csrfFetch.mock.calls.find(([, options]) => options?.method === 'POST')[1].body
    );
    expect(payload).toEqual({ revision_id: 'revision', expected_version: 4 });
});
it('keeps this event’s reviewed category labels when a generic design has no mappings', async () => {
    csrfFetch.mockImplementation((url, options) =>
        Promise.resolve(
            json(
                options
                    ? { template: {} }
                    : url.includes('preview')
                      ? { template: { width_mm: 54, height_mm: 85, layout: {}, categories: [] } }
                      : { designs: [design], revisions: [] }
            )
        )
    );
    render(
        <BadgeDesignLibrary
            event={{ id: 'event' }}
            template={{
                design_version: 1,
                sheet_settings: { category_labels: { target: 'Doctor' } },
            }}
            ticketTypes={[{ id: 'target', name: 'In-person doctor ticket' }]}
            badges={[]}
            onApplied={onApplied}
        />
    );
    fireEvent.click(await screen.findByRole('button', { name: 'Preview Conference' }));
    expect((await screen.findByRole('textbox', { name: 'In-person doctor ticket' })).value).toBe(
        'Doctor'
    );
});
