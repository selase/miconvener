import { useEffect, useState } from 'react';
import { Trash2, Plus, Link2 } from 'lucide-react';
import Input from '@/Components/Console/Input';
import Select from '@/Components/Console/Select';
import Button from '@/Components/Console/Button';
import { useToast } from '@/Components/Console/Toast';
import csrfFetch from '@/lib/csrfFetch';

export default function SpeakersPanel({ event, speakers, onChange }) {
    const [directory, setDirectory] = useState([]);
    const [selectedSpeakerId, setSelectedSpeakerId] = useState('');
    const [newSpeaker, setNewSpeaker] = useState({ name: '', title: '', organization: '', bio: '' });
    const toast = useToast();

    useEffect(() => {
        csrfFetch(route('tenant.speakers.index'))
            .then((r) => r.json())
            .then(setDirectory);
    }, []);

    const attachExisting = async (e) => {
        e.preventDefault();
        if (!selectedSpeakerId) return;

        await csrfFetch(route('tenant.events.speakers.store', { event: event.id }), {
            method: 'POST',
            body: JSON.stringify({ speaker_id: selectedSpeakerId }),
        });

        setSelectedSpeakerId('');
        onChange();
    };

    const createAndAttach = async (e) => {
        e.preventDefault();

        const response = await csrfFetch(route('tenant.speakers.store'), {
            method: 'POST',
            body: JSON.stringify(newSpeaker),
        });
        const speaker = await response.json();

        await csrfFetch(route('tenant.events.speakers.store', { event: event.id }), {
            method: 'POST',
            body: JSON.stringify({ speaker_id: speaker.id }),
        });

        setDirectory((prev) => [...prev, speaker]);
        setNewSpeaker({ name: '', title: '', organization: '', bio: '' });
        onChange();
        toast?.('Speaker added.');
    };

    const remove = async (speakerId) => {
        await csrfFetch(route('tenant.events.speakers.destroy', { event: event.id, speaker: speakerId }), { method: 'DELETE' });
        onChange();
    };

    const copyPortalLink = async (speakerId) => {
        const response = await csrfFetch(route('tenant.events.speakers.portal-link', { event: event.id, speaker: speakerId }));
        const { portal_url: portalUrl } = await response.json();
        await navigator.clipboard.writeText(portalUrl);
        toast?.('Portal link copied.');
    };

    const attachedIds = new Set(speakers.map((s) => s.id));
    const available = directory.filter((s) => !attachedIds.has(s.id));

    return (
        <div className="max-w-2xl">
            {speakers.length > 0 ? (
                <ul className="mb-6 divide-y divide-border rounded-md border border-border">
                    {speakers.map((speaker) => (
                        <li key={speaker.id} className="flex items-center justify-between px-4 py-3">
                            <div>
                                <div className="text-sm font-medium text-ink">{speaker.name}</div>
                                <div className="text-xs text-ink-secondary">
                                    {[speaker.title, speaker.organization].filter(Boolean).join(', ')}
                                </div>
                            </div>
                            <div className="flex shrink-0 items-center gap-3">
                                <button type="button" onClick={() => copyPortalLink(speaker.id)} title="Copy speaker portal link" className="text-ink-secondary hover:text-accent">
                                    <Link2 className="h-4 w-4" strokeWidth={1.75} />
                                </button>
                                <button type="button" onClick={() => remove(speaker.id)} className="text-ink-secondary hover:text-danger-fg">
                                    <Trash2 className="h-4 w-4" strokeWidth={1.75} />
                                </button>
                            </div>
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="mb-6 text-sm text-ink-secondary">No speakers added yet.</p>
            )}

            {available.length > 0 && (
                <form onSubmit={attachExisting} className="mb-6 flex items-end gap-3">
                    <div className="flex-1">
                        <Select label="Add existing speaker" value={selectedSpeakerId} onChange={(e) => setSelectedSpeakerId(e.target.value)}>
                            <option value="">Select a speaker</option>
                            {available.map((s) => (
                                <option key={s.id} value={s.id}>{s.name}</option>
                            ))}
                        </Select>
                    </div>
                    <Button type="submit" icon={Plus}>Add</Button>
                </form>
            )}

            <div className="rounded-md border border-border p-4">
                <h3 className="mb-3 text-sm font-semibold text-ink">Or add a new speaker</h3>
                <form onSubmit={createAndAttach} className="space-y-3">
                    <div className="grid grid-cols-2 gap-3">
                        <Input label="Name" value={newSpeaker.name} onChange={(e) => setNewSpeaker({ ...newSpeaker, name: e.target.value })} required />
                        <Input label="Title" value={newSpeaker.title} onChange={(e) => setNewSpeaker({ ...newSpeaker, title: e.target.value })} />
                    </div>
                    <Input label="Organization" value={newSpeaker.organization} onChange={(e) => setNewSpeaker({ ...newSpeaker, organization: e.target.value })} />
                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-ink">Bio</label>
                        <textarea
                            value={newSpeaker.bio}
                            onChange={(e) => setNewSpeaker({ ...newSpeaker, bio: e.target.value })}
                            rows={2}
                            className="w-full rounded-lg border border-border px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                        />
                    </div>
                    <Button type="submit" icon={Plus}>Add speaker</Button>
                </form>
            </div>
        </div>
    );
}
