import { afterEach, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import ImportMembers from '@/Pages/Tenant/Community/ImportMembers';
vi.mock('@/Layouts/ConsoleLayout', () => ({ default: ({ children }) => children }));
afterEach(() => vi.restoreAllMocks());
it('requires a per-row decision and submits the explicitly selected existing member', async () => {
    const fetch = vi
        .spyOn(globalThis, 'fetch')
        .mockResolvedValueOnce({
            ok: true,
            json: async () => ({
                token: 'preview',
                summary: { new: 0, existing: 0, duplicate: 0, error: 0, review_required: 1 },
                rows: [
                    {
                        line: 2,
                        name: 'Alex',
                        phone: '+233241234567',
                        email: 'new@example.test',
                        membership_type: 'Regular',
                        status: 'review_required',
                        errors: [],
                        candidates: [
                            {
                                id: 'member-a',
                                name: 'Alex',
                                email: 'old@example.test',
                                phone: '+233241234567',
                                reasons: ['phone', 'person'],
                            },
                        ],
                    },
                ],
            }),
        })
        .mockResolvedValueOnce({
            ok: true,
            json: async () => ({ created: 0, updated: 1, skipped: 0, errors: 0, rows: [] }),
        });
    render(<ImportMembers />);
    fireEvent.change(screen.getByLabelText('Spreadsheet'), {
        target: { files: [new File(['a'], 'members.csv')] },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Preview spreadsheet' }));
    await screen.findByLabelText('Decision for line 2');
    expect(screen.getByRole('button', { name: 'Confirm import' }).disabled).toBe(true);
    fireEvent.change(screen.getByLabelText('Decision for line 2'), {
        target: { value: 'update:member-a' },
    });
    expect(screen.getByRole('button', { name: 'Confirm import' }).disabled).toBe(false);
    expect(screen.getByText(/1 update/)).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Confirm import' }));
    await screen.findByText(/0 added/);
    expect(JSON.parse(fetch.mock.calls[1][1].body).decisions).toEqual({
        2: { action: 'update', member_id: 'member-a' },
    });
});

it('reviews possible matches before a manual distinct-person save', async () => {
    const { default: MemberFormModal } = await import('@/Pages/Tenant/Community/MemberFormModal');
    const review = {
        candidates: [
            {
                id: 'existing',
                name: 'Alex Existing',
                phone: '+233241234567',
                email: 'old@example.test',
            },
        ],
        revision: 'review-revision',
        can_create_distinct: true,
    };
    const fetch = vi
        .spyOn(globalThis, 'fetch')
        .mockResolvedValueOnce({ ok: true, status: 200, json: async () => review })
        .mockResolvedValueOnce({ ok: true, status: 200, json: async () => review })
        .mockResolvedValueOnce({ ok: true, status: 201, json: async () => ({ id: 'new' }) });
    const saved = vi.fn();
    render(
        <MemberFormModal
            member={null}
            membershipTypes={[{ id: 'type', name: 'Regular' }]}
            onClose={() => {}}
            onSaved={saved}
        />
    );
    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Alex' } });
    fireEvent.change(screen.getByLabelText('Phone'), { target: { value: '0241234567' } });
    fireEvent.click(screen.getByRole('button', { name: 'Add member' }));
    await screen.findByText('Alex Existing');
    expect(fetch).toHaveBeenCalledTimes(1);
    fireEvent.click(screen.getByLabelText(/I checked these records/));
    fireEvent.click(screen.getByRole('button', { name: 'Add member' }));
    await vi.waitFor(() => expect(saved).toHaveBeenCalledOnce());
    expect(JSON.parse(fetch.mock.calls[2][1].body)).toMatchObject({
        confirm_distinct: true,
        duplicate_revision: 'review-revision',
    });
});
