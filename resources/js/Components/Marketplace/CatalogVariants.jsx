import Input from '@/Components/Console/Input';
import Button from '@/Components/Console/Button';

export function newCatalogVariant() {
    return {
        sku: '',
        price: '',
        options: [],
        is_active: true,
        initial_stock: '0',
        idempotency_key: crypto.randomUUID(),
    };
}

export function catalogFormValues(item) {
    return {
        title: item?.title ?? '',
        slug: item?.slug ?? '',
        description: item?.description ?? '',
        visibility: item?.visibility ?? 'public',
        currency: 'GHS',
        collection_instructions: item?.collection_instructions ?? '',
        order_mode: item?.order_mode ?? 'stock',
        enquiry_phone: item?.enquiry_phone ?? '',
        variants: item
            ? item.variants.map((variant) => ({
                  id: variant.id,
                  sku: variant.sku,
                  price: (variant.price_pesewas / 100).toFixed(2),
                  options: Object.entries(variant.options).map(([name, value]) => ({
                      name,
                      value,
                  })),
                  is_active: variant.is_active,
              }))
            : [newCatalogVariant()],
    };
}

export default function CatalogVariants({ variants, onChange, errors, madeToOrder = false }) {
    const update = (index, field, value) =>
        onChange(
            variants.map((variant, position) =>
                position === index ? { ...variant, [field]: value } : variant
            )
        );
    return (
        <section className="min-w-0 space-y-5 border border-border bg-surface p-5 sm:p-6">
            <div>
                <h2 className="text-xl font-semibold text-ink">Options & prices</h2>
                <p className="mt-2 text-sm leading-6 text-ink-secondary">
                    Use one option for a simple item. Add variants for sizes, colours or other
                    choices. Each SKU must be unique in your store.
                </p>
            </div>
            {variants.map((variant, index) => (
                <fieldset
                    key={variant.id ?? variant.idempotency_key}
                    className="min-w-0 space-y-4 border border-border p-4"
                >
                    <legend className="px-2 text-sm font-semibold text-ink">
                        Variant {index + 1}
                    </legend>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Input
                            label="SKU"
                            name={`variants.${index}.sku`}
                            value={variant.sku}
                            onChange={(event) => update(index, 'sku', event.target.value)}
                            required
                            maxLength={64}
                            error={errors[`variants.${index}.sku`]}
                            className="rounded-none bg-canvas"
                        />
                        <Input
                            label={madeToOrder ? 'Base price (GHS)' : 'Price (GHS)'}
                            name={`variants.${index}.price_pesewas`}
                            type="number"
                            inputMode="decimal"
                            min="0"
                            step="0.01"
                            value={variant.price}
                            onChange={(event) => update(index, 'price', event.target.value)}
                            required
                            error={errors[`variants.${index}.price_pesewas`]}
                            className="rounded-none bg-canvas"
                        />
                    </div>
                    {variant.options.length ? (
                        variant.options.map((option, optionIndex) => (
                            <div
                                key={optionIndex}
                                className="grid min-w-0 grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] items-end gap-2"
                            >
                                <Input
                                    label={`Option name ${optionIndex + 1}`}
                                    value={option.name}
                                    required
                                    maxLength={50}
                                    placeholder="Size"
                                    className="rounded-none bg-canvas"
                                    onChange={(event) =>
                                        update(
                                            index,
                                            'options',
                                            variant.options.map((row, position) =>
                                                position === optionIndex
                                                    ? { ...row, name: event.target.value }
                                                    : row
                                            )
                                        )
                                    }
                                />
                                <Input
                                    label={`Option value ${optionIndex + 1}`}
                                    value={option.value}
                                    required
                                    maxLength={100}
                                    placeholder="Medium"
                                    className="rounded-none bg-canvas"
                                    onChange={(event) =>
                                        update(
                                            index,
                                            'options',
                                            variant.options.map((row, position) =>
                                                position === optionIndex
                                                    ? { ...row, value: event.target.value }
                                                    : row
                                            )
                                        )
                                    }
                                />
                                <button
                                    type="button"
                                    aria-label={`Remove option ${optionIndex + 1} from variant ${index + 1}`}
                                    className="h-10 px-2 text-sm text-ink-secondary hover:text-danger-fg"
                                    onClick={() =>
                                        update(
                                            index,
                                            'options',
                                            variant.options.filter(
                                                (_, position) => position !== optionIndex
                                            )
                                        )
                                    }
                                >
                                    ×
                                </button>
                            </div>
                        ))
                    ) : (
                        <p className="text-xs text-ink-secondary">
                            A standard item with no size or colour choices.
                        </p>
                    )}
                    <Button
                        type="button"
                        className="rounded-none"
                        disabled={variant.options.length >= 8}
                        onClick={() =>
                            update(index, 'options', [...variant.options, { name: '', value: '' }])
                        }
                    >
                        Add option
                    </Button>
                    {!variant.id && !madeToOrder && (
                        <Input
                            label="Opening stock"
                            name={`variants.${index}.initial_stock`}
                            type="number"
                            min="0"
                            step="1"
                            value={variant.initial_stock}
                            onChange={(event) => update(index, 'initial_stock', event.target.value)}
                            error={errors[`variants.${index}.initial_stock`]}
                            hint="Recorded once as an audited opening balance."
                            className="rounded-none bg-canvas"
                        />
                    )}
                    <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border pt-4">
                        <label className="inline-flex items-center gap-2 text-sm text-ink">
                            <input
                                type="checkbox"
                                checked={variant.is_active}
                                onChange={(event) =>
                                    update(index, 'is_active', event.target.checked)
                                }
                                className="accent-accent"
                            />
                            List this variant
                        </label>
                        {!variant.id && variants.length > 1 && (
                            <button
                                type="button"
                                className="text-xs text-ink-secondary underline"
                                onClick={() =>
                                    onChange(variants.filter((_, position) => position !== index))
                                }
                            >
                                Remove new variant
                            </button>
                        )}
                    </div>
                </fieldset>
            ))}
            <Button
                type="button"
                className="rounded-none"
                disabled={variants.length >= 100}
                onClick={() => onChange([...variants, newCatalogVariant()])}
            >
                Add variant
            </Button>
        </section>
    );
}
