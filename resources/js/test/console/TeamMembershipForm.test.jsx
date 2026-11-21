import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import TeamMemberForm from '@/Pages/Tenant/Team/Form';

const props = {
    data: { email: 'person@example.org', role: 1, status: 'active' },
    setData: vi.fn(),
    errors: {},
    roles: [{ id: 1, name: 'Org Admin' }],
    statuses: ['active', 'suspended'],
};

describe('organisation membership form', () => {
    it('requests an email for an invitation without assigning global profile or password', () => {
        render(<TeamMemberForm {...props} />);
        expect(screen.getByLabelText('Email').disabled).toBe(false);
        expect(screen.queryByLabelText('First name')).toBeNull();
        expect(screen.queryByLabelText('Phone number')).toBeNull();
        expect(screen.queryByLabelText('Organisation access')).toBeNull();
    });

    it('edits only organisation access and keeps identity read only', () => {
        render(<TeamMemberForm {...props} editing />);
        expect(screen.getByLabelText('Email').disabled).toBe(true);
        expect(screen.getByLabelText('Organisation access').value).toBe('active');
        expect(screen.getByRole('option', { name: 'Suspended' })).toBeTruthy();
    });
});
