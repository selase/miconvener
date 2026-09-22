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

describe('editing a registration: the request never reaches the server', () => {
    // csrfFetch's own vi.fn() is created inside the vi.mock() factory, which
    // Vitest evaluates before this file's imports run. Vitest's mock-call
    // tracking treats a *rejection* produced by a mock born that early as a
    // reportable error even once the component's try/catch has fully handled
    // it, which would fail this test despite the component behaving
    // correctly. Delegating the rejection through a mock created here, inside
    // describe(), sidesteps that false positive without changing what's
    // exercised: csrfFetch(...) still rejects and the component still has to
    // recover from it.
    const failingRequest = vi.fn();

    beforeEach(() => {
        csrfFetch.mockReset();
        failingRequest.mockReset();
        csrfFetch.mockImplementation((...args) => failingRequest(...args));
    });

    it('recovers when the request never reaches the server', async () => {
        failingRequest.mockRejectedValue(new Error('offline'));
        const onSaved = vi.fn();
        render(
            <EditRegistrationModal event={event} registration={registration} onClose={() => {}} onSaved={onSaved} />
        );

        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect(
            await screen.findByText("Couldn't reach the server. Check your connection and try again.")
        ).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Save' }).disabled).toBe(false);
        expect(onSaved).not.toHaveBeenCalled();
    });
});
