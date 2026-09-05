import { useEffect, useMemo, useState } from 'react';
import Button from '@/Components/Console/Button';
import SearchInput from '@/Components/Console/SearchInput';
import { Printer, History } from 'lucide-react';
import csrfFetch from '@/lib/csrfFetch';

const TIER_STYLE = {
    general: { band: 'bg-accent', label: null },
    speaker: { band: 'bg-[#5B4FE0]', label: 'SPEAKER', labelClass: 'text-[#5B4FE0]' },
    vip: { band: 'bg-[#B8860B]', label: 'VIP', labelClass: 'text-[#B8860B]' },
    staff: { band: 'bg-[#4A5560]', label: 'STAFF', labelClass: 'text-[#4A5560]' },
};

function Badge({ event, badge }) {
    const tier = TIER_STYLE[badge.badge_tier] ?? TIER_STYLE.general;

    return (
        <div className="flex aspect-[10/7] w-full max-w-[420px] border border-border bg-white text-[#111618] break-inside-avoid">
            <div className={`w-2 shrink-0 ${tier.band}`} />
            <div className="flex flex-1 flex-col p-5">
                <div className="flex items-center justify-between">
                    <div className="text-[10px] uppercase tracking-wide text-[#5A656A]">{event.name}</div>
                    {tier.label && <div className={`text-[10px] font-bold tracking-wide ${tier.labelClass}`}>{tier.label}</div>}
                </div>
                <div className="mt-auto text-2xl leading-tight tracking-tight">{badge.full_name}</div>
                <div className="mt-1.5 text-xs text-[#5A656A]">{badge.ticket_type_name ?? 'General admission'}</div>
                <div className="mt-4 flex items-end justify-between gap-3">
                    {badge.qr_image && <img src={badge.qr_image} alt="" className="h-14 w-14 shrink-0" />}
                    <div className="flex flex-col items-end gap-0.5 text-right text-[11px] text-[#5A656A]">
                        <span className="font-mono">{badge.ticket_code}</span>
                        {badge.seat_label && <span className="font-mono">Seat {badge.seat_label}</span>}
                    </div>
                </div>
            </div>
        </div>
    );
}

export default function BadgesPanel({ event }) {
    const [badges, setBadges] = useState(null);
    const [recentPrints, setRecentPrints] = useState([]);
    const [query, setQuery] = useState('');
    const [printing, setPrinting] = useState(false);

    const load = () => {
        csrfFetch(route('tenant.events.badges.index', { event: event.id }))
            .then((r) => r.json())
            .then((data) => {
                setBadges(data.badges);
                setRecentPrints(data.recent_prints);
            });
    };

    useEffect(load, [event.id]);

    const filtered = useMemo(() => {
        if (!badges) {
            return [];
        }
        const q = query.trim().toLowerCase();
        if (!q) {
            return badges;
        }
        return badges.filter((b) => b.full_name.toLowerCase().includes(q) || b.ticket_code?.toLowerCase().includes(q));
    }, [badges, query]);

    const print = async () => {
        setPrinting(true);
        await csrfFetch(route('tenant.events.badges.print-log', { event: event.id }), {
            method: 'POST',
            body: JSON.stringify({ registration_ids: filtered.map((b) => b.id) }),
        });
        setPrinting(false);
        window.print();
        load();
    };

    return (
        <div>
            <div className="no-print mb-5 flex items-center justify-between gap-3">
                <div>
                    <p className="text-sm text-ink-secondary">Design once, print for everyone, reprint at the desk.</p>
                    <p className="mt-1 text-xs text-ink-tertiary">
                        Search for a name to reprint just one badge, or leave it blank and print the whole batch.
                    </p>
                </div>
                <Button icon={Printer} variant="primary" onClick={print} disabled={filtered.length === 0 || printing}>
                    Print {filtered.length > 0 ? filtered.length : ''} badge{filtered.length === 1 ? '' : 's'}
                </Button>
            </div>

            <div className="no-print mb-5">
                <SearchInput placeholder="Search by name or entry code" value={query} onChange={(e) => setQuery(e.target.value)} className="w-full max-w-sm" />
            </div>

            {badges === null && <p className="text-sm text-ink-secondary">Loading badges…</p>}

            {badges !== null && badges.length === 0 && (
                <p className="text-sm text-ink-secondary">No confirmed registrations yet — badges appear here once someone checks out.</p>
            )}

            {badges !== null && badges.length > 0 && filtered.length === 0 && (
                <p className="text-sm text-ink-secondary">No badge matches "{query}".</p>
            )}

            {filtered.length > 0 && (
                <div className="no-print mb-6 grid grid-cols-1 gap-1.5 text-xs text-ink-tertiary sm:grid-cols-2">
                    {filtered.map((b) => (
                        b.print_count > 0 && (
                            <div key={b.id} className="flex items-center gap-1.5">
                                <Printer className="h-3 w-3" strokeWidth={1.75} />
                                {b.full_name} — printed {b.print_count}×
                            </div>
                        )
                    ))}
                </div>
            )}

            {filtered.length > 0 && (
                <div className="print-area grid grid-cols-1 gap-4 sm:grid-cols-2 print:grid-cols-2">
                    {filtered.map((b) => (
                        <Badge key={b.id} event={event} badge={b} />
                    ))}
                </div>
            )}

            {recentPrints.length > 0 && (
                <div className="no-print mt-8 border border-border p-4">
                    <b className="mb-2 flex items-center gap-1.5 text-sm font-medium text-ink">
                        <History className="h-3.5 w-3.5" strokeWidth={1.75} /> Print history
                    </b>
                    <ul className="divide-y divide-border">
                        {recentPrints.map((p, i) => (
                            <li key={i} className="flex items-center justify-between py-1.5 text-[13px]">
                                <span className="text-ink">{p.registrant_name}</span>
                                <span className="text-ink-secondary">{p.printed_by} · {new Date(p.printed_at).toLocaleString()}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}
