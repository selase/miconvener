import { useEffect, useState, useRef } from 'react';
import {
    Trash2,
    Plus,
    Link2,
    Mail,
    Edit3,
    CheckCircle,
    Clock,
    XCircle,
    Upload,
    Loader2,
} from 'lucide-react';
import Input from '@/Components/Console/Input';
import Select from '@/Components/Console/Select';
import Button from '@/Components/Console/Button';
import Modal from '@/Components/Console/Modal';
import { useToast } from '@/Components/Console/Toast';
import csrfFetch, { csrfFetchFormData } from '@/lib/csrfFetch';

export default function SpeakersPanel({ event, speakers, onChange }) {
    const [directory, setDirectory] = useState([]);
    const [selectedSpeakerId, setSelectedSpeakerId] = useState('');
    const [invitingId, setInvitingId] = useState(null);
    const [saving, setSaving] = useState(false);

    // New speaker form state
    const [newSpeaker, setNewSpeaker] = useState({
        name: '',
        email: '',
        title: '',
        organization: '',
        bio: '',
        website_url: '',
        linkedin_url: '',
        twitter_url: '',
    });
    const [newPhoto, setNewPhoto] = useState(null);
    const [newPhotoPreview, setNewPhotoPreview] = useState(null);
    const newPhotoInputRef = useRef(null);

    // Edit modal state
    const [editingSpeaker, setEditingSpeaker] = useState(null);
    const [editPhoto, setEditPhoto] = useState(null);
    const [editPhotoPreview, setEditPhotoPreview] = useState(null);
    const editPhotoInputRef = useRef(null);

    const toast = useToast();

    useEffect(() => {
        loadDirectory();
    }, []);

    const loadDirectory = () => {
        csrfFetch(route('tenant.speakers.index'))
            .then((r) => r.json())
            .then(setDirectory);
    };

    const handleNewPhotoChange = (e) => {
        const file = e.target.files?.[0];
        if (file) {
            setNewPhoto(file);
            setNewPhotoPreview(URL.createObjectURL(file));
        }
    };

    const handleEditPhotoChange = (e) => {
        const file = e.target.files?.[0];
        if (file) {
            setEditPhoto(file);
            setEditPhotoPreview(URL.createObjectURL(file));
        }
    };

    const attachExisting = async (e) => {
        e.preventDefault();
        if (!selectedSpeakerId) return;

        await csrfFetch(route('tenant.events.speakers.store', { event: event.id }), {
            method: 'POST',
            body: JSON.stringify({ speaker_id: selectedSpeakerId }),
        });

        setSelectedSpeakerId('');
        onChange();
        toast?.('Speaker added to event.');
    };

    const createAndAttach = async (e) => {
        e.preventDefault();
        setSaving(true);

        try {
            const formData = new FormData();
            formData.append('name', newSpeaker.name);
            formData.append('email', newSpeaker.email);
            if (newSpeaker.title) formData.append('title', newSpeaker.title);
            if (newSpeaker.organization) formData.append('organization', newSpeaker.organization);
            if (newSpeaker.bio) formData.append('bio', newSpeaker.bio);
            if (newSpeaker.website_url) formData.append('website_url', newSpeaker.website_url);
            if (newSpeaker.linkedin_url) formData.append('linkedin_url', newSpeaker.linkedin_url);
            if (newSpeaker.twitter_url) formData.append('twitter_url', newSpeaker.twitter_url);
            if (newPhoto) formData.append('photo', newPhoto);

            const response = await csrfFetchFormData(route('tenant.speakers.store'), formData);
            if (!response.ok) {
                const err = await response.json();
                toast?.(err.message || 'Failed to create speaker.');
                setSaving(false);
                return;
            }

            const speaker = await response.json();

            await csrfFetch(route('tenant.events.speakers.store', { event: event.id }), {
                method: 'POST',
                body: JSON.stringify({ speaker_id: speaker.id }),
            });

            setDirectory((prev) => [...prev, speaker]);
            setNewSpeaker({
                name: '',
                email: '',
                title: '',
                organization: '',
                bio: '',
                website_url: '',
                linkedin_url: '',
                twitter_url: '',
            });
            setNewPhoto(null);
            setNewPhotoPreview(null);
            if (newPhotoInputRef.current) newPhotoInputRef.current.value = '';

            onChange();
            toast?.('Speaker created and added to event.');
        } catch {
            toast?.('An error occurred.');
        } finally {
            setSaving(false);
        }
    };

    const saveEditSpeaker = async (e) => {
        e.preventDefault();
        if (!editingSpeaker) return;
        setSaving(true);

        try {
            const formData = new FormData();
            formData.append('_method', 'PUT');
            formData.append('name', editingSpeaker.name);
            formData.append('email', editingSpeaker.email);
            if (editingSpeaker.title) formData.append('title', editingSpeaker.title);
            if (editingSpeaker.organization) formData.append('organization', editingSpeaker.organization);
            if (editingSpeaker.bio) formData.append('bio', editingSpeaker.bio);
            if (editingSpeaker.website_url) formData.append('website_url', editingSpeaker.website_url);
            if (editingSpeaker.linkedin_url) formData.append('linkedin_url', editingSpeaker.linkedin_url);
            if (editingSpeaker.twitter_url) formData.append('twitter_url', editingSpeaker.twitter_url);
            if (editPhoto) formData.append('photo', editPhoto);

            const response = await csrfFetchFormData(
                route('tenant.speakers.update', { speaker: editingSpeaker.id }),
                formData
            );

            if (!response.ok) {
                const err = await response.json();
                toast?.(err.message || 'Failed to update speaker.');
                setSaving(false);
                return;
            }

            const updated = await response.json();
            setDirectory((prev) => prev.map((s) => (s.id === updated.id ? updated : s)));
            setEditingSpeaker(null);
            setEditPhoto(null);
            setEditPhotoPreview(null);
            onChange();
            toast?.('Speaker profile updated.');
        } catch {
            toast?.('Failed to update speaker.');
        } finally {
            setSaving(false);
        }
    };

    const remove = async (speakerId) => {
        await csrfFetch(
            route('tenant.events.speakers.destroy', { event: event.id, speaker: speakerId }),
            { method: 'DELETE' }
        );
        onChange();
        toast?.('Speaker removed from event.');
    };

    const copyPortalLink = async (speakerId) => {
        const response = await csrfFetch(
            route('tenant.events.speakers.portal-link', { event: event.id, speaker: speakerId })
        );
        const { portal_url: portalUrl } = await response.json();
        await navigator.clipboard.writeText(portalUrl);
        toast?.('Speaker portal link copied to clipboard.');
    };

    const sendInviteEmail = async (speakerId) => {
        setInvitingId(speakerId);
        try {
            const response = await csrfFetch(
                route('tenant.events.speakers.invite', { event: event.id, speaker: speakerId }),
                { method: 'POST' }
            );

            const data = await response.json();
            if (response.ok) {
                toast?.(data.message || 'Invitation email dispatched.');
                onChange();
            } else {
                toast?.(data.message || 'Failed to send invitation email.');
            }
        } catch {
            toast?.('An error occurred sending invitation.');
        } finally {
            setInvitingId(null);
        }
    };

    const attachedIds = new Set(speakers.map((s) => s.id));
    const available = directory.filter((s) => !attachedIds.has(s.id));

    return (
        <div className="max-w-3xl space-y-8">
            {/* Event Speakers List */}
            <div>
                <div className="mb-3 flex items-center justify-between">
                    <h3 className="text-base font-semibold text-ink">Event Speakers ({speakers.length})</h3>
                </div>

                {speakers.length > 0 ? (
                    <div className="divide-y divide-border rounded-lg border border-border bg-surface">
                        {speakers.map((speaker) => (
                            <div
                                key={speaker.id}
                                className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div className="flex items-center gap-3.5">
                                    {speaker.photo_url ? (
                                        <img
                                            src={speaker.photo_url}
                                            alt={speaker.name}
                                            className="h-12 w-12 rounded-full border border-border object-cover shrink-0"
                                        />
                                    ) : (
                                        <div className="grid h-12 w-12 shrink-0 place-items-center rounded-full bg-surface-alt border border-border text-sm font-semibold text-ink-secondary">
                                            {speaker.name
                                                .split(' ')
                                                .map((w) => w[0])
                                                .slice(0, 2)
                                                .join('')
                                                .toUpperCase()}
                                        </div>
                                    )}

                                    <div>
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="font-medium text-ink">{speaker.name}</span>
                                            {speaker.is_confirmed === true && (
                                                <span className="inline-flex items-center gap-1 rounded bg-accent-soft px-2 py-0.5 text-[11px] font-medium text-accent">
                                                    <CheckCircle className="h-3 w-3" /> Confirmed
                                                </span>
                                            )}
                                            {speaker.is_confirmed === false && (
                                                <span className="inline-flex items-center gap-1 rounded bg-danger-bg px-2 py-0.5 text-[11px] font-medium text-danger-fg">
                                                    <XCircle className="h-3 w-3" /> Declined
                                                </span>
                                            )}
                                            {speaker.is_confirmed === null || speaker.is_confirmed === undefined ? (
                                                <span className="inline-flex items-center gap-1 rounded bg-surface-alt px-2 py-0.5 text-[11px] font-medium text-ink-secondary">
                                                    <Clock className="h-3 w-3" /> Pending confirmation
                                                </span>
                                            ) : null}
                                        </div>

                                        <div className="mt-0.5 text-xs text-ink-secondary">
                                            {[speaker.title, speaker.organization].filter(Boolean).join(' • ') || 'No title specified'}
                                        </div>

                                        <div className="mt-0.5 text-xs text-ink-tertiary">
                                            {speaker.email || 'No email on file'}
                                            {speaker.last_invited_at && (
                                                <span className="ml-2">
                                                    • Last invited:{' '}
                                                    {new Date(speaker.last_invited_at).toLocaleDateString(undefined, {
                                                        month: 'short',
                                                        day: 'numeric',
                                                        hour: '2-digit',
                                                        minute: '2-digit',
                                                    })}
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                </div>

                                <div className="flex shrink-0 items-center gap-2 self-end sm:self-center">
                                    <button
                                        type="button"
                                        onClick={() => sendInviteEmail(speaker.id)}
                                        disabled={invitingId === speaker.id || !speaker.email}
                                        title={speaker.email ? 'Send portal invitation email' : 'Add email first to invite'}
                                        className="inline-flex items-center gap-1.5 rounded border border-border px-2.5 py-1.5 text-xs font-medium text-ink hover:border-accent hover:text-accent disabled:opacity-50"
                                    >
                                        {invitingId === speaker.id ? (
                                            <Loader2 className="h-3.5 w-3.5 animate-spin" />
                                        ) : (
                                            <Mail className="h-3.5 w-3.5 text-accent" />
                                        )}
                                        <span>Invite</span>
                                    </button>

                                    <button
                                        type="button"
                                        onClick={() => copyPortalLink(speaker.id)}
                                        title="Copy speaker portal link"
                                        className="inline-flex items-center gap-1.5 rounded border border-border px-2.5 py-1.5 text-xs font-medium text-ink hover:border-accent hover:text-accent"
                                    >
                                        <Link2 className="h-3.5 w-3.5" />
                                        <span>Link</span>
                                    </button>

                                    <button
                                        type="button"
                                        onClick={() => {
                                            setEditingSpeaker({ ...speaker });
                                            setEditPhoto(null);
                                            setEditPhotoPreview(speaker.photo_url || null);
                                        }}
                                        title="Edit speaker profile"
                                        className="grid h-8 w-8 place-items-center rounded border border-border text-ink-secondary hover:border-accent hover:text-ink"
                                    >
                                        <Edit3 className="h-3.5 w-3.5" />
                                    </button>

                                    <button
                                        type="button"
                                        onClick={() => remove(speaker.id)}
                                        title="Remove speaker from event"
                                        className="grid h-8 w-8 place-items-center rounded border border-border text-ink-secondary hover:border-danger-fg/40 hover:text-danger-fg"
                                    >
                                        <Trash2 className="h-3.5 w-3.5" />
                                    </button>
                                </div>
                            </div>
                        ))}
                    </div>
                ) : (
                    <div className="rounded-lg border border-dashed border-border p-6 text-center text-sm text-ink-secondary">
                        No speakers attached to this event yet. Add from directory or create one below.
                    </div>
                )}
            </div>

            {/* Attach Existing Speaker */}
            {available.length > 0 && (
                <div className="rounded-lg border border-border bg-surface p-4">
                    <h4 className="mb-2.5 text-xs font-semibold uppercase tracking-wider text-ink-secondary">
                        Add from Speaker Directory
                    </h4>
                    <form onSubmit={attachExisting} className="flex items-end gap-3">
                        <div className="flex-1">
                            <Select
                                value={selectedSpeakerId}
                                onChange={(e) => setSelectedSpeakerId(e.target.value)}
                            >
                                <option value="">Select a speaker from directory...</option>
                                {available.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name} {s.title ? `(${s.title})` : ''} — {s.email || 'No email'}
                                    </option>
                                ))}
                            </Select>
                        </div>
                        <Button type="submit" icon={Plus}>
                            Add
                        </Button>
                    </form>
                </div>
            )}

            {/* Create New Speaker Form */}
            <div className="rounded-lg border border-border bg-surface p-5">
                <h3 className="mb-4 text-sm font-semibold text-ink">Create New Speaker & Add to Event</h3>
                <form onSubmit={createAndAttach} className="space-y-4">
                    {/* Headshot Upload & Preview */}
                    <div className="flex items-center gap-4">
                        <div className="relative">
                            {newPhotoPreview ? (
                                <img
                                    src={newPhotoPreview}
                                    alt="Preview"
                                    className="h-16 w-16 rounded-full border border-border object-cover"
                                />
                            ) : (
                                <div className="grid h-16 w-16 place-items-center rounded-full border border-dashed border-border bg-surface-alt text-ink-tertiary">
                                    <Upload className="h-5 w-5" />
                                </div>
                            )}
                        </div>
                        <div>
                            <label className="block text-xs font-medium text-ink">Headshot Photo</label>
                            <input
                                ref={newPhotoInputRef}
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                onChange={handleNewPhotoChange}
                                className="mt-1 block text-xs text-ink-secondary file:mr-2 file:rounded file:border-0 file:bg-surface-alt file:px-2.5 file:py-1 file:text-xs file:font-medium file:text-ink hover:file:bg-surface-hover"
                            />
                            <p className="mt-0.5 text-[11px] text-ink-tertiary">JPEG, PNG, or WebP up to 3MB</p>
                        </div>
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <Input
                            label="Full Name"
                            value={newSpeaker.name}
                            onChange={(e) => setNewSpeaker({ ...newSpeaker, name: e.target.value })}
                            required
                            placeholder="e.g. Dr. Kwame Nkrumah"
                        />
                        <Input
                            label="Email Address"
                            type="email"
                            value={newSpeaker.email}
                            onChange={(e) => setNewSpeaker({ ...newSpeaker, email: e.target.value })}
                            required
                            placeholder="speaker@example.com"
                        />
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <Input
                            label="Professional Title"
                            value={newSpeaker.title}
                            onChange={(e) => setNewSpeaker({ ...newSpeaker, title: e.target.value })}
                            placeholder="e.g. Keynote Speaker / Founder"
                        />
                        <Input
                            label="Organization / Affiliation"
                            value={newSpeaker.organization}
                            onChange={(e) => setNewSpeaker({ ...newSpeaker, organization: e.target.value })}
                            placeholder="e.g. University of Ghana"
                        />
                    </div>

                    <div>
                        <label className="mb-1.5 block text-xs font-medium text-ink">Speaker Bio</label>
                        <textarea
                            value={newSpeaker.bio}
                            onChange={(e) => setNewSpeaker({ ...newSpeaker, bio: e.target.value })}
                            rows={3}
                            placeholder="Short biography highlighting speaker's background and achievements..."
                            className="w-full rounded-lg border border-border px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                        />
                    </div>

                    <div className="grid gap-3 sm:grid-cols-3">
                        <Input
                            label="Website URL"
                            type="url"
                            value={newSpeaker.website_url}
                            onChange={(e) => setNewSpeaker({ ...newSpeaker, website_url: e.target.value })}
                            placeholder="https://speaker.com"
                        />
                        <Input
                            label="LinkedIn Profile"
                            type="url"
                            value={newSpeaker.linkedin_url}
                            onChange={(e) => setNewSpeaker({ ...newSpeaker, linkedin_url: e.target.value })}
                            placeholder="https://linkedin.com/in/..."
                        />
                        <Input
                            label="Twitter / X Profile"
                            type="url"
                            value={newSpeaker.twitter_url}
                            onChange={(e) => setNewSpeaker({ ...newSpeaker, twitter_url: e.target.value })}
                            placeholder="https://x.com/..."
                        />
                    </div>

                    <div className="pt-2">
                        <Button type="submit" variant="primary" disabled={saving} icon={Plus}>
                            {saving ? 'Creating…' : 'Create & Add Speaker'}
                        </Button>
                    </div>
                </form>
            </div>

            {/* Edit Speaker Profile Modal */}
            <Modal
                open={Boolean(editingSpeaker)}
                onClose={() => {
                    setEditingSpeaker(null);
                    setEditPhoto(null);
                    setEditPhotoPreview(null);
                }}
                title="Edit Speaker Profile"
                className="max-w-lg"
            >
                {editingSpeaker && (
                    <form onSubmit={saveEditSpeaker} className="space-y-4">
                        {/* Edit Photo */}
                        <div className="flex items-center gap-4">
                            <div className="relative">
                                {editPhotoPreview ? (
                                    <img
                                        src={editPhotoPreview}
                                        alt="Preview"
                                        className="h-16 w-16 rounded-full border border-border object-cover"
                                    />
                                ) : (
                                    <div className="grid h-16 w-16 place-items-center rounded-full border border-dashed border-border bg-surface-alt text-ink-tertiary">
                                        <Upload className="h-5 w-5" />
                                    </div>
                                )}
                            </div>
                            <div>
                                <label className="block text-xs font-medium text-ink">Change Headshot Photo</label>
                                <input
                                    ref={editPhotoInputRef}
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp"
                                    onChange={handleEditPhotoChange}
                                    className="mt-1 block text-xs text-ink-secondary file:mr-2 file:rounded file:border-0 file:bg-surface-alt file:px-2.5 file:py-1 file:text-xs file:font-medium file:text-ink hover:file:bg-surface-hover"
                                />
                                <p className="mt-0.5 text-[11px] text-ink-tertiary">JPEG, PNG, or WebP up to 3MB</p>
                            </div>
                        </div>

                        <div className="grid gap-3 sm:grid-cols-2">
                            <Input
                                label="Full Name"
                                value={editingSpeaker.name}
                                onChange={(e) =>
                                    setEditingSpeaker({ ...editingSpeaker, name: e.target.value })
                                }
                                required
                            />
                            <Input
                                label="Email Address"
                                type="email"
                                value={editingSpeaker.email || ''}
                                onChange={(e) =>
                                    setEditingSpeaker({ ...editingSpeaker, email: e.target.value })
                                }
                                required
                            />
                        </div>

                        <div className="grid gap-3 sm:grid-cols-2">
                            <Input
                                label="Title"
                                value={editingSpeaker.title || ''}
                                onChange={(e) =>
                                    setEditingSpeaker({ ...editingSpeaker, title: e.target.value })
                                }
                            />
                            <Input
                                label="Organization"
                                value={editingSpeaker.organization || ''}
                                onChange={(e) =>
                                    setEditingSpeaker({ ...editingSpeaker, organization: e.target.value })
                                }
                            />
                        </div>

                        <div>
                            <label className="mb-1.5 block text-xs font-medium text-ink">Bio</label>
                            <textarea
                                value={editingSpeaker.bio || ''}
                                onChange={(e) =>
                                    setEditingSpeaker({ ...editingSpeaker, bio: e.target.value })
                                }
                                rows={3}
                                className="w-full rounded-lg border border-border px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                            />
                        </div>

                        <div className="grid gap-3 sm:grid-cols-3">
                            <Input
                                label="Website URL"
                                type="url"
                                value={editingSpeaker.website_url || ''}
                                onChange={(e) =>
                                    setEditingSpeaker({ ...editingSpeaker, website_url: e.target.value })
                                }
                                placeholder="https://"
                            />
                            <Input
                                label="LinkedIn"
                                type="url"
                                value={editingSpeaker.linkedin_url || ''}
                                onChange={(e) =>
                                    setEditingSpeaker({ ...editingSpeaker, linkedin_url: e.target.value })
                                }
                                placeholder="https://"
                            />
                            <Input
                                label="Twitter / X"
                                type="url"
                                value={editingSpeaker.twitter_url || ''}
                                onChange={(e) =>
                                    setEditingSpeaker({ ...editingSpeaker, twitter_url: e.target.value })
                                }
                                placeholder="https://"
                            />
                        </div>

                        <div className="flex justify-end gap-2 pt-2">
                            <button
                                type="button"
                                onClick={() => setEditingSpeaker(null)}
                                className="inline-flex h-control items-center rounded-md border border-border px-4 text-xs font-medium text-ink hover:border-accent"
                            >
                                Cancel
                            </button>
                            <Button type="submit" variant="primary" disabled={saving}>
                                {saving ? 'Saving…' : 'Save Changes'}
                            </Button>
                        </div>
                    </form>
                )}
            </Modal>
        </div>
    );
}
