import { useEffect, useRef, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import PageHeader from '@/Components/Console/PageHeader';
import Input from '@/Components/Console/Input';
import Select from '@/Components/Console/Select';
import Button from '@/Components/Console/Button';
import ConfirmModal from '@/Components/Console/ConfirmModal';
import CatalogVariants, { catalogFormValues } from '@/Components/Marketplace/CatalogVariants';
import CatalogPhotos from '@/Components/Marketplace/CatalogPhotos';
import CatalogStock from '@/Components/Marketplace/CatalogStock';
import { sendJson, toMinorUnits } from '@/lib/dues';

export default function CatalogForm({ item = null }) {
    const [saved, setSaved] = useState(item);
    const [values, setValues] = useState(() => catalogFormValues(item));
    const [errors, setErrors] = useState({});
    const [message, setMessage] = useState('');
    const [busy, setBusy] = useState(false);
    const [archiveOpen, setArchiveOpen] = useState(false);
    const form = useRef(null);
    const alert = useRef(null);
    const set = (field, value) => setValues((current) => ({ ...current, [field]: value }));
    useEffect(() => {
        if (Object.keys(errors).length)
            (form.current?.querySelector('[aria-invalid="true"]') ?? alert.current)?.focus();
    }, [errors]);
    const save = async (event) => {
        event.preventDefault();
        setErrors({});
        setMessage('');
        const invalid = {};
        const variants = values.variants.map((variant, index) => {
            const price = toMinorUnits(variant.price);
            if (!Number.isSafeInteger(price) || price < 0)
                invalid[`variants.${index}.price_pesewas`] =
                    'Enter a non-negative price with up to two decimal places.';
            const names = variant.options.map((option) =>
                option.name.trim().replace(/\s+/gu, ' ').toLowerCase()
            );
            if (new Set(names).size !== names.length)
                invalid.variants = 'Use distinct option names within a variant.';
            return {
                ...(variant.id
                    ? { id: variant.id }
                    : {
                          initial_stock: Number(variant.initial_stock),
                          idempotency_key: variant.idempotency_key,
                      }),
                sku: variant.sku.trim(),
                options: Object.fromEntries(
                    variant.options.map((option) => [option.name, option.value])
                ),
                price_pesewas: price,
                is_active: variant.is_active,
            };
        });
        if (Object.keys(invalid).length) {
            setErrors(invalid);
            return;
        }
        setBusy(true);
        const result = await sendJson(
            saved ? 'PUT' : 'POST',
            saved
                ? route('tenant.store.catalog.update', { listing: saved.id })
                : route('tenant.store.catalog.store'),
            { ...values, variants }
        );
        setBusy(false);
        if (!result.ok) {
            setErrors(Object.keys(result.errors).length ? result.errors : { form: result.message });
            return;
        }
        setSaved(result.body.listing);
        setValues(catalogFormValues(result.body.listing));
        setMessage('Changes saved.');
        if (!saved)
            router.visit(route('tenant.store.catalog.show', { listing: result.body.listing.id }), {
                replace: true,
                preserveScroll: true,
            });
    };
    const transition = async (action) => {
        setBusy(true);
        setErrors({});
        setMessage('');
        const result = await sendJson(
            'POST',
            route(`tenant.store.catalog.${action}`, { listing: saved.id })
        );
        setBusy(false);
        setArchiveOpen(false);
        if (!result.ok) {
            setErrors(Object.keys(result.errors).length ? result.errors : { form: result.message });
            return;
        }
        setSaved(result.body.listing);
        setMessage(
            action === 'publish'
                ? 'Item published.'
                : action === 'archive'
                  ? 'Item archived. Stock and history are retained.'
                  : 'Item moved to draft.'
        );
    };
    const dirty = saved && JSON.stringify(values) !== JSON.stringify(catalogFormValues(saved));
    return (
        <ConsoleLayout>
            <Head title={saved ? 'Edit merchandise' : 'New merchandise'} />
            <PageHeader
                title={saved ? 'Edit merchandise' : 'New merchandise'}
                actions={
                    <Link
                        href={route('tenant.store.catalog.index')}
                        className="text-sm text-accent underline"
                    >
                        Back to catalog
                    </Link>
                }
            />
            <div className="mx-auto w-full max-w-6xl space-y-6 p-5 sm:p-8">
                <p className="border-l-2 border-accent bg-accent/5 p-4 text-sm leading-6 text-ink-secondary">
                    Catalog preview · Purchasing is not enabled yet. Publish products and organise
                    stock without taking payments.
                </p>
                {saved && (
                    <section className="flex flex-wrap items-center justify-between gap-4 border border-border bg-surface p-5">
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-wider text-ink-secondary">
                                Current visibility
                            </p>
                            <p className="mt-1 font-semibold text-ink">
                                {saved.taken_down
                                    ? 'Taken down by platform'
                                    : saved.archived
                                      ? 'Archived'
                                      : saved.status === 'published'
                                        ? saved.visibility === 'members'
                                            ? 'Published · Members'
                                            : 'Published · Public'
                                        : 'Draft'}
                            </p>
                            {dirty && (
                                <p className="mt-2 text-xs text-ink-secondary">
                                    Save your changes before publishing or changing visibility.
                                </p>
                            )}
                        </div>
                        <div className="flex flex-wrap gap-3">
                            <a
                                href={route('marketplace.items.show', { slug: saved.slug })}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="self-center text-sm text-accent underline"
                            >
                                Preview item
                            </a>
                            {saved.archived || saved.status === 'published' ? (
                                <Button
                                    type="button"
                                    className="rounded-none"
                                    disabled={busy || dirty}
                                    onClick={() => transition('draft')}
                                >
                                    {saved.archived ? 'Restore as draft' : 'Move to draft'}
                                </Button>
                            ) : (
                                <Button
                                    type="button"
                                    variant="primary"
                                    className="rounded-none"
                                    disabled={busy || dirty || saved.taken_down}
                                    onClick={() => transition('publish')}
                                >
                                    Publish item
                                </Button>
                            )}
                            {!saved.archived && (
                                <Button
                                    type="button"
                                    className="rounded-none"
                                    disabled={busy || dirty}
                                    onClick={() => setArchiveOpen(true)}
                                >
                                    Archive
                                </Button>
                            )}
                        </div>
                    </section>
                )}
                {Object.keys(errors).length > 0 && (
                    <div
                        role="alert"
                        ref={alert}
                        tabIndex={-1}
                        className="border-l-2 border-danger-fg bg-danger-bg p-4 text-sm text-danger-fg"
                    >
                        <p className="font-semibold">Please check the item</p>
                        <ul className="mt-2 list-inside list-disc">
                            {Object.entries(errors).map(([key, error]) => (
                                <li key={key}>{error}</li>
                            ))}
                        </ul>
                    </div>
                )}
                {message && (
                    <p
                        role="status"
                        className="border-l-2 border-success-fg bg-success-bg p-4 text-sm text-success-fg"
                    >
                        {message}
                    </p>
                )}
                <form ref={form} onSubmit={save} className="space-y-6">
                    <fieldset disabled={busy} className="min-w-0 space-y-6">
                        <section className="space-y-5 border border-border bg-surface p-5 sm:p-6">
                            <div>
                                <h2 className="text-xl font-semibold text-ink">Item details</h2>
                                <p className="mt-2 text-sm text-ink-secondary">
                                    A clear name, useful description and straightforward collection
                                    instructions.
                                </p>
                            </div>
                            <div className="grid gap-5 md:grid-cols-2">
                                <Input
                                    label="Item name"
                                    name="title"
                                    required
                                    value={values.title}
                                    onChange={(event) => set('title', event.target.value)}
                                    error={errors.title}
                                    className="rounded-none bg-canvas"
                                />
                                <Input
                                    label="Item address"
                                    name="slug"
                                    required
                                    pattern="[a-z0-9]+(-[a-z0-9]+)*"
                                    value={values.slug}
                                    onChange={(event) => set('slug', event.target.value)}
                                    error={errors.slug}
                                    hint="Lowercase letters, numbers and hyphens."
                                    className="rounded-none bg-canvas"
                                />
                            </div>
                            <label className="block text-sm font-medium text-ink">
                                Description
                                <textarea
                                    name="description"
                                    rows={4}
                                    maxLength={20000}
                                    value={values.description}
                                    onChange={(event) => set('description', event.target.value)}
                                    className="mt-2 block w-full border border-border bg-canvas p-3 text-sm text-ink focus:border-accent focus:outline-none"
                                />
                            </label>
                            <Select
                                label="Who can view this item?"
                                name="visibility"
                                value={values.visibility}
                                onChange={(event) => set('visibility', event.target.value)}
                                error={errors.visibility}
                                className="rounded-none bg-canvas"
                            >
                                <option value="public">Anyone · Public</option>
                                <option value="members">Verified active members</option>
                            </Select>
                            <label className="block text-sm font-medium text-ink">
                                Collection instructions
                                <textarea
                                    name="collection_instructions"
                                    rows={3}
                                    maxLength={2000}
                                    value={values.collection_instructions}
                                    onChange={(event) =>
                                        set('collection_instructions', event.target.value)
                                    }
                                    className="mt-2 block w-full border border-border bg-canvas p-3 text-sm text-ink focus:border-accent focus:outline-none"
                                />
                                <span className="mt-2 block text-xs font-normal text-ink-secondary">
                                    Required before publishing. Include the collection location,
                                    opening times and who to contact.
                                </span>
                            </label>
                        </section>
                        <CatalogVariants
                            variants={values.variants}
                            onChange={(variants) => set('variants', variants)}
                            errors={errors}
                        />
                        <div className="flex flex-wrap items-center gap-4">
                            <Button
                                type="submit"
                                variant="primary"
                                className="rounded-none"
                                disabled={busy}
                            >
                                {busy ? 'Saving…' : saved ? 'Save changes' : 'Save draft'}
                            </Button>
                            {!saved && (
                                <p className="text-xs text-ink-secondary">
                                    Save a draft to upload photos and manage inventory.
                                </p>
                            )}
                        </div>
                    </fieldset>
                </form>
                {saved && (
                    <>
                        <CatalogPhotos item={saved} onChange={setSaved} />
                        <CatalogStock item={saved} onChange={setSaved} />
                    </>
                )}
            </div>
            <ConfirmModal
                open={archiveOpen}
                onClose={() => setArchiveOpen(false)}
                onConfirm={() => transition('archive')}
                processing={busy}
                title="Archive this item?"
                description="It will disappear from the storefront. Stock and history are retained, and you can restore it as a draft."
                confirmLabel="Archive item"
            />
        </ConsoleLayout>
    );
}
