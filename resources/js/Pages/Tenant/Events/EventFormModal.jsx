import { useState, useRef, useEffect } from 'react';
import { useForm } from '@inertiajs/react';
import { AlertCircle, ArrowUp, Repeat, Building2 } from 'lucide-react';
import Modal from '@/Components/Console/Modal';
import Button from '@/Components/Console/Button';
import Input from '@/Components/Console/Input';
import Select from '@/Components/Console/Select';

const TITLES = {
    create: 'New event',
    edit: 'Edit event',
};

const MAX_HERO_SIZE_BYTES = 20 * 1024 * 1024; // 20 MB

function toDateTimeLocal(iso) {
    if (!iso) return '';
    const date = new Date(iso);
    const pad = (n) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

export default function EventFormModal({ mode, event, onClose }) {
    const formRef = useRef(null);
    const [fileError, setFileError] = useState(null);
    const [previewUrl, setPreviewUrl] = useState(null);

    const { data, setData, transform, post, put, processing, errors } = useForm({
        name: event?.name ?? '',
        event_category: event?.event_category ?? 'general',
        description: event?.description ?? '',
        status: event?.status ?? 'draft',
        starts_at: toDateTimeLocal(event?.starts_at) ?? '',
        ends_at: toDateTimeLocal(event?.ends_at) ?? '',
        timezone: event?.timezone ?? 'Africa/Accra',
        location_type: event?.location_type ?? 'in_person',
        address: event?.address ?? '',
        virtual_link: event?.virtual_link ?? '',
        contact_email: event?.contact_email ?? '',
        capacity: event?.capacity ?? '',
        requires_approval: event?.requires_approval ?? false,
        ticket_price: event ? event.ticket_price / 100 : 0,
        currency: event?.currency ?? 'GHS',
        fee_bearer: event?.fee_bearer ?? 'organizer',
        plan_your_visit_content: event?.plan_your_visit_content ?? '',
        hero_image: null,
        is_recurring: event?.is_recurring ?? false,
        recurrence_pattern: event?.recurrence_pattern ?? 'weekly',
        recurrence_days: event?.recurrence_days ?? ['sunday'],
        recurrence_time_start: event?.recurrence_time_start ?? '09:00',
        recurrence_time_end: event?.recurrence_time_end ?? '11:00',
        recurrence_interval: event?.recurrence_interval ?? 1,
        recurrence_until: event?.recurrence_until ?? '',
        recurrence_auto_generate_weeks: event?.recurrence_auto_generate_weeks ?? 4,
        allow_offline_payments: event?.allow_offline_payments ?? false,
        offline_payment_instructions: event?.offline_payment_instructions ?? '',
        offline_payment_bank_name: event?.offline_payment_bank_name ?? '',
        offline_payment_account_name: event?.offline_payment_account_name ?? '',
        offline_payment_account_number: event?.offline_payment_account_number ?? '',
        offline_payment_momo_number: event?.offline_payment_momo_number ?? '',
        offline_payment_momo_network: event?.offline_payment_momo_network ?? '',
    });

    const activeErrors = {
        ...(fileError ? { hero_image: fileError } : {}),
        ...errors,
    };
    const errorCount = Object.keys(activeErrors).length;

    const scrollToFirstError = () => {
        setTimeout(() => {
            const firstErrorEl = formRef.current?.querySelector(
                '.text-danger-fg, [aria-invalid="true"]'
            );
            if (firstErrorEl) {
                firstErrorEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
            } else if (formRef.current) {
                formRef.current.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }, 50);
    };

    useEffect(() => {
        if (errorCount > 0) {
            scrollToFirstError();
        }
    }, [errors, fileError]);

    const handleFileChange = (e) => {
        const file = e.target.files?.[0] ?? null;
        if (file) {
            if (file.size > MAX_HERO_SIZE_BYTES) {
                const sizeMb = (file.size / (1024 * 1024)).toFixed(1);
                setFileError(
                    `The selected file "${file.name}" is ${sizeMb}MB, which exceeds the 20MB limit. Please choose a smaller image.`
                );
                setData('hero_image', null);
                setPreviewUrl(null);
                e.target.value = '';
                return;
            }
            setFileError(null);
            setData('hero_image', file);
            setPreviewUrl(URL.createObjectURL(file));
        } else {
            setFileError(null);
            setData('hero_image', null);
            setPreviewUrl(null);
        }
    };

    const submit = (submitEvent) => {
        submitEvent.preventDefault();

        if (fileError) {
            scrollToFirstError();
            return;
        }

        transform((formData) => ({
            ...formData,
            capacity: formData.capacity === '' ? null : formData.capacity,
            requires_approval: formData.requires_approval ? '1' : '0',
            allow_offline_payments: formData.allow_offline_payments ? '1' : '0',
            ticket_price: Math.round(Number(formData.ticket_price) * 100),
        }));

        const options = {
            forceFormData: true,
            onSuccess: onClose,
            onError: () => scrollToFirstError(),
        };

        if (mode === 'create') {
            post(route('tenant.events.store'), options);
        } else {
            put(route('tenant.events.update', { event: event.id }), options);
        }
    };

    return (
        <Modal open onClose={onClose} title={TITLES[mode]} className="max-w-2xl">
            <form ref={formRef} onSubmit={submit} className="space-y-5">
                {errorCount > 0 && (
                    <div className="flex items-start gap-3 rounded-lg border border-danger-border bg-danger-surface p-3.5 text-danger-fg">
                        <AlertCircle className="mt-0.5 h-5 w-5 shrink-0" strokeWidth={1.9} />
                        <div className="flex-1 text-sm">
                            <div className="font-semibold">
                                Please fix the following error{errorCount > 1 ? 's' : ''}:
                            </div>
                            <ul className="mt-1 list-disc list-inside space-y-0.5 text-xs text-danger-fg">
                                {Object.entries(activeErrors).map(([key, msg]) => (
                                    <li key={key}>{msg}</li>
                                ))}
                            </ul>
                        </div>
                    </div>
                )}

                <Input
                    label="Event name"
                    type="text"
                    value={data.name}
                    onChange={(e) => setData('name', e.target.value)}
                    error={errors.name}
                />

                <div>
                    <Select
                        label="Event archetype"
                        value={data.event_category}
                        onChange={(e) => setData('event_category', e.target.value)}
                        error={errors.event_category}
                    >
                        <option value="general">🌐 General / Community & Meetup</option>
                        <option value="faith">⛪ Church / Faith & Religious Gathering</option>
                        <option value="memorial">
                            🕊️ Funeral / Memorial & Celebration of Life
                        </option>
                        <option value="academic">🎓 Academic / Course, Lecture & Seminar</option>
                        <option value="conference">💼 Conference / Summit & Corporate</option>
                        <option value="fundraiser">🎗️ Nonprofit / Charity & Fundraiser</option>
                    </Select>
                    <p className="mt-1 text-xs text-ink-secondary">
                        MiConvener automatically tailors giving terms, schedules, and occurrence
                        notes to match this event archetype.
                    </p>
                </div>

                <div>
                    <div className="flex items-center justify-between">
                        <label className="mb-1.5 block text-sm font-medium text-ink">
                            Hero image
                        </label>
                        <span className="text-xs text-ink-secondary">Max 20MB</span>
                    </div>
                    <input
                        type="file"
                        accept="image/png,image/jpeg,image/webp,image/svg+xml,image/gif"
                        onChange={handleFileChange}
                        className="block w-full text-sm text-ink-secondary file:mr-3 file:rounded-md file:border file:border-border file:bg-surface file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-ink"
                    />
                    <p className="mt-1 text-xs text-ink-secondary">
                        Supported formats: PNG, JPG, WebP, SVG. Recommended ratio: 16:9 (e.g.
                        1920×1080).
                    </p>
                    {previewUrl ? (
                        <div className="mt-2">
                            <img
                                src={previewUrl}
                                alt="Preview"
                                className="h-28 w-full rounded-md object-cover border border-border"
                            />
                        </div>
                    ) : event?.hero_image_url && !data.hero_image ? (
                        <div className="mt-2">
                            <img
                                src={event.hero_image_url}
                                alt="Current hero"
                                className="h-28 w-full rounded-md object-cover border border-border"
                            />
                        </div>
                    ) : null}
                    {(fileError || errors.hero_image) && (
                        <p className="mt-1.5 flex items-center gap-1.5 text-sm font-medium text-danger-fg">
                            <AlertCircle className="h-4 w-4 shrink-0" />
                            <span>{fileError || errors.hero_image}</span>
                        </p>
                    )}
                </div>

                <div>
                    <label className="mb-1.5 block text-sm font-medium text-ink">Description</label>
                    <textarea
                        value={data.description}
                        onChange={(e) => setData('description', e.target.value)}
                        rows={3}
                        className="w-full rounded-lg border border-border px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                    />
                    {errors.description && (
                        <p className="mt-1 text-sm text-danger-fg">{errors.description}</p>
                    )}
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

                <div className="rounded-xl border border-border/80 bg-surface-muted/40 p-4 space-y-3.5">
                    <label className="flex items-start gap-3 cursor-pointer">
                        <input
                            type="checkbox"
                            checked={data.is_recurring}
                            onChange={(e) => setData('is_recurring', e.target.checked)}
                            className="mt-0.5 h-4 w-4 rounded border-border text-accent focus:ring-accent"
                        />
                        <div>
                            <span className="text-sm font-semibold text-ink flex items-center gap-1.5">
                                <Repeat className="h-4 w-4 text-accent" />
                                Recurring Event Series
                            </span>
                            <p className="text-xs text-ink-secondary mt-0.5">
                                For church services, recurring lectures, prayer vigils, Bible
                                studies, or regular gatherings. MiConvener automatically generates
                                upcoming session occurrences.
                            </p>
                        </div>
                    </label>

                    {data.is_recurring && (
                        <div className="pt-2 border-t border-border/60 space-y-3.5">
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                                <Select
                                    label="Recurrence pattern"
                                    value={data.recurrence_pattern}
                                    onChange={(e) => setData('recurrence_pattern', e.target.value)}
                                    error={errors.recurrence_pattern}
                                >
                                    <option value="weekly">Weekly</option>
                                    <option value="biweekly">Every 2 weeks (Bi-weekly)</option>
                                    <option value="custom_days">Custom days of the week</option>
                                    <option value="monthly">Monthly</option>
                                    <option value="daily">Daily</option>
                                </Select>

                                <div>
                                    <label className="mb-1.5 block text-sm font-medium text-ink">
                                        Auto-generate horizon
                                    </label>
                                    <select
                                        value={data.recurrence_auto_generate_weeks}
                                        onChange={(e) =>
                                            setData(
                                                'recurrence_auto_generate_weeks',
                                                parseInt(e.target.value, 10)
                                            )
                                        }
                                        className="h-control w-full rounded-md border border-border bg-surface px-3 text-sm text-ink focus:border-accent focus:outline-none"
                                    >
                                        <option value={2}>Next 2 weeks</option>
                                        <option value={4}>Next 4 weeks (1 month)</option>
                                        <option value={8}>Next 8 weeks (2 months)</option>
                                        <option value={12}>Next 12 weeks (3 months)</option>
                                    </select>
                                </div>
                            </div>

                            {(data.recurrence_pattern === 'weekly' ||
                                data.recurrence_pattern === 'biweekly' ||
                                data.recurrence_pattern === 'custom_days') && (
                                <div>
                                    <label className="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-ink-secondary">
                                        Days of the week
                                    </label>
                                    <div className="flex flex-wrap gap-1.5">
                                        {[
                                            'sunday',
                                            'monday',
                                            'tuesday',
                                            'wednesday',
                                            'thursday',
                                            'friday',
                                            'saturday',
                                        ].map((day) => {
                                            const isSelected = (
                                                data.recurrence_days || []
                                            ).includes(day);
                                            return (
                                                <button
                                                    key={day}
                                                    type="button"
                                                    onClick={() => {
                                                        const current = data.recurrence_days || [];
                                                        const next = isSelected
                                                            ? current.filter((d) => d !== day)
                                                            : [...current, day];
                                                        setData('recurrence_days', next);
                                                    }}
                                                    className={`px-3 py-1.5 text-xs font-medium rounded-lg border transition-colors capitalize ${
                                                        isSelected
                                                            ? 'bg-accent text-white border-accent shadow-xs'
                                                            : 'bg-surface border-border text-ink-secondary hover:text-ink hover:border-ink-secondary'
                                                    }`}
                                                >
                                                    {day.slice(0, 3)}
                                                </button>
                                            );
                                        })}
                                    </div>
                                    {errors.recurrence_days && (
                                        <p className="mt-1 text-xs text-danger-fg">
                                            {errors.recurrence_days}
                                        </p>
                                    )}
                                </div>
                            )}

                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                                <Input
                                    label="Occurrence start time"
                                    type="time"
                                    value={data.recurrence_time_start}
                                    onChange={(e) =>
                                        setData('recurrence_time_start', e.target.value)
                                    }
                                    error={errors.recurrence_time_start}
                                />
                                <Input
                                    label="Occurrence end time"
                                    type="time"
                                    value={data.recurrence_time_end}
                                    onChange={(e) => setData('recurrence_time_end', e.target.value)}
                                    error={errors.recurrence_time_end}
                                />
                            </div>

                            <Input
                                label="Recurrence end date (optional)"
                                type="date"
                                value={data.recurrence_until}
                                onChange={(e) => setData('recurrence_until', e.target.value)}
                                error={errors.recurrence_until}
                            />
                        </div>
                    )}
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

                <Input
                    label="Contact email"
                    type="email"
                    placeholder="Your organization email"
                    value={data.contact_email}
                    onChange={(e) => setData('contact_email', e.target.value)}
                    error={errors.contact_email}
                    hint="Where replies to this event's emails go. Leave blank to use your organization email."
                />

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
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
                    Default price is used only if this event has no ticket types. Add ticket types
                    (e.g. In-Person / Virtual, each with its own price) from the event page after
                    creating it.
                </p>

                <Select
                    label="Who pays the platform fee"
                    value={data.fee_bearer}
                    onChange={(e) => setData('fee_bearer', e.target.value)}
                    error={errors.fee_bearer}
                >
                    <option value="organizer">I do — it comes out of the ticket price</option>
                    <option value="attendee">The attendee — it is added at checkout</option>
                </Select>
                <p className="-mt-3 text-xs text-ink-secondary">
                    Choose &ldquo;the attendee&rdquo; and the platform fee is added to what the
                    buyer pays instead of coming out of your ticket price. Payment processing is
                    deducted from your payout either way.
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

                <div className="rounded-xl border border-border/80 bg-surface-muted/40 p-4 space-y-3.5">
                    <label className="flex items-start gap-3 cursor-pointer">
                        <input
                            type="checkbox"
                            checked={data.allow_offline_payments}
                            onChange={(e) => setData('allow_offline_payments', e.target.checked)}
                            className="mt-0.5 h-4 w-4 rounded border-border text-accent focus:ring-accent"
                        />
                        <div>
                            <span className="text-sm font-semibold text-ink flex items-center gap-1.5">
                                <Building2 className="h-4 w-4 text-accent" />
                                Accept Offline & Direct Payments
                            </span>
                            <p className="text-xs text-ink-secondary mt-0.5">
                                Allow attendees to pay via bank transfer, MoMo merchant line, or
                                cash on site and upload proof of payment for your manual
                                verification.
                            </p>
                        </div>
                    </label>

                    {data.allow_offline_payments && (
                        <div className="pt-2 border-t border-border/60 space-y-3.5">
                            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                <Input
                                    label="Bank name"
                                    type="text"
                                    placeholder="e.g. Ecobank Ghana"
                                    value={data.offline_payment_bank_name}
                                    onChange={(e) =>
                                        setData('offline_payment_bank_name', e.target.value)
                                    }
                                    error={errors.offline_payment_bank_name}
                                />
                                <Input
                                    label="Account name"
                                    type="text"
                                    placeholder="e.g. Acme Organization Ltd"
                                    value={data.offline_payment_account_name}
                                    onChange={(e) =>
                                        setData('offline_payment_account_name', e.target.value)
                                    }
                                    error={errors.offline_payment_account_name}
                                />
                                <Input
                                    label="Account number"
                                    type="text"
                                    placeholder="e.g. 1441001234567"
                                    value={data.offline_payment_account_number}
                                    onChange={(e) =>
                                        setData('offline_payment_account_number', e.target.value)
                                    }
                                    error={errors.offline_payment_account_number}
                                />
                            </div>

                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <Select
                                    label="MoMo network"
                                    value={data.offline_payment_momo_network}
                                    onChange={(e) =>
                                        setData('offline_payment_momo_network', e.target.value)
                                    }
                                    error={errors.offline_payment_momo_network}
                                >
                                    <option value="">Select network (optional)</option>
                                    <option value="MTN MoMo">MTN MoMo</option>
                                    <option value="Telecel Cash">Telecel Cash</option>
                                    <option value="AT Money">AT Money</option>
                                </Select>

                                <Input
                                    label="MoMo merchant / phone number"
                                    type="text"
                                    placeholder="e.g. 0244123456 or Merchant ID"
                                    value={data.offline_payment_momo_number}
                                    onChange={(e) =>
                                        setData('offline_payment_momo_number', e.target.value)
                                    }
                                    error={errors.offline_payment_momo_number}
                                />
                            </div>

                            <div>
                                <label className="mb-1.5 block text-sm font-medium text-ink">
                                    Offline payment instructions & terms (optional)
                                </label>
                                <textarea
                                    value={data.offline_payment_instructions}
                                    onChange={(e) =>
                                        setData('offline_payment_instructions', e.target.value)
                                    }
                                    rows={2}
                                    placeholder="e.g. Use your registration email or reference code in the transfer memo. Tickets are confirmed within 24 hours."
                                    className="w-full rounded-lg border border-border px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                                />
                                {errors.offline_payment_instructions && (
                                    <p className="mt-1 text-sm text-danger-fg">
                                        {errors.offline_payment_instructions}
                                    </p>
                                )}
                            </div>
                        </div>
                    )}
                </div>

                <div>
                    <label className="mb-1.5 block text-sm font-medium text-ink">
                        Plan your visit (optional)
                    </label>
                    <textarea
                        value={data.plan_your_visit_content}
                        onChange={(e) => setData('plan_your_visit_content', e.target.value)}
                        rows={3}
                        placeholder="Venue directions, parking, visa letters, accommodation..."
                        className="w-full rounded-lg border border-border px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                    />
                    <p className="mt-1 text-xs text-ink-secondary">
                        Only shown on the public page if filled in.
                    </p>
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

                {errorCount > 0 && (
                    <div className="flex items-center justify-between gap-3 rounded-lg border border-danger-border bg-danger-surface p-3 text-sm text-danger-fg">
                        <div className="flex items-center gap-2">
                            <AlertCircle className="h-4 w-4 shrink-0" strokeWidth={1.9} />
                            <span>
                                {errorCount} error{errorCount > 1 ? 's' : ''} found above. Please
                                correct {errorCount > 1 ? 'them' : 'it'} before saving.
                            </span>
                        </div>
                        <button
                            type="button"
                            onClick={scrollToFirstError}
                            className="flex items-center gap-1 text-xs font-semibold underline hover:opacity-80"
                        >
                            <ArrowUp className="h-3.5 w-3.5" />
                            Scroll to error
                        </button>
                    </div>
                )}

                <Button type="submit" disabled={processing}>
                    {mode === 'create' ? 'Create event' : 'Save changes'}
                </Button>
            </form>
        </Modal>
    );
}
