import { Head, Link } from '@inertiajs/react';
import { ArrowUpRight, Package, Plus } from 'lucide-react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import PageHeader from '@/Components/Console/PageHeader';
import Button from '@/Components/Console/Button';
import StoreFulfilmentPolicyForm from '@/Components/Marketplace/StoreFulfilmentPolicyForm';

export default function CatalogIndex({
    items = { data: [], links: [] },
    shop = null,
    fulfilmentPolicy = null,
}) {
    return (
        <ConsoleLayout>
            <Head title="Store catalog" />
            <PageHeader
                title="Store catalog"
                actions={
                    <Button
                        href={route('tenant.store.catalog.create')}
                        icon={Plus}
                        variant="primary"
                        className="rounded-none"
                    >
                        Add merchandise
                    </Button>
                }
            />
            <div className="mx-auto w-full max-w-6xl space-y-6 p-5 sm:p-8">
                <div className="flex flex-wrap items-start justify-between gap-4 border-l-2 border-accent bg-accent/5 p-5">
                    <div>
                        <h2 className="font-semibold text-ink">
                            Make your organisation’s store your own
                        </h2>
                        <p className="mt-2 max-w-2xl text-sm leading-6 text-ink-secondary">
                            Publish merchandise, offer sizes and colours, and keep an audited stock
                            record. Checkout is available only after platform approval and
                            activation.
                        </p>
                    </div>
                    {shop && (
                        <a
                            href={route('marketplace.storefront.show', {
                                merchant_slug: shop.slug,
                            })}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="inline-flex items-center gap-2 text-sm text-accent underline"
                        >
                            View storefront
                            <ArrowUpRight className="h-4 w-4" />
                        </a>
                    )}
                </div>
                {shop && <StoreFulfilmentPolicyForm initial={fulfilmentPolicy} />}
                {items.data.length ? (
                    <div className="divide-y divide-border border border-border bg-surface">
                        {items.data.map((item) => (
                            <Link
                                key={item.id}
                                href={route('tenant.store.catalog.show', { listing: item.id })}
                                className="flex min-w-0 flex-wrap items-center justify-between gap-4 p-5 transition-colors hover:bg-surface-hover focus-visible:outline-2 focus-visible:outline-accent"
                            >
                                <div className="min-w-0 flex-1">
                                    <h2 className="break-words font-semibold text-ink">
                                        {item.title}
                                    </h2>
                                    <p className="mt-2 text-xs text-ink-secondary">
                                        {item.taken_down
                                            ? 'Taken down'
                                            : item.archived
                                              ? 'Archived'
                                              : item.status === 'published'
                                                ? 'Published'
                                                : 'Draft'}{' '}
                                        · {item.visibility === 'members' ? 'Members' : 'Public'} ·{' '}
                                        {item.variants.length}{' '}
                                        {item.variants.length === 1 ? 'variant' : 'variants'}
                                    </p>
                                </div>
                                <div className="flex shrink-0 items-center gap-5">
                                    <p className="text-sm text-ink-secondary">
                                        <strong className="font-semibold tabular-nums text-ink">
                                            {item.variants.reduce(
                                                (sum, variant) => sum + variant.on_hand,
                                                0
                                            )}
                                        </strong>{' '}
                                        on hand
                                    </p>
                                    <ArrowUpRight className="h-4 w-4 text-ink-secondary" />
                                </div>
                            </Link>
                        ))}
                    </div>
                ) : (
                    <section className="border border-dashed border-border bg-surface p-8 text-center sm:p-12">
                        <Package className="mx-auto h-12 w-12 text-ink-tertiary" strokeWidth={1} />
                        <h2 className="mt-5 text-xl font-semibold text-ink">
                            Your first item starts here
                        </h2>
                        <p className="mx-auto mt-3 max-w-md text-sm leading-6 text-ink-secondary">
                            Add a shirt, notebook or another physical item. A store does not need
                            dues or a Community membership programme.
                        </p>
                        <Button
                            href={route('tenant.store.catalog.create')}
                            className="mt-6 rounded-none"
                        >
                            Create your first item
                        </Button>
                    </section>
                )}
                {items.links?.length > 3 && (
                    <nav aria-label="Catalog pages" className="flex flex-wrap gap-2">
                        {items.links.map((link, index) =>
                            link.url ? (
                                <Link
                                    key={index}
                                    href={link.url}
                                    aria-current={link.active ? 'page' : undefined}
                                    className={`border px-3 py-2 text-sm ${link.active ? 'border-accent text-accent' : 'border-border text-ink-secondary'}`}
                                >
                                    {link.label.replace(/&laquo;|&raquo;/g, '').trim()}
                                </Link>
                            ) : null
                        )}
                    </nav>
                )}
            </div>
        </ConsoleLayout>
    );
}
