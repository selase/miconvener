import { useForm } from '@inertiajs/react';
import Modal from '@/Components/Console/Modal';
import Button from '@/Components/Console/Button';
import Input from '@/Components/Console/Input';
import Select from '@/Components/Console/Select';

const TITLES = {
    create: 'New event',
    edit: 'Edit event',
};

function toDateTimeLocal(iso) {
    if (!iso) return '';
    const date = new Date(iso);
    const pad = (n) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

export default function EventFormModal({ mode, event, onClose }) {
    const { data, setData, transform, post, put, processing, errors } = useForm({
        name: event?.name ?? '',
        description: event?.description ?? '',
        status: event?.status ?? 'draft',
        starts_at: toDateTimeLocal(event?.starts_at) ?? '',
        ends_at: toDateTimeLocal(event?.ends_at) ?? '',
        timezone: event?.timezone ?? 'Africa/Accra',
        location_type: event?.location_type ?? 'in_person',
        address: event?.address ?? '',
        virtual_link: event?.virtual_link ?? '',
        capacity: event?.capacity ?? '',
        requires_approval: event?.requires_approval ?? false,
        ticket_price: event ? event.ticket_price / 100 : 0,
        currency: event?.currency ?? 'GHS',
        plan_your_visit_content: event?.plan_your_visit_content ?? '',
        hero_image: null,
    });

    const submit = (submitEvent) => {
        submitEvent.preventDefault();

        transform((formData) => ({
            ...formData,
            capacity: formData.capacity === '' ? null : formData.capacity,
            requires_approval: formData.requires_approval ? '1' : '0',
            ticket_price: Math.round(Number(formData.ticket_price) * 100),
        }));

        if (mode === 'create') {
            post(route('tenant.events.store'), { forceFormData: true, onSuccess: onClose });
        } else {
            put(route('tenant.events.update', { event: event.id }), { forceFormData: true, onSuccess: onClose });
        }
    };

    return (
        <Modal open onClose={onClose} title={TITLES[mode]} className="max-w-2xl">
            <form onSubmit={submit} className="space-y-5">
                <Input
                    label="Event name"
                    type="text"
                    value={data.name}
                    onChange={(e) => setData('name', e.target.value)}
                    error={errors.name}
                />

                <div>
                    <label className="mb-1.5 block text-sm font-medium text-ink">Hero image</label>
                    <input
                        type="file"
                        accept="image/*"
                        onChange={(e) => setData('hero_image', e.target.files[0] ?? null)}
                        className="block w-full text-sm text-ink-secondary file:mr-3 file:rounded-md file:border file:border-border file:bg-surface file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-ink"
                    />
                    {event?.hero_image_url && !data.hero_image && (
                        <img src={event.hero_image_url} alt="Current hero" className="mt-2 h-24 w-full rounded-md object-cover" />
                    )}
                    {errors.hero_image && <p className="mt-1 text-sm text-danger-fg">{errors.hero_image}</p>}
                </div>

                <div>
                    <label className="mb-1.5 block text-sm font-medium text-ink">Description</label>
                    <textarea
                        value={data.description}
                        onChange={(e) => setData('description', e.target.value)}
                        rows={3}
                        className="w-full rounded-lg border border-border px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                    />
                    {errors.description && <p className="mt-1 text-sm text-danger-fg">{errors.description}</p>}
                </div>

                <div className="grid grid-cols-2 gap-4">
                    <Input
                        label="Starts"
                        type="datetime-local"
                        value={data.starts_at}
                        onChange={(e) => setData('starts_at', e.target.value)}
                        error={errors.starts_at}
                    />
                    <Input
                        label="Ends"
                        type="datetime-local"
                        value={data.ends_at}
                        onChange={(e) => setData('ends_at', e.target.value)}
                        error={errors.ends_at}
                    />
                </div>

                <Select
                    label="Location type"
                    value={data.location_type}
                    onChange={(e) => setData('location_type', e.target.value)}
                    error={errors.location_type}
                >
                    <option value="in_person">In person</option>
                    <option value="virtual">Virtual</option>
                </Select>

                {data.location_type === 'in_person' ? (
                    <Input
                        label="Address"
                        type="text"
                        value={data.address}
                        onChange={(e) => setData('address', e.target.value)}
                        error={errors.address}
                    />
                ) : (
                    <Input
                        label="Virtual link"
                        type="text"
                        value={data.virtual_link}
                        onChange={(e) => setData('virtual_link', e.target.value)}
                        error={errors.virtual_link}
                    />
                )}

                <div className="grid grid-cols-3 gap-4">
                    <Input
                        label="Capacity"
                        type="number"
                        min="1"
                        placeholder="Unlimited"
                        value={data.capacity}
                        onChange={(e) => setData('capacity', e.target.value)}
                        error={errors.capacity}
                    />
                    <Input
                        label="Default price"
                        type="number"
                        min="0"
                        step="0.01"
                        value={data.ticket_price}
                        onChange={(e) => setData('ticket_price', e.target.value)}
                        error={errors.ticket_price}
                    />
                    <Input
                        label="Currency"
                        type="text"
                        maxLength={3}
                        value={data.currency}
                        onChange={(e) => setData('currency', e.target.value.toUpperCase())}
                        error={errors.currency}
                    />
                </div>
                <p className="-mt-3 text-xs text-ink-secondary">
                    Default price is used only if this event has no ticket types. Add ticket types (e.g. In-Person / Virtual, each with its own price) from the event page after creating it.
                </p>

                <label className="flex items-center gap-2.5 text-sm text-ink">
                    <input
                        type="checkbox"
                        checked={data.requires_approval}
                        onChange={(e) => setData('requires_approval', e.target.checked)}
                        className="h-4 w-4"
                    />
                    Registrations need my approval before they're confirmed
                </label>

                <div>
                    <label className="mb-1.5 block text-sm font-medium text-ink">Plan your visit (optional)</label>
                    <textarea
                        value={data.plan_your_visit_content}
                        onChange={(e) => setData('plan_your_visit_content', e.target.value)}
                        rows={3}
                        placeholder="Venue directions, parking, visa letters, accommodation..."
                        className="w-full rounded-lg border border-border px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                    />
                    <p className="mt-1 text-xs text-ink-secondary">Only shown on the public page if filled in.</p>
                </div>

                <Select
                    label="Status"
                    value={data.status}
                    onChange={(e) => setData('status', e.target.value)}
                    error={errors.status}
                >
                    <option value="draft">Draft</option>
                    <option value="published">Published</option>
                    <option value="cancelled">Cancelled</option>
                </Select>

                <Button type="submit" disabled={processing}>
                    {mode === 'create' ? 'Create event' : 'Save changes'}
                </Button>
            </form>
        </Modal>
    );
}
