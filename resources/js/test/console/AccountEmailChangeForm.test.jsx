import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import AccountEmailChangeForm from '@/Components/Console/AccountEmailChangeForm';

const { post } = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock('axios', () => ({ default: { post } }));

beforeEach(() => {
    post.mockReset();
    window.route = vi.fn(() => '/account-identity/email-changes');
});

it('requests a new verified address with password and enrolled authenticator proof', async () => {
    post.mockResolvedValue({ data: { message: 'Check your new mailbox.' } });
    render(
        <AccountEmailChangeForm
            account={{ email: 'original@example.org', two_factor_enabled: true }}
        />
    );
    fireEvent.change(screen.getByLabelText('New email address'), {
        target: { value: 'new@example.org' },
    });
    fireEvent.change(screen.getByLabelText('Current password'), {
        target: { value: 'current-password' },
    });
    fireEvent.change(screen.getByLabelText('Authenticator code'), { target: { value: '123456' } });
    fireEvent.click(screen.getByRole('button', { name: 'Send verification link' }));
    await waitFor(() =>
        expect(post).toHaveBeenCalledWith(
            '/account-identity/email-changes',
            { email: 'new@example.org', password: 'current-password', code: '123456' },
            expect.any(Object)
        )
    );
    expect((await screen.findByRole('status')).textContent).toContain('Check your new mailbox.');
    expect(window.route).toHaveBeenCalledWith('account-identity.email-changes.store', {}, false);
    expect(screen.getByLabelText('Current password').value).toBe('');
    expect(screen.getByLabelText('Authenticator code').value).toBe('');
});

it('shows field errors and never requests an authenticator for an unenrolled account', async () => {
    post.mockRejectedValue({
        response: { status: 422, data: { errors: { password: ['The password is incorrect.'] } } },
    });
    render(
        <AccountEmailChangeForm
            account={{ email: 'original@example.org', two_factor_enabled: false }}
        />
    );
    expect(screen.queryByLabelText('Authenticator code')).toBeNull();
    fireEvent.change(screen.getByLabelText('New email address'), {
        target: { value: 'new@example.org' },
    });
    fireEvent.change(screen.getByLabelText('Current password'), {
        target: { value: 'bad-password' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Send verification link' }));
    expect(await screen.findByText('The password is incorrect.')).toBeTruthy();
});

it('offers a safe retry when transport fails', async () => {
    post.mockRejectedValue(new Error('network error'));
    render(<AccountEmailChangeForm account={{ email: 'original@example.org' }} />);
    fireEvent.change(screen.getByLabelText('New email address'), {
        target: { value: 'new@example.org' },
    });
    fireEvent.change(screen.getByLabelText('Current password'), {
        target: { value: 'current-password' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Send verification link' }));
    expect((await screen.findByRole('alert')).textContent).toContain('Unable to send');
    expect(screen.getByRole('button', { name: 'Send verification link' }).disabled).toBe(false);
});
