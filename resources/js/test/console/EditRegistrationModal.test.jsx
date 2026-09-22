import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import EditRegistrationModal from '@/Pages/Tenant/Events/EditRegistrationModal';
import csrfFetch from '@/lib/csrfFetch';

vi.mock('@/lib/csrfFetch', () => ({ default: vi.fn() }));

const registration = { id: 'reg-1', full_name: 'Ama Serwa', email: 'ama@stme.org', phone: null };
const event = { id: 'evt-1' };

describe('editing a registration', () => {
    beforeEach(() => csrfFetch.mockReset());

    it('sends the corrected details to the update route', async () => {
        csrfFetch.mockResolvedValue({ ok: true, json: async () => ({}) });
        const onSaved = vi.fn();
        render(
            <EditRegistrationModal event={event} registration={registration} onClose={() => {}} onSaved={onSaved} />
        );

        fireEvent.change(screen.getByDisplayValue('ama@stme.org'), { target: { value: 'ama@stem.org' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => expect(onSaved).toHaveBeenCalled());
        const [url, options] = csrfFetch.mock.calls[0];
        expect(url).toContain('tenant.events.registrations.update');
        expect(options.method).toBe('PATCH');
        expect(JSON.parse(options.body)).toEqual({
            full_name: 'Ama Serwa',
            email: 'ama@stem.org',
            phone: null,
        });
    });

    it('shows the server\'s reason when the address is refused, and stays open', async () => {
        csrfFetch.mockResolvedValue({
            ok: false,
            json: async () => ({ errors: { email: ["That doesn't look like an email address."] } }),
        });
        const onSaved = vi.fn();
        render(
            <EditRegistrationModal event={event} registration={registration} onClose={() => {}} onSaved={onSaved} />
        );

        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect(await screen.findByText("That doesn't look like an email address.")).toBeTruthy();
        expect(onSaved).not.toHaveBeenCalled();
    });
});
