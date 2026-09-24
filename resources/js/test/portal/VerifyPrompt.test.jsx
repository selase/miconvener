import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import VerifyPrompt from '@/Pages/Public/Events/AttendeePortal/VerifyPrompt';
import csrfFetch from '@/lib/csrfFetch';

vi.mock('@/lib/csrfFetch', () => ({ default: vi.fn() }));

const answer = (ok, json) => Promise.resolve({ ok, json: async () => json });

describe('verify prompt', () => {
    beforeEach(() => csrfFetch.mockReset());
    afterEach(() => vi.useRealTimers());

    it('asks for an address, sends a code, and reports the proven address', async () => {
        csrfFetch
            .mockReturnValueOnce(answer(true, { message: 'On its way.' }))
            .mockReturnValueOnce(answer(true, { email: 'am•@stem.org' }));
        const onVerified = vi.fn();
        render(<VerifyPrompt onVerified={onVerified} />);

        fireEvent.change(screen.getByLabelText('Your email address'), {
            target: { value: 'ama@stem.org' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Email me a code' }));
        fireEvent.change(await screen.findByLabelText('Verification code'), { target: { value: '482913' } });
        fireEvent.click(screen.getByRole('button', { name: 'Confirm' }));

        await waitFor(() => expect(onVerified).toHaveBeenCalledWith('am•@stem.org'));
        expect(JSON.parse(csrfFetch.mock.calls[1][1].body)).toEqual({
            email: 'ama@stem.org',
            code: '482913',
        });
    });

    it('from a portal page, asks by registration and never sends a typed address', async () => {
        csrfFetch.mockReturnValueOnce(answer(true, { message: 'On its way.' }));
        render(<VerifyPrompt registrationId="reg-1" sentTo="am•@stem.org" onVerified={() => {}} />);

        expect(screen.queryByLabelText('Your email address')).toBeNull();
        expect(screen.getByText(/am•@stem\.org/)).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Email me a code' }));

        await waitFor(() => expect(csrfFetch).toHaveBeenCalled());
        expect(JSON.parse(csrfFetch.mock.calls[0][1].body)).toEqual({ registration: 'reg-1' });
    });
});
