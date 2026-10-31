/** GHS text for whole pesewas, e.g. 30000 -> "GHS 300.00". */
export const formatMoney = (pesewas) =>
    `GHS ${((pesewas || 0) / 100).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`;

/** Whole pesewas from a typed GHS amount, or null when it is not a clean amount. */
export function pesewasFromInput(text) {
    const cleaned = String(text ?? '').replace(/,/g, '').trim();
    if (!/^\d+(\.\d{1,2})?$/.test(cleaned)) {
        return null;
    }
    const [whole, fraction = ''] = cleaned.split('.');

    return Number(whole) * 100 + Number(fraction.padEnd(2, '0'));
}

export const newOperationKey = () =>
    globalThis.crypto?.randomUUID
        ? globalThis.crypto.randomUUID()
        : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
              const r = (Math.random() * 16) | 0;
              return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
          });

export const PURPOSE_LABELS = {
    rental_initial: 'Booking payment',
    rental_balance: 'Balance',
    security: 'Refundable security',
    legacy_review: 'Payment under review',
};

export const CHARGE_STATES = {
    paid: { label: 'Paid', tone: 'success' },
    due: { label: 'Due', tone: 'pending' },
    overdue: { label: 'Overdue', tone: 'failed' },
    expired: { label: 'Expired', tone: 'neutral' },
    under_review: { label: 'Under review', tone: 'pending' },
    refund_pending: { label: 'Refund in progress', tone: 'pending' },
    refunded: { label: 'Refunded', tone: 'neutral' },
};
