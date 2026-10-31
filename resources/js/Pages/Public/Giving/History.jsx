import { useEffect, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import PublicLayout from '@/Layouts/PublicLayout';
import GivingSection from '@/Pages/Public/Events/AttendeePortal/components/GivingSection';
import csrfFetch from '@/lib/csrfFetch';
import {
    IDENTITY_EVENT,
    isIdentityStorageEvent,
    storedIdentity,
} from '@/lib/attendeeIdentityCache';
export default function History({ giving, identity, filters = {} }) {
    const [filterForm, setFilterForm] = useState({
        currency: filters.currency ?? '',
        source: filters.source ?? '',
    });
    const [visible, setVisible] = useState(
        () => navigator.onLine !== false && identity?.proof_expires_at > Date.now()
    );
    useEffect(() => {
        let active = true;
        let controller;
        let generation = 0;
        const clear = () => {
            generation++;
            controller?.abort();
            if (active) setVisible(false);
        };
        const check = async () => {
            clear();
            if (navigator.onLine === false) return;
            const current = generation;
            controller = new AbortController();
            try {
                const response = await csrfFetch(route('attendee.my.giving.session'), {
                    signal: controller.signal,
                });
                if (!response.ok) return;
                const context = await response.json();
                if (
                    active &&
                    current === generation &&
                    context.state === 'verified' &&
                    context.owner_key === identity.owner_key &&
                    context.access_key === identity.access_key &&
                    context.proof_expires_at > Date.now()
                )
                    setVisible(true);
            } catch {
                /* Private records remain hidden until ownership is checked. */
            }
        };
        const identityChanged = () => {
            if (storedIdentity()?.owner_key !== identity.owner_key) clear();
        };
        const storage = (e) => {
            if (isIdentityStorageEvent(e)) identityChanged();
        };
        const visibility = () => {
            if (document.visibilityState === 'hidden') clear();
            else check();
        };
        const pageShow = (e) => {
            if (e.persisted) check();
        };
        const channel =
            typeof BroadcastChannel === 'undefined'
                ? null
                : new BroadcastChannel('miconvener-attendee-identity');
        if (channel) channel.onmessage = clear;
        const timer = setTimeout(clear, Math.max(0, identity.proof_expires_at - Date.now()));
        window.addEventListener(IDENTITY_EVENT, identityChanged);
        window.addEventListener('storage', storage);
        window.addEventListener('offline', clear);
        window.addEventListener('online', check);
        window.addEventListener('focus', check);
        window.addEventListener('pagehide', clear);
        window.addEventListener('pageshow', pageShow);
        document.addEventListener('visibilitychange', visibility);
        return () => {
            active = false;
            channel?.close();
            controller?.abort();
            clearTimeout(timer);
            window.removeEventListener(IDENTITY_EVENT, identityChanged);
            window.removeEventListener('storage', storage);
            window.removeEventListener('offline', clear);
            window.removeEventListener('online', check);
            window.removeEventListener('focus', check);
            window.removeEventListener('pagehide', clear);
            window.removeEventListener('pageshow', pageShow);
            document.removeEventListener('visibilitychange', visibility);
        };
    }, [identity]);
    return (
        <PublicLayout>
            <Head title="Your contributions" />
            <main className="mx-auto max-w-4xl px-5 py-10">
                <a href={route('attendee.my')} className="text-sm text-accent underline">
                    Back to my workspace
                </a>
                <h1 className="mt-6 text-3xl font-medium text-ink">
                    Your contributions and payments
                </h1>
                {visible && (
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            router.get(
                                route('attendee.my.giving.index'),
                                Object.fromEntries(
                                    Object.entries({
                                        ...filters,
                                        ...filterForm,
                                        giving_page: 1,
                                    }).filter(([, value]) => value !== '')
                                ),
                                { preserveState: false }
                            );
                        }}
                        className="mt-6 grid items-end gap-4 sm:grid-cols-3"
                    >
                        <label className="text-sm text-ink">
                            Currency
                            <select
                                value={filterForm.currency}
                                onChange={(e) =>
                                    setFilterForm({ ...filterForm, currency: e.target.value })
                                }
                                className="mt-2 block min-h-11 w-full border border-border bg-surface px-3 text-ink"
                            >
                                <option value="">All currencies</option>
                                {['GHS', 'USD', 'EUR', 'GBP'].map((currency) => (
                                    <option key={currency}>{currency}</option>
                                ))}
                            </select>
                        </label>
                        <label className="text-sm text-ink">
                            Contribution type
                            <select
                                value={filterForm.source}
                                onChange={(e) =>
                                    setFilterForm({ ...filterForm, source: e.target.value })
                                }
                                className="mt-2 block min-h-11 w-full border border-border bg-surface px-3 text-ink"
                            >
                                <option value="">All types</option>
                                <option value="online">Online payments</option>
                                <option value="recorded_cash">Recorded cash</option>
                                <option value="bulk_cash">Offering batches</option>
                            </select>
                        </label>
                        <button
                            type="submit"
                            className="min-h-11 border border-border px-5 text-sm text-ink"
                        >
                            Apply filters
                        </button>
                    </form>
                )}
                {visible ? (
                    <GivingSection
                        giving={giving}
                        tenantSlug={filters.tenant_slug}
                        statementFilters={filters}
                        onPage={(_, page) =>
                            router.get(
                                route('attendee.my.giving.index'),
                                { ...filters, giving_page: page },
                                { preserveState: false }
                            )
                        }
                    />
                ) : (
                    <div
                        role="status"
                        className="mt-6 border border-border p-6 text-sm text-ink-secondary"
                    >
                        <p>
                            Private contribution records are hidden while you are offline or your
                            verified session has changed.
                        </p>
                        <a
                            href={route('attendee.my.giving.index', filters)}
                            className="mt-4 inline-block min-h-11 text-accent underline"
                        >
                            Reload and verify access
                        </a>
                    </div>
                )}
                <a
                    href={`${route('attendee.my')}?tab=contributions`}
                    className="mt-8 inline-block min-h-11 py-2 text-sm text-accent underline"
                >
                    Older event contributions and tributes
                </a>
            </main>
        </PublicLayout>
    );
}
