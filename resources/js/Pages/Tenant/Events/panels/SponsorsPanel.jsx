import { useEffect, useState } from 'react';
import Button from '@/Components/Console/Button';
import Input from '@/Components/Console/Input';
import Select from '@/Components/Console/Select';
import { Download, Plus, Trash2, ChevronDown, ChevronUp } from 'lucide-react';
import csrfFetch, { csrfFetchFormData } from '@/lib/csrfFetch';

const TIERS = ['headline', 'supporting', 'partner'];
const TIER_LABEL = { headline: 'Headline', supporting: 'Supporting', partner: 'Partner' };

function formatMoney(amount, currency) {
    return `${currency} ${(amount / 100).toFixed(2)}`;
}

function DeliverableList({ event, sponsor, onChange }) {
    const [description, setDescription] = useState('');
    const [saving, setSaving] = useState(false);

    const addDeliverable = async (e) => {
        e.preventDefault();
        if (!description.trim()) return;
        setSaving(true);
        await csrfFetch(route('tenant.events.sponsors.deliverables.store', { event: event.id, sponsor: sponsor.id }), {
            method: 'POST',
            body: JSON.stringify({ description }),
        });
        setSaving(false);
        setDescription('');
        onChange();
    };

    const toggle = async (deliverable) => {
        await csrfFetch(route('tenant.events.sponsors.deliverables.update', { event: event.id, sponsor: sponsor.id, deliverable: deliverable.id }), {
            method: 'PATCH',
            body: JSON.stringify({ is_done: !deliverable.is_done }),
        });
        onChange();
    };

    const remove = async (deliverable) => {
        await csrfFetch(route('tenant.events.sponsors.deliverables.destroy', { event: event.id, sponsor: sponsor.id, deliverable: deliverable.id }), { method: 'DELETE' });
        onChange();
    };

    return (
        <div className="border-t border-border bg-surface-sunken p-3.5">
            {sponsor.deliverables.length > 0 && (
                <ul className="mb-3 divide-y divide-border">
                    {sponsor.deliverables.map((d) => (
                        <li key={d.id} className="flex items-center gap-2.5 py-2">
                            <input type="checkbox" checked={d.is_done} onChange={() => toggle(d)} className="h-3.5 w-3.5" />
                            <span className={`flex-1 text-[13px] ${d.is_done ? 'text-ink-tertiary line-through' : 'text-ink'}`}>{d.description}</span>
                            <button onClick={() => remove(d)} className="text-ink-secondary hover:text-danger-fg">
                                <Trash2 className="h-3.5 w-3.5" strokeWidth={1.75} />
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            <form onSubmit={addDeliverable} className="flex gap-2">
                <input
                    value={description}
                    onChange={(e) => setDescription(e.target.value)}
                    placeholder="e.g. Logo on the closing plenary slide"
                    className="w-full border border-border bg-surface px-2.5 py-1.5 text-[13px] text-ink focus:border-accent focus:outline-none"
                />
                <Button type="submit" disabled={saving}>Add</Button>
            </form>
        </div>
    );
}

function SponsorCard({ event, sponsor, onChange }) {
    const [open, setOpen] = useState(false);
    const done = sponsor.deliverables.filter((d) => d.is_done).length;
    const total = sponsor.deliverables.length;

    const remove = async () => {
        await csrfFetch(route('tenant.events.sponsors.destroy', { event: event.id, sponsor: sponsor.id }), { method: 'DELETE' });
        onChange();
    };

    return (
        <div className="border border-border">
            <div className="flex items-center gap-3 p-3">
                {sponsor.logo_url ? (
                    <img src={sponsor.logo_url} alt={sponsor.name} className="h-10 w-10 shrink-0 object-contain" />
                ) : (
                    <div className="grid h-10 w-10 shrink-0 place-items-center bg-surface-sunken text-xs font-medium text-ink-secondary">
                        {sponsor.name.slice(0, 2).toUpperCase()}
                    </div>
                )}
                <div className="min-w-0 flex-1">
                    <div className="truncate text-[13.5px] text-ink">{sponsor.name}</div>
                    <div className="text-xs text-ink-secondary">{sponsor.booth ? `Booth ${sponsor.booth}` : 'No booth assigned'}</div>
                    {total > 0 && (
                        <div className="mt-1.5 h-1 w-full bg-surface-sunken">
                            <div className="h-full bg-accent" style={{ width: `${(done / total) * 100}%` }} />
                        </div>
                    )}
                </div>
                <button onClick={() => setOpen(!open)} className="text-ink-secondary hover:text-ink" title="Deliverables">
                    {open ? <ChevronUp className="h-4 w-4" strokeWidth={1.75} /> : <ChevronDown className="h-4 w-4" strokeWidth={1.75} />}
                </button>
                <button onClick={remove} className="text-ink-secondary hover:text-danger-fg" title="Remove sponsor">
                    <Trash2 className="h-4 w-4" strokeWidth={1.75} />
                </button>
            </div>
            {open && <DeliverableList event={event} sponsor={sponsor} onChange={onChange} />}
        </div>
    );
}

function NewSponsorForm({ event, onChange }) {
    const [form, setForm] = useState({ name: '', tier: 'supporting', booth: '', contact_name: '', contact_email: '', amount: '', logo: null });
    const [saving, setSaving] = useState(false);

    const submit = async (e) => {
        e.preventDefault();
        setSaving(true);

        const body = new FormData();
        body.append('name', form.name);
        body.append('tier', form.tier);
        if (form.booth) body.append('booth', form.booth);
        if (form.contact_name) body.append('contact_name', form.contact_name);
        if (form.contact_email) body.append('contact_email', form.contact_email);
        if (form.amount) body.append('amount', Math.round(Number(form.amount) * 100));
        if (form.logo) body.append('logo', form.logo);

        await csrfFetchFormData(route('tenant.events.sponsors.store', { event: event.id }), body);

        setSaving(false);
        setForm({ name: '', tier: 'supporting', booth: '', contact_name: '', contact_email: '', amount: '', logo: null });
        onChange();
    };

    return (
        <form onSubmit={submit} className="border border-border p-4">
            <b className="text-sm font-medium text-ink">Add sponsor</b>
            <div className="mt-3.5 grid grid-cols-3 gap-3.5">
                <Input label="Name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required />
                <Select label="Tier" value={form.tier} onChange={(e) => setForm({ ...form, tier: e.target.value })}>
                    {TIERS.map((t) => (
                        <option key={t} value={t}>{TIER_LABEL[t]}</option>
                    ))}
                </Select>
                <Input label="Booth" value={form.booth} onChange={(e) => setForm({ ...form, booth: e.target.value })} />
                <Input label="Contact name" value={form.contact_name} onChange={(e) => setForm({ ...form, contact_name: e.target.value })} />
                <Input label="Contact email" type="email" value={form.contact_email} onChange={(e) => setForm({ ...form, contact_email: e.target.value })} />
                <Input label="Sponsorship amount" type="number" min="0" step="0.01" value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} />
            </div>
            <div className="mt-3.5">
                <label className="mb-1.5 block text-sm font-medium text-ink">Logo</label>
                <input type="file" accept="image/*" onChange={(e) => setForm({ ...form, logo: e.target.files[0] ?? null })} className="text-sm text-ink-secondary" />
            </div>
            <Button type="submit" icon={Plus} variant="primary" className="mt-3.5" disabled={saving || !form.name}>
                {saving ? 'Adding…' : 'Add sponsor'}
            </Button>
        </form>
    );
}

export default function SponsorsPanel({ event }) {
    const [sponsors, setSponsors] = useState(null);

    const load = () => {
        csrfFetch(route('tenant.events.sponsors.index', { event: event.id }))
            .then((r) => r.json())
            .then(setSponsors);
    };

    useEffect(load, [event.id]);

    if (!sponsors) {
        return <p className="text-sm text-ink-secondary">Loading…</p>;
    }

    const income = sponsors.reduce((sum, s) => sum + s.amount, 0);
    const allDeliverables = sponsors.flatMap((s) => s.deliverables);
    const doneCount = allDeliverables.filter((d) => d.is_done).length;

    return (
        <div className="max-w-4xl">
            <div className="mb-5 flex items-center justify-between">
                <p className="text-sm text-ink-secondary">Who is paying, what you promised them, and what is still outstanding.</p>
                <a
                    href={route('tenant.events.sponsors.export', { event: event.id })}
                    className="inline-flex h-control items-center gap-2 border border-border px-4 text-sm text-ink hover:border-accent"
                >
                    <Download className="h-4 w-4" strokeWidth={1.75} />
                    Export deliverables
                </a>
            </div>

            <div className="mb-6 grid grid-cols-3 gap-px bg-border">
                <div className="bg-surface p-4">
                    <div className="text-xs text-ink-secondary">Sponsors</div>
                    <div className="mt-1 text-xl font-semibold text-ink">{sponsors.length}</div>
                </div>
                <div className="bg-surface p-4">
                    <div className="text-xs text-ink-secondary">Sponsorship income</div>
                    <div className="mt-1 text-xl font-semibold text-accent">{formatMoney(income, event.currency)}</div>
                </div>
                <div className="bg-surface p-4">
                    <div className="text-xs text-ink-secondary">Promises kept</div>
                    <div className="mt-1 text-xl font-semibold text-ink">{doneCount} of {allDeliverables.length}</div>
                </div>
            </div>

            {TIERS.map((tier) => {
                const tierSponsors = sponsors.filter((s) => s.tier === tier);
                if (tierSponsors.length === 0) return null;
                return (
                    <div key={tier} className="mb-5">
                        <b className="mb-2 block text-sm font-medium text-ink">{TIER_LABEL[tier]}</b>
                        <div className="grid gap-3 sm:grid-cols-2">
                            {tierSponsors.map((s) => (
                                <SponsorCard key={s.id} event={event} sponsor={s} onChange={load} />
                            ))}
                        </div>
                    </div>
                );
            })}

            {sponsors.length === 0 && <p className="mb-5 text-sm text-ink-secondary">No sponsors yet.</p>}

            <NewSponsorForm event={event} onChange={load} />
        </div>
    );
}
