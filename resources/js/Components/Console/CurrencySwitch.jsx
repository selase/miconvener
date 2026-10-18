import { router } from '@inertiajs/react';

/**
 * Prices in GHS or USD. Ghana defaults to GHS and everywhere else to USD; the
 * choice is remembered for a year. USD prices are always charged in GHS.
 */
export default function CurrencySwitch({ value }) {
    const choose = (currency) => {
        if (currency !== value) {
            router.post('/currency', { currency }, { preserveScroll: true });
        }
    };

    return (
        <div className="inline-flex items-center gap-2 text-sm text-ink-secondary">
            <span>Prices in</span>
            <div className="inline-flex rounded-lg bg-surface-sunken p-1">
                {['GHS', 'USD'].map((currency) => (
                    <button
                        key={currency}
                        type="button"
                        aria-pressed={currency === value}
                        onClick={() => choose(currency)}
                        className={`rounded-md px-3 py-1 text-[13px] font-medium transition-colors duration-120 ease-out ${
                            currency === value
                                ? 'bg-surface text-ink shadow-raised'
                                : 'text-ink-secondary'
                        }`}
                    >
                        {currency}
                    </button>
                ))}
            </div>
        </div>
    );
}
