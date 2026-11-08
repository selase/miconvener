import { expect, it, vi } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import SpeakersPanel from '@/Pages/Tenant/Events/SpeakersPanel';
import csrfFetch, { csrfFetchFormData } from '@/lib/csrfFetch';
vi.mock('@inertiajs/react', () => ({ usePage: () => ({ props: { canManageEventMoney: false } }) }));
vi.mock('@/lib/csrfFetch', () => ({
    default: vi.fn(async () => ({
        json: async () => [{ id: 'other', name: 'Unrelated speaker' }],
    })),
    csrfFetchFormData: vi.fn(async () => ({
        ok: true,
        json: async () => ({ id: 'new', name: 'Ama' }),
    })),
}));
it('uses event speaker profiles without offering the global directory', async () => {
    render(<SpeakersPanel event={{ id: 'event' }} speakers={[]} onChange={vi.fn()} />);
    await waitFor(() =>
        expect(csrfFetch).toHaveBeenCalledWith('/tenant.events.speaker-profiles.index?event=event')
    );
    expect(screen.queryByText('Add from Speaker Directory')).toBeNull();
    fireEvent.change(screen.getByLabelText('Full Name'), { target: { value: 'Ama' } });
    fireEvent.change(screen.getByLabelText('Email Address'), {
        target: { value: 'ama@example.test' },
    });
    fireEvent.submit(screen.getByLabelText('Full Name').closest('form'));
    await waitFor(() =>
        expect(csrfFetchFormData).toHaveBeenCalledWith(
            '/tenant.events.speaker-profiles.store?event=event',
            expect.any(FormData)
        )
    );
    expect(csrfFetch).toHaveBeenCalledTimes(1);
});
