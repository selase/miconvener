import React from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import QuestionnairesPanel from '@/Pages/Public/Community/QuestionnairesPanel';
import { communityRequest } from '@/lib/community';
vi.mock('@/lib/community', () => ({ communityRequest: vi.fn() }));
const form = {
    id: 'form',
    title: 'Volunteer survey',
    revision: 1,
    questions: [
        {
            id: 'q',
            title: 'How can you help?',
            type: 'open',
            required: true,
            settings: {},
            options: [],
        },
    ],
};
const detail = {
    questionnaire: form,
    accepting: true,
    contexts: [{ id: 'member', name: 'Local Member', role: 'self', responded: false }],
    results: null,
};
afterEach(() => {
    cleanup();
    vi.clearAllMocks();
    window.history.replaceState({}, '', '/my');
});
describe('community questionnaires', () => {
    it('requires explicit member selection and retains typed answers after validation errors', async () => {
        communityRequest.mockResolvedValue(detail);
        render(<QuestionnairesPanel tenantSlug="local" questionnaireId="form" />);
        await screen.findByText('Volunteer survey');
        expect(screen.getByRole('button', { name: 'Send response' }).disabled).toBe(true);
        fireEvent.change(screen.getByLabelText('Answering as'), { target: { value: 'member' } });
        fireEvent.change(screen.getByLabelText(/How can you help/), {
            target: { value: 'Welcome team' },
        });
        communityRequest.mockRejectedValueOnce(
            Object.assign(new Error('Check your answer'), { status: 422 })
        );
        fireEvent.click(screen.getByRole('button', { name: 'Send response' }));
        await screen.findByText('Check your answer');
        expect(screen.getByLabelText(/How can you help/).value).toBe('Welcome team');
    });
    it('labels representative reading and prevents answering', async () => {
        communityRequest.mockResolvedValue({
            ...detail,
            contexts: [
                { id: 'member', name: 'Local Member', role: 'representative', responded: false },
            ],
        });
        render(<QuestionnairesPanel tenantSlug="local" questionnaireId="form" />);
        await screen.findByText(/Representatives can read/);
        expect(screen.getByRole('button', { name: 'Send response' }).disabled).toBe(true);
        expect(screen.getByRole('option', { name: /representative/i }).disabled).toBe(true);
    });
    it('clears private content when proof expires', async () => {
        const expired = vi.fn();
        communityRequest.mockResolvedValue(detail);
        render(
            <QuestionnairesPanel tenantSlug="local" questionnaireId="form" onExpired={expired} />
        );
        await screen.findByText('Volunteer survey');
        communityRequest.mockRejectedValue(
            Object.assign(new Error('Verify again'), { status: 401 })
        );
        fireEvent(window, new Event('focus'));
        await waitFor(() => expect(expired).toHaveBeenCalled());
        expect(screen.queryByText('Volunteer survey')).toBeNull();
    });
    it('shows a received state without revealing other members or unpublished results', async () => {
        communityRequest.mockResolvedValue({
            ...detail,
            contexts: [{ ...detail.contexts[0], responded: true }],
        });
        render(<QuestionnairesPanel tenantSlug="local" questionnaireId="form" />);
        await screen.findByText('Volunteer survey');
        fireEvent.change(screen.getByLabelText('Answering as'), { target: { value: 'member' } });
        await screen.findByText('Your response has been received.');
        expect(screen.getByRole('button', { name: 'Send response' }).disabled).toBe(true);
        expect(screen.queryByText('Published results')).toBeNull();
    });
});

it('shows all ten supported native answer controls', async () => {
    const { default: Fields } = await import('@/Pages/Public/Community/QuestionnaireFields');
    const options = [
        { id: 'one', label: 'First' },
        { id: 'two', label: 'Second' },
    ];
    for (const type of [
        'multiple_choice',
        'quiz',
        'yes_no',
        'rating',
        'multi_select',
        'ranking',
        'word_cloud',
        'open',
        'scale',
        'number',
    ]) {
        const { unmount } = render(
            <Fields
                question={{
                    id: type,
                    title: `${type} question`,
                    type,
                    required: true,
                    options,
                    settings: { min: 1, max: 5 },
                }}
                onChange={vi.fn()}
            />
        );
        expect(screen.getByText(`${type} question *`)).toBeTruthy();
        unmount();
    }
});
