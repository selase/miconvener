import { beforeEach, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import PublicProfileEditor from '@/Pages/Public/Events/AttendeePortal/panels/PublicProfileEditor';
import PublicAttendeeStrip from '@/Pages/Public/Events/Components/PublicAttendeeStrip';
import csrfFetch from '@/lib/csrfFetch';
vi.mock('@/lib/csrfFetch', () => ({ default: vi.fn(), csrfFetchFormData: vi.fn() }));
beforeEach(() => {
    vi.resetAllMocks();
    global.fetch = vi.fn();
});
const reply = (profile) => ({ ok: true, json: async () => ({ profile }) });
it('starts private with no legal name prefill or checked consent', async () => {
    csrfFetch.mockResolvedValue(reply({ eligible: true, state: 'private', display_name: '' }));
    render(<PublicProfileEditor registrationId="ticket" isOnline />);
    expect((await screen.findByLabelText('Public display name')).value).toBe('');
    expect(screen.getByLabelText(/I am 18/).checked).toBe(false);
    expect(screen.getByLabelText(/I agree to publish/).checked).toBe(false);
    expect(screen.getByRole('button', { name: 'Publish my profile' }).disabled).toBe(true);
});
it('requires fresh adult and consent choices before submitting chosen content', async () => {
    csrfFetch
        .mockResolvedValueOnce(reply({ eligible: true, state: 'private', display_name: '' }))
        .mockResolvedValueOnce(
            reply({ eligible: true, state: 'published', display_name: 'My name', revision: 'new' })
        );
    render(<PublicProfileEditor registrationId="ticket" isOnline />);
    fireEvent.change(await screen.findByLabelText('Public display name'), {
        target: { value: 'My name' },
    });
    fireEvent.click(screen.getByLabelText(/I am 18/));
    fireEvent.click(screen.getByLabelText(/I agree to publish/));
    fireEvent.click(screen.getByRole('button', { name: 'Publish my profile' }));
    await waitFor(() => expect(csrfFetch).toHaveBeenCalledTimes(2));
    expect(JSON.parse(csrfFetch.mock.calls[1][1].body)).toMatchObject({
        display_name: 'My name',
        adult: true,
        consent: true,
    });
    expect(await screen.findByRole('button', { name: 'Remove my public profile' })).not.toBeNull();
});
it('hides the editor when disabled and never fetches personal data offline', async () => {
    render(<PublicProfileEditor registrationId="ticket" isOnline={false} />);
    expect(csrfFetch).not.toHaveBeenCalled();
    expect(screen.queryByLabelText('Public display name')).toBeNull();
});
it('does not expose an empty or disabled public directory', async () => {
    fetch.mockResolvedValue({ ok: false });
    render(<PublicAttendeeStrip eventId="event" />);
    await waitFor(() => expect(fetch).toHaveBeenCalled());
    expect(screen.queryByText('Public attendees')).toBeNull();
});
it('renders only consenting chosen names with initials and no fake headshots', async () => {
    fetch.mockResolvedValue({
        ok: true,
        json: async () => ({
            profiles: [{ id: 'p', display_name: 'Chosen name', initials: 'CN', photo_url: null }],
            next: null,
        }),
    });
    render(<PublicAttendeeStrip eventId="event" />);
    expect(await screen.findByText('Chosen name')).not.toBeNull();
    expect(screen.queryByRole('img')).toBeNull();
});

it('withdraws immediately without requiring fresh consent or saving a draft', async () => {
    csrfFetch
        .mockResolvedValueOnce(
            reply({
                eligible: true,
                state: 'published',
                display_name: 'Public name',
                revision: 'old',
            })
        )
        .mockResolvedValueOnce(reply({ eligible: true, state: 'private', display_name: '' }));
    render(<PublicProfileEditor registrationId="ticket" isOnline />);
    fireEvent.click(await screen.findByRole('button', { name: 'Remove my public profile' }));
    await waitFor(() => expect(csrfFetch).toHaveBeenCalledTimes(2));
    expect(csrfFetch.mock.calls[1][1].method).toBe('DELETE');
    await waitFor(() => expect(screen.getByLabelText('Public display name').value).toBe(''));
});

it('keeps the existing published choice when saving fails', async () => {
    csrfFetch
        .mockResolvedValueOnce(
            reply({ eligible: true, state: 'published', display_name: 'Original', revision: 'old' })
        )
        .mockResolvedValueOnce({
            ok: false,
            json: async () => ({ message: 'Please verify again.' }),
        });
    render(<PublicProfileEditor registrationId="ticket" isOnline />);
    fireEvent.change(await screen.findByLabelText('Public display name'), {
        target: { value: 'Draft name' },
    });
    fireEvent.click(screen.getByLabelText(/I am 18/));
    fireEvent.click(screen.getByLabelText(/I agree to publish/));
    fireEvent.click(screen.getByRole('button', { name: 'Publish my profile' }));
    expect(await screen.findByRole('alert')).not.toBeNull();
    expect(screen.getByRole('button', { name: 'Remove my public profile' })).not.toBeNull();
});

it('clears the previous event profiles while a different event loads', async () => {
    fetch
        .mockResolvedValueOnce({
            ok: true,
            json: async () => ({
                profiles: [{ id: 'p', display_name: 'Previous event attendee', initials: 'PE' }],
                next: null,
            }),
        })
        .mockImplementationOnce(() => new Promise(() => {}));
    const { rerender } = render(<PublicAttendeeStrip eventId="first" />);
    await screen.findByText('Previous event attendee');
    rerender(<PublicAttendeeStrip eventId="second" />);
    expect(screen.queryByText('Previous event attendee')).toBeNull();
});
