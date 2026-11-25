import { describe, it, expect, vi, beforeEach } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import ApplicationReviewModal from '@/Pages/Tenant/Events/ApplicationReviewModal';
import csrfFetch from '@/lib/csrfFetch';

vi.mock('@/lib/csrfFetch', () => ({ default: vi.fn() }));

const application = {
    applicant: {
        id: 'r1',
        full_name: 'Esi Owusu',
        email: 'esi@example.com',
        phone: '0241234567',
        status: 'pending_approval',
        ticket_type_name: 'Mentorship + Thesis support',
        dietary_requirements: null,
        accessibility_needs: null,
        approval_note: null,
        submitted_at: '2026-11-24T10:00:00Z',
    },
    sections: [
        {
            title: 'About you',
            help: null,
            answers: [
                { label: 'Current year / level', value: 'Level 400 / Final year' },
            ],
        },
    ],
};

const json = (body, ok = true) => Promise.resolve({ ok, json: () => Promise.resolve(body) });

describe('reviewing an application', () => {
    beforeEach(() => csrfFetch.mockReset());

    it('shows the answers under their section with choice labels', async () => {
        csrfFetch.mockReturnValueOnce(json(application));
        render(
            <ApplicationReviewModal
                event={{ id: 'e1' }}
                registration={{ id: 'r1', full_name: 'Esi Owusu', status: 'pending_approval' }}
                onClose={() => {}}
            />
        );

        expect(await screen.findByText('About you')).toBeTruthy();
        expect(screen.getByText('Level 400 / Final year')).toBeTruthy();
        expect(screen.getByText('Mentorship + Thesis support')).toBeTruthy();
    });

    it('declines with a reason typed in the dialog and reports back', async () => {
        csrfFetch.mockReturnValueOnce(json(application)).mockReturnValueOnce(json({}));
        const onDecided = vi.fn();
        const onClose = vi.fn();
        render(
            <ApplicationReviewModal
                event={{ id: 'e1' }}
                registration={{ id: 'r1', full_name: 'Esi Owusu', status: 'pending_approval' }}
                onClose={onClose}
                onDecided={onDecided}
            />
        );
        await screen.findByText('About you');

        fireEvent.click(screen.getByRole('button', { name: 'Decline' }));
        fireEvent.change(screen.getByLabelText(/Reason for declining/), {
            target: { value: 'Cohort is full' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Decline application' }));

        await waitFor(() => expect(onDecided).toHaveBeenCalled());
        const [url, options] = csrfFetch.mock.calls[1];
        expect(url).toContain('tenant.events.registrations.reject');
        expect(JSON.parse(options.body)).toEqual({ note: 'Cohort is full' });
        expect(onClose).toHaveBeenCalled();
    });

    it('keeps the dialog open and explains when approval is refused', async () => {
        csrfFetch
            .mockReturnValueOnce(json(application))
            .mockReturnValueOnce(json({ message: 'No place is available.' }, false));
        const onClose = vi.fn();
        render(
            <ApplicationReviewModal
                event={{ id: 'e1' }}
                registration={{ id: 'r1', full_name: 'Esi Owusu', status: 'pending_approval' }}
                onClose={onClose}
            />
        );
        await screen.findByText('About you');

        fireEvent.click(screen.getByRole('button', { name: 'Approve' }));

        expect(await screen.findByText('No place is available.')).toBeTruthy();
        expect(onClose).not.toHaveBeenCalled();
    });

    it('offers no decision for someone already decided', async () => {
        csrfFetch.mockReturnValueOnce(
            json({ ...application, applicant: { ...application.applicant, status: 'confirmed' } })
        );
        render(
            <ApplicationReviewModal
                event={{ id: 'e1' }}
                registration={{ id: 'r1', full_name: 'Esi Owusu', status: 'confirmed' }}
                onClose={() => {}}
            />
        );
        await screen.findByText('About you');

        expect(screen.queryByRole('button', { name: 'Approve' })).toBeNull();
    });
});
