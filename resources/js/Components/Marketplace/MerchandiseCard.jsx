import { Link } from '@inertiajs/react';
import { ArrowUpRight, Package } from 'lucide-react';
import { formatDuesMoney } from '@/lib/dues';

export default function MerchandiseCard({ item }) {
    const prices = item.variants.map((variant) => variant.price_pesewas);
    const price = prices.length ? Math.min(...prices) : null;
    const available = item.variants.some((variant) => variant.available);
    return (
        <Link
            href={route('marketplace.items.show', { slug: item.slug })}
            className="group block min-w-0 border border-border bg-surface transition-colors hover:border-accent focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-accent"
        >
            <div className="relative flex aspect-[4/3] items-center justify-center overflow-hidden border-b border-border bg-canvas">
                {item.photos[0] ? (
                    <img
                        src={item.photos[0].url}
                        alt=""
                        className="h-full w-full object-contain p-4"
                        loading="lazy"
                    />
                ) : (
                    <Package
                        aria-label="No product photo"
                        className="h-12 w-12 text-ink-tertiary"
                        strokeWidth={1}
                    />
                )}
                {item.visibility === 'members' && (
                    <span className="absolute left-3 top-3 border border-border bg-surface px-2 py-1 text-xs font-medium text-ink">
                        Members
                    </span>
                )}
            </div>
            <div className="space-y-3 p-5">
                <div className="flex items-start justify-between gap-3">
                    <h3 className="min-w-0 break-words text-lg font-semibold text-ink group-hover:text-accent">
                        {item.title}
                    </h3>
                    <ArrowUpRight className="mt-1 h-4 w-4 shrink-0 text-ink-secondary" />
                </div>
                <p className="text-sm font-medium text-ink">
                    {price === null
                        ? 'No active options'
                        : `${prices.some((value) => value !== price) ? 'From ' : ''}${formatDuesMoney(price, item.currency)}`}
                </p>
                <p className={`text-xs ${available ? 'text-ink-secondary' : 'text-ink-tertiary'}`}>
                    {available ? 'View options & collection details' : 'Currently unavailable'}
                </p>
            </div>
        </Link>
    );
}
