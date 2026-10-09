import { useState } from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import ArtifactFontLibrary from '@/Pages/Tenant/Events/Certificates/ArtifactFonts';
import csrfFetch, { csrfFetchFormData } from '@/lib/csrfFetch';
vi.mock('@/lib/csrfFetch', () => ({ default: vi.fn(), csrfFetchFormData: vi.fn() }));
beforeEach(() => vi.clearAllMocks());
function Library() {
    const [fonts, setFonts] = useState([]);
    return <ArtifactFontLibrary eventId="event" fonts={fonts} setFonts={setFonts} />;
}
it('imports a licensed Google family and archives without losing its saved descriptor', async () => {
    csrfFetchFormData.mockResolvedValue({
        ok: true,
        json: async () => ({
            font: {
                id: 'font-id',
                family: 'artifact-id',
                name: 'Brand Font',
                weights: [400],
                faces: [],
            },
        }),
    });
    csrfFetch.mockResolvedValue({ ok: true });
    render(<Library />);
    fireEvent.click(screen.getByText('Add your own font'));
    fireEvent.change(screen.getByLabelText('Google Fonts family or link'), {
        target: { value: 'Lato' },
    });
    expect(screen.getByRole('button', { name: 'Import font' }).disabled).toBe(true);
    fireEvent.click(screen.getByLabelText('I have permission to use and embed this font'));
    fireEvent.click(screen.getByRole('button', { name: 'Import font' }));
    expect((await screen.findByRole('status')).textContent).toContain('Brand Font is ready');
    expect(csrfFetchFormData.mock.calls[0][1].get('source')).toBe('Lato');
    fireEvent.click(screen.getByRole('button', { name: 'Remove font Brand Font from library' }));
    expect(
        (await screen.findByText('Font removed from the library. Saved designs can still use it.'))
            .textContent
    ).toContain('Saved designs');
    expect(
        screen.queryByRole('button', { name: 'Remove font Brand Font from library' })
    ).toBeNull();
});
it('reports rejected imports and keeps the source for retry', async () => {
    csrfFetchFormData.mockResolvedValue({
        ok: false,
        status: 422,
        json: async () => ({ message: 'Use a static TrueType font.' }),
    });
    render(<Library />);
    fireEvent.click(screen.getByText('Add your own font'));
    fireEvent.change(screen.getByLabelText('Google Fonts family or link'), {
        target: { value: 'Unsupported' },
    });
    fireEvent.click(screen.getByLabelText('I have permission to use and embed this font'));
    fireEvent.click(screen.getByRole('button', { name: 'Import font' }));
    expect((await screen.findByRole('alert')).textContent).toContain('static TrueType');
    expect(screen.getByLabelText('Google Fonts family or link').value).toBe('Unsupported');
});
