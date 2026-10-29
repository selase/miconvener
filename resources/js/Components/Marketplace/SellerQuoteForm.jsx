import { useEffect, useRef, useState } from 'react';
import Input from '@/Components/Console/Input';
import Select from '@/Components/Console/Select';
import Button from '@/Components/Console/Button';
import StoreFulfilmentChoice from '@/Components/Marketplace/StoreFulfilmentChoice';
import { formatDuesMoney, sendJson, toMinorUnits } from '@/lib/dues';

const newLine = () => ({ key: crypto.randomUUID(), variant_id: '', quantity: '1', unit_price: '' });
export default function SellerQuoteForm({
    variants = [],
    policy = null,
    canIssue = false,
    onIssued,
}) {
    const [buyer, setBuyer] = useState('');
    const [hours, setHours] = useState('48');
    const [customisation, setCustomisation] = useState('No custom printing.');
    const [production, setProduction] = useState('');
    const [printing, setPrinting] = useState('0');
    const [delivery, setDelivery] = useState('0');
    const [lines, setLines] = useState(() => [newLine()]);
    const [choice, setChoice] = useState(null);
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState({});
    const operation = useRef(crypto.randomUUID());
    const alert = useRef(null);
    useEffect(() => {
        if (Object.keys(errors).length) alert.current?.focus();
    }, [errors]);
    const setLine = (key, field, value) =>
        setLines((current) =>
            current.map((line) => {
                if (line.key !== key) return line;
                if (field === 'variant_id') {
                    const variant = variants.find((item) => item.id === value);
                    return {
                        ...line,
                        variant_id: value,
                        unit_price: variant ? (variant.price_pesewas / 100).toFixed(2) : '',
                    };
                }
                return { ...line, [field]: value };
            })
        );
    const amounts = lines.map((line) => toMinorUnits(line.unit_price) * Number(line.quantity));
    const subtotal =
        amounts.reduce((sum, value) => sum + (Number.isSafeInteger(value) ? value : 0), 0) +
        (toMinorUnits(printing) ?? 0) +
        (choice?.method === 'delivery' ? (toMinorUnits(delivery) ?? 0) : 0);
    const issue = async (event) => {
        event.preventDefault();
        if (busy || !canIssue || !variants.length) return;
        const values = lines.map((line) => ({
            variant_id: line.variant_id,
            quantity: Number(line.quantity),
            unit_price_pesewas: toMinorUnits(line.unit_price),
        }));
        const printAmount = toMinorUnits(printing);
        const deliveryAmount = choice?.method === 'delivery' ? toMinorUnits(delivery) : 0;
        if (
            !choice ||
            !buyer.trim() ||
            !production.trim() ||
            !customisation.trim() ||
            values.some(
                (line) =>
                    !line.variant_id ||
                    !Number.isInteger(line.quantity) ||
                    line.quantity < 1 ||
                    line.quantity > 10000 ||
                    !Number.isSafeInteger(line.unit_price_pesewas) ||
                    line.unit_price_pesewas < 0
            ) ||
            !Number.isSafeInteger(printAmount) ||
            printAmount < 0 ||
            !Number.isSafeInteger(deliveryAmount) ||
            deliveryAmount < 0
        ) {
            setErrors({
                quote: 'Complete the buyer, product, specification, timeframe and agreed fulfilment details. Use non-negative prices with up to two decimal places.',
            });
            return;
        }
        setErrors({});
        setBusy(true);
        const result = await sendJson('POST', route('tenant.store.quotes.store'), {
            operation_key: operation.current,
            buyer_email: buyer.trim(),
            valid_for_hours: Number(hours),
            customisation,
            production_terms: production,
            printing_pesewas: printAmount,
            delivery_pesewas: deliveryAmount,
            lines: values,
            choice,
        });
        setBusy(false);
        if (!result.ok) {
            setErrors(
                Object.keys(result.errors).length ? result.errors : { quote: result.message }
            );
            return;
        }
        onIssued(result.body.quote);
        operation.current = crypto.randomUUID();
        setBuyer('');
        setProduction('');
        setCustomisation('No custom printing.');
        setLines([newLine()]);
        setChoice(null);
        setPrinting('0');
        setDelivery('0');
    };
    return (
        <form
            onSubmit={issue}
            className="min-w-0 space-y-6 border border-border bg-surface p-5 sm:p-7"
        >
            <div>
                <h2 className="text-xl font-semibold text-ink">Create an agreed quote</h2>
                <p className="mt-2 max-w-3xl text-sm leading-6 text-ink-secondary">
                    Record what you and the buyer have agreed. Issued prices and specifications are
                    fixed; withdraw an unpaid quote and issue a replacement to make changes.
                </p>
            </div>
            {Object.keys(errors).length > 0 && (
                <div
                    ref={alert}
                    role="alert"
                    tabIndex={-1}
                    className="border-l-2 border-danger-fg bg-danger-bg p-4 text-sm text-danger-fg"
                >
                    <ul className="space-y-1">
                        {Object.entries(errors).map(([key, message]) => (
                            <li key={key}>
                                {Array.isArray(message) ? message.join(' ') : message}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
            <fieldset
                disabled={busy || !canIssue || !variants.length}
                className="min-w-0 space-y-6 disabled:opacity-60"
            >
                <div className="grid gap-4 sm:grid-cols-2">
                    <Input
                        label="Buyer email"
                        name="buyer_email"
                        type="email"
                        required
                        maxLength={255}
                        value={buyer}
                        onChange={(e) => setBuyer(e.target.value)}
                        error={errors.buyer_email}
                        hint="The buyer must verify this address to review and pay. No membership is required for public products."
                        className="rounded-none"
                    />
                    <Input
                        label="Valid for (hours)"
                        name="valid_for_hours"
                        type="number"
                        required
                        min={1}
                        max={720}
                        step={1}
                        value={hours}
                        onChange={(e) => setHours(e.target.value)}
                        error={errors.valid_for_hours}
                        hint="Default 48 hours; up to 30 days. Production time is agreed separately."
                        className="rounded-none"
                    />
                </div>
                <section className="space-y-4" aria-label="Quoted products">
                    {lines.map((line, index) => (
                        <div
                            key={line.key}
                            className="grid min-w-0 gap-4 border border-border p-4 sm:grid-cols-2 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_auto]"
                        >
                            <Select
                                label={`Product ${index + 1}`}
                                name={`lines.${index}.variant_id`}
                                required
                                value={line.variant_id}
                                onChange={(e) => setLine(line.key, 'variant_id', e.target.value)}
                                error={errors[`lines.${index}.variant_id`]}
                                className="rounded-none"
                            >
                                <option value="">Choose made-to-order merchandise</option>
                                {variants.map((variant) => (
                                    <option key={variant.id} value={variant.id}>
                                        {variant.label}
                                        {variant.visibility === 'members' ? ' · Members only' : ''}
                                    </option>
                                ))}
                            </Select>
                            <Input
                                label={`Quantity ${index + 1}`}
                                name={`lines.${index}.quantity`}
                                required
                                type="number"
                                min={1}
                                max={10000}
                                step={1}
                                value={line.quantity}
                                onChange={(e) => setLine(line.key, 'quantity', e.target.value)}
                                error={errors[`lines.${index}.quantity`]}
                                className="rounded-none"
                            />
                            <Input
                                label={`Unit price ${index + 1} (GHS)`}
                                name={`lines.${index}.unit_price_pesewas`}
                                required
                                type="number"
                                min={0}
                                step="0.01"
                                value={line.unit_price}
                                onChange={(e) => setLine(line.key, 'unit_price', e.target.value)}
                                error={errors[`lines.${index}.unit_price_pesewas`]}
                                className="rounded-none"
                            />
                            {lines.length > 1 && (
                                <button
                                    type="button"
                                    onClick={() =>
                                        setLines((current) =>
                                            current.filter((row) => row.key !== line.key)
                                        )
                                    }
                                    aria-label={`Remove product ${index + 1}`}
                                    className="self-end py-3 text-sm text-ink-secondary underline"
                                >
                                    Remove
                                </button>
                            )}
                        </div>
                    ))}
                    <Button
                        type="button"
                        disabled={lines.length >= 20}
                        onClick={() => setLines((current) => [...current, newLine()])}
                        className="rounded-none"
                    >
                        Add another product
                    </Button>
                </section>
                <div className="grid gap-5 lg:grid-cols-2">
                    <label className="block text-sm font-medium text-ink">
                        Customisation specification
                        <textarea
                            name="customisation"
                            required
                            maxLength={5000}
                            rows={4}
                            value={customisation}
                            onChange={(e) => setCustomisation(e.target.value)}
                            className="mt-2 block w-full border border-border bg-canvas p-3 text-sm text-ink focus:outline-accent"
                        />
                    </label>
                    <label className="block text-sm font-medium text-ink">
                        Production timeframe
                        <textarea
                            name="production_terms"
                            required
                            maxLength={2000}
                            rows={4}
                            value={production}
                            onChange={(e) => setProduction(e.target.value)}
                            placeholder="For example: 7–10 working days after payment and final artwork approval."
                            className="mt-2 block w-full border border-border bg-canvas p-3 text-sm text-ink focus:outline-accent"
                        />
                    </label>
                </div>
                <StoreFulfilmentChoice
                    options={policy}
                    value={choice}
                    quoted
                    onChange={(value) => {
                        setChoice(value);
                        if (value.method === 'collection') setDelivery('0');
                    }}
                />
                <div className="grid gap-4 sm:grid-cols-2">
                    <Input
                        label="Printing charge (GHS)"
                        name="printing_pesewas"
                        type="number"
                        required
                        min={0}
                        step="0.01"
                        value={printing}
                        onChange={(e) => setPrinting(e.target.value)}
                        error={errors.printing_pesewas}
                        className="rounded-none"
                    />
                    <Input
                        label="Delivery charge (GHS)"
                        name="delivery_pesewas"
                        type="number"
                        required
                        min={0}
                        step="0.01"
                        disabled={choice?.method !== 'delivery'}
                        value={delivery}
                        onChange={(e) => setDelivery(e.target.value)}
                        error={errors.delivery_pesewas}
                        hint="Use the agreed charge for this delivery address. Collection has no delivery charge."
                        className="rounded-none"
                    />
                </div>
                <div className="flex flex-wrap items-center justify-between gap-5 border-t border-border pt-5">
                    <div>
                        <p className="text-sm text-ink-secondary">Goods and extras</p>
                        <p className="mt-1 text-2xl font-semibold text-ink">
                            {Number.isSafeInteger(subtotal) && subtotal >= 0
                                ? formatDuesMoney(subtotal, 'GHS')
                                : 'Check the amounts'}
                        </p>
                        <p className="mt-2 max-w-lg text-xs leading-5 text-ink-secondary">
                            The issued quote shows the final payable total, including any applicable
                            buyer checkout fee. This creates a private payment link; no email is
                            sent automatically.
                        </p>
                    </div>
                    <Button
                        type="submit"
                        variant="primary"
                        disabled={busy || !canIssue || !variants.length}
                        className="rounded-none"
                    >
                        {busy ? 'Issuing…' : 'Issue quote & create payment link'}
                    </Button>
                </div>
            </fieldset>
            {!variants.length && (
                <p role="status" className="text-sm text-ink-secondary">
                    Publish a made-to-order product before creating a quote.
                </p>
            )}
        </form>
    );
}
