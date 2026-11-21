import { describe, it, expect } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import Index from '@/Pages/Workspaces/Index';
import Create from '@/Pages/Workspaces/Create';

const auth = { user: { email: 'owner@example.org' } };

describe('workspace navigation and creation', () => {
    it('offers creation and invitation guidance when no memberships remain', () => {
        render(<Index auth={auth} canCreateOrganisation />);
        expect(screen.getByText(/no active organisation memberships/)).toBeTruthy();
        expect(
            screen
                .getByRole('link', { name: 'Create your first organisation' })
                .getAttribute('href')
        ).toContain('workspaces.create');
    });

    it('opens active workspaces across hosts and withholds unavailable workspace links', () => {
        render(
            <Index
                auth={auth}
                workspaces={[
                    {
                        id: 'a',
                        name: 'Active',
                        slug: 'active',
                        available: true,
                        url: 'https://active.example.org/dashboard',
                    },
                    {
                        id: 'b',
                        name: 'Unavailable',
                        slug: 'unavailable',
                        available: false,
                        url: null,
                    },
                ]}
            />
        );
        expect(
            screen.getByRole('link', { name: 'Open workspace for Active' }).getAttribute('href')
        ).toBe('https://active.example.org/dashboard');
        expect(screen.getByText('This organisation is currently unavailable.')).toBeTruthy();
        expect(screen.queryByRole('link', { name: /Open workspace for Unavailable/ })).toBeNull();
    });

    it('makes an interrupted setup discoverable and resumable', () => {
        render(
            <Index
                auth={auth}
                canCreateOrganisation
                pendingWorkspaces={[
                    {
                        name: 'Saved org',
                        state: 'failed',
                        retry_url: '/workspaces/create?request_key=saved',
                    },
                ]}
            />
        );
        expect(screen.getByText(/Your organisation is saved/)).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Resume setup' }).getAttribute('href')).toContain(
            'request_key=saved'
        );
    });

    it('uses the existing account without requesting credentials and explains the free plan', () => {
        render(<Create auth={auth} requestKey="key" defaults={{}} />);
        expect(screen.getByText(/owner@example.org/)).toBeTruthy();
        expect(screen.queryByLabelText(/password/i)).toBeNull();
        expect(screen.getByText('Start on Free')).toBeTruthy();
        const name = screen.getByLabelText('Organisation name');
        fireEvent.change(name, { target: { value: 'My organisation' } });
        expect(name.value).toBe('My organisation');
        expect(screen.getByRole('button', { name: 'Create organisation' })).toBeTruthy();
    });

    it('keeps saved recovery identity immutable in the form', () => {
        render(
            <Create
                auth={auth}
                requestKey="key"
                defaults={{ organization_name: 'Saved org', slug: 'saved-org' }}
                recovering
            />
        );
        expect(screen.getByLabelText('Organisation name').readOnly).toBe(true);
        expect(screen.getByLabelText('Workspace address').value).toBe('saved-org');
        expect(screen.getByLabelText('Workspace address').readOnly).toBe(true);
        expect(screen.getByRole('button', { name: 'Resume setup' })).toBeTruthy();
    });
});

describe('disabled organisation rollout', () => {
    it('hides creation and recovery links while keeping existing workspaces accessible', () => {
        render(
            <Index
                auth={auth}
                canCreateOrganisation={false}
                workspaces={[
                    {
                        id: 'a',
                        name: 'Existing',
                        slug: 'existing',
                        available: true,
                        url: 'https://existing.example.org/dashboard',
                    },
                ]}
                pendingWorkspaces={[
                    {
                        name: 'Saved',
                        state: 'failed',
                        retry_url: '/workspaces/create?request_key=saved',
                    },
                ]}
            />
        );
        expect(screen.getByText('Organisation creation temporarily unavailable')).toBeTruthy();
        expect(screen.queryByRole('link', { name: 'Create organisation' })).toBeNull();
        expect(screen.queryByRole('link', { name: 'Resume setup' })).toBeNull();
        expect(screen.getByRole('link', { name: 'Open workspace for Existing' })).toBeTruthy();
    });

    it('withholds the empty-state creation CTA when the flag is absent', () => {
        render(<Index auth={auth} />);
        expect(screen.getByText('Organisation creation temporarily unavailable')).toBeTruthy();
        expect(screen.queryByRole('link', { name: 'Create your first organisation' })).toBeNull();
    });
});
