import { render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import Account from '@/Pages/Tenant/Account/Index';

it('renders account security outside a tenant without generating tenant navigation', () => {
    globalThis.route = vi.fn((name) => {
        if (name.startsWith('tenant.')) {
            throw new Error('No tenant subdomain exists for a global account');
        }
        return `/${name}`;
    });
    render(
        <Account
            account={{
                name: 'Independent Reviewer',
                email: 'independent@example.test',
                two_factor_enabled: false,
            }}
            accountRoutePrefix="workspaces.account"
        />
    );
    expect(screen.getByRole('heading', { name: 'My account' })).toBeTruthy();
    expect(screen.getByRole('heading', { name: 'Two-factor authentication' })).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Your workspaces' }).getAttribute('href')).toBe(
        '/workspaces.index'
    );
    expect(screen.getByRole('button', { name: 'Set up two-factor authentication' })).toBeTruthy();
    expect(screen.getByLabelText('New email address')).toBeTruthy();
});

it('preserves tenant account navigation for the established account page', () => {
    globalThis.route = vi.fn((name) => `http://review-alpha.example.test/${name}`);
    render(
        <Account
            account={{
                name: 'Tenant Reviewer',
                email: 'tenant@example.test',
                two_factor_enabled: false,
            }}
        />
    );
    expect(screen.getByRole('heading', { name: 'My account' })).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Dashboard' }).getAttribute('href')).toContain(
        'tenant.dashboard'
    );
    expect(
        screen.getByRole('link', { name: 'Switch workspace or create organisation' })
    ).toBeTruthy();
});
