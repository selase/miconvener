import { useForm, router, Link } from '@inertiajs/react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import Button from '@/Components/Console/Button';
import Input from '@/Components/Console/Input';
import Select from '@/Components/Console/Select';
import PageHeader from '@/Components/Console/PageHeader';
import {
    ArrowLeft,
    Save,
    CheckCircle2,
    XCircle,
    Building2,
    Zap,
    Armchair,
    Volume2,
    ShieldCheck,
    Utensils,
} from 'lucide-react';
import { useState } from 'react';

const CATEGORY_META = {
    power_climate: { label: 'Power & Climate', icon: Zap },
    furniture: { label: 'Tables, Seating & Staging', icon: Armchair },
    av_tech: { label: 'Audio, Visual & Tech', icon: Volume2 },
    facilities: { label: 'Facilities, Parking & Access', icon: ShieldCheck },
    catering_rules: { label: 'Catering & Food Policy', icon: Utensils },
};

export default function SpaceForm({ space, amenities }) {
    const isEdit = !!space;

    // Convert initial amenities from listing
    const initialAmenityMap = {};
    if (space?.amenities) {
        space.amenities.forEach((a) => {
            initialAmenityMap[a.amenity_id] = {
                amenity_id: a.amenity_id,
                is_included: a.is_included,
                notes: a.notes || '',
            };
        });
    }

    const [amenitySelections, setAmenitySelections] = useState(initialAmenityMap);

    const { data, setData, post, put, processing, errors } = useForm({
        title: space?.title || '',
        slug: space?.slug || '',
        description: space?.description || '',
        rental_price: space ? (space.rental_price_pesewas / 100).toString() : '',
        pricing_model: space?.pricing_model || 'per_day',
        price_visibility: space?.price_visibility || 'public',
        security_deposit: space?.security_deposit_pesewas
            ? (space.security_deposit_pesewas / 100).toString()
            : '',
        capacity_breakdown: {
            banquet: space?.capacity_breakdown?.banquet || '',
            theater: space?.capacity_breakdown?.theater || '',
            cocktail: space?.capacity_breakdown?.cocktail || '',
            classroom: space?.capacity_breakdown?.classroom || '',
        },
        floor_area_sqm: space?.floor_area_sqm || '',
        ceiling_height_meters: space?.ceiling_height_meters || '',
        status: space?.status || 'published',
        amenities: [],
    });

    const handleAmenityToggle = (amenityId, isIncluded) => {
        setAmenitySelections((prev) => {
            const current = prev[amenityId];
            if (current && current.is_included === isIncluded) {
                // Deselect / Remove
                const next = { ...prev };
                delete next[amenityId];
                return next;
            }
            return {
                ...prev,
                [amenityId]: {
                    amenity_id: amenityId,
                    is_included: isIncluded,
                    notes: current?.notes || '',
                },
            };
        });
    };

    const handleAmenityNoteChange = (amenityId, notes) => {
        setAmenitySelections((prev) => ({
            ...prev,
            [amenityId]: {
                amenity_id: amenityId,
                is_included: prev[amenityId]?.is_included ?? true,
                notes,
            },
        }));
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        const formattedAmenities = Object.values(amenitySelections);

        const payload = {
            ...data,
            amenities: formattedAmenities,
        };

        if (isEdit) {
            router.put(route('tenant.venue.spaces.update', { listing: space.id }), payload);
        } else {
            router.post(route('tenant.venue.spaces.store'), payload);
        }
    };

    // Auto-generate slug from title
    const handleTitleChange = (e) => {
        const val = e.target.value;
        setData((prev) => ({
            ...prev,
            title: val,
            slug: isEdit
                ? prev.slug
                : val
                      .toLowerCase()
                      .replace(/[^a-z0-9]+/g, '-')
                      .replace(/(^-|-$)/g, ''),
        }));
    };

    // Group amenities by category
    const groupedAmenities = amenities.reduce((acc, a) => {
        acc[a.category] = acc[a.category] || [];
        acc[a.category].push(a);
        return acc;
    }, {});

    return (
        <form onSubmit={handleSubmit} className="space-y-8 max-w-4xl pb-12">
            <PageHeader
                title={isEdit ? `Edit ${space.title}` : 'Add Venue Space'}
                actions={
                    <Button
                        href={route('tenant.venue.spaces.index')}
                        variant="default"
                        icon={ArrowLeft}
                    >
                        Back to Spaces
                    </Button>
                }
            />

            {/* SECTION 1: Space Overview */}
            <div className="rounded-lg border border-border bg-surface p-6 space-y-4">
                <h3 className="text-base font-semibold text-ink">1. Space Overview</h3>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <Input
                        label="Space / Hall Title"
                        placeholder="e.g. Omanye Main Ballroom"
                        value={data.title}
                        onChange={handleTitleChange}
                        error={errors.title}
                        required
                    />
                    <Input
                        label="URL Identifier (Slug)"
                        value={data.slug}
                        onChange={(e) => setData('slug', e.target.value)}
                        error={errors.slug}
                        required
                    />
                </div>
                <div>
                    <label className="block text-xs font-medium text-ink-secondary mb-1">
                        Description
                    </label>
                    <textarea
                        rows={3}
                        className="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm text-ink placeholder:text-ink-secondary focus:border-accent focus:outline-none"
                        placeholder="Highlight key room features, acoustics, natural lighting, or decor..."
                        value={data.description}
                        onChange={(e) => setData('description', e.target.value)}
                    />
                    {errors.description && (
                        <p className="mt-1 text-xs text-danger-fg">{errors.description}</p>
                    )}
                </div>
                <div className="grid grid-cols-2 gap-4">
                    <Input
                        label="Floor Area (sqm)"
                        type="number"
                        step="0.1"
                        placeholder="e.g. 450"
                        value={data.floor_area_sqm}
                        onChange={(e) => setData('floor_area_sqm', e.target.value)}
                        error={errors.floor_area_sqm}
                    />
                    <Input
                        label="Ceiling Height (meters)"
                        type="number"
                        step="0.1"
                        placeholder="e.g. 5.5"
                        value={data.ceiling_height_meters}
                        onChange={(e) => setData('ceiling_height_meters', e.target.value)}
                        error={errors.ceiling_height_meters}
                    />
                </div>
            </div>

            {/* SECTION 2: Seating Capacity Matrix */}
            <div className="rounded-lg border border-border bg-surface p-6 space-y-4">
                <h3 className="text-base font-semibold text-ink">2. Seating Capacity Matrix</h3>
                <p className="text-xs text-ink-secondary">
                    Specify maximum attendee capacities across common seating arrangements.
                    Organizers search by seating style.
                </p>
                <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <Input
                        label="Banquet (Round Tables)"
                        type="number"
                        placeholder="e.g. 350"
                        value={data.capacity_breakdown.banquet}
                        onChange={(e) =>
                            setData('capacity_breakdown', {
                                ...data.capacity_breakdown,
                                banquet: e.target.value,
                            })
                        }
                    />
                    <Input
                        label="Theater / Auditorium"
                        type="number"
                        placeholder="e.g. 600"
                        value={data.capacity_breakdown.theater}
                        onChange={(e) =>
                            setData('capacity_breakdown', {
                                ...data.capacity_breakdown,
                                theater: e.target.value,
                            })
                        }
                    />
                    <Input
                        label="Cocktail / Standing"
                        type="number"
                        placeholder="e.g. 800"
                        value={data.capacity_breakdown.cocktail}
                        onChange={(e) =>
                            setData('capacity_breakdown', {
                                ...data.capacity_breakdown,
                                cocktail: e.target.value,
                            })
                        }
                    />
                    <Input
                        label="Classroom"
                        type="number"
                        placeholder="e.g. 250"
                        value={data.capacity_breakdown.classroom}
                        onChange={(e) =>
                            setData('capacity_breakdown', {
                                ...data.capacity_breakdown,
                                classroom: e.target.value,
                            })
                        }
                    />
                </div>
            </div>

            {/* SECTION 3: Pricing & Transparency */}
            <div className="rounded-lg border border-border bg-surface p-6 space-y-4">
                <h3 className="text-base font-semibold text-ink">3. Rental Pricing & Visibility</h3>
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <Input
                        label="Base Rental Price (GHS)"
                        type="number"
                        step="0.01"
                        placeholder="e.g. 15000.00"
                        value={data.rental_price}
                        onChange={(e) => setData('rental_price', e.target.value)}
                        error={errors.rental_price}
                        required
                    />
                    <Select
                        label="Pricing Model"
                        value={data.pricing_model}
                        onChange={(e) => setData('pricing_model', e.target.value)}
                        error={errors.pricing_model}
                    >
                        <option value="per_day">Per Day (Full Day)</option>
                        <option value="per_half_day">Per Half Day (4 Hours)</option>
                        <option value="per_hour">Per Hour</option>
                        <option value="flat_rate">Flat Rate per Event</option>
                    </Select>
                    <Input
                        label="Refundable Security Deposit (GHS)"
                        type="number"
                        step="0.01"
                        placeholder="e.g. 3000.00"
                        value={data.security_deposit}
                        onChange={(e) => setData('security_deposit', e.target.value)}
                        error={errors.security_deposit}
                    />
                </div>

                <div className="pt-2">
                    <label className="block text-xs font-medium text-ink-secondary mb-2">
                        Price Visibility on Marketplace
                    </label>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <label
                            className={`flex items-start gap-3 rounded-lg border p-3 cursor-pointer transition-colors ${
                                data.price_visibility === 'public'
                                    ? 'border-accent bg-accent-soft text-ink'
                                    : 'border-border bg-surface hover:bg-surface-hover'
                            }`}
                        >
                            <input
                                type="radio"
                                name="price_visibility"
                                value="public"
                                checked={data.price_visibility === 'public'}
                                onChange={() => setData('price_visibility', 'public')}
                                className="mt-0.5 text-accent"
                            />
                            <div>
                                <div className="text-sm font-semibold">Publicly Display Price</div>
                                <div className="text-xs text-ink-secondary">
                                    Shows exact price on search cards (e.g. "GHS 15,000 / day").
                                    Attracts direct inquiries.
                                </div>
                            </div>
                        </label>

                        <label
                            className={`flex items-start gap-3 rounded-lg border p-3 cursor-pointer transition-colors ${
                                data.price_visibility === 'on_request'
                                    ? 'border-accent bg-accent-soft text-ink'
                                    : 'border-border bg-surface hover:bg-surface-hover'
                            }`}
                        >
                            <input
                                type="radio"
                                name="price_visibility"
                                value="on_request"
                                checked={data.price_visibility === 'on_request'}
                                onChange={() => setData('price_visibility', 'on_request')}
                                className="mt-0.5 text-accent"
                            />
                            <div>
                                <div className="text-sm font-semibold">Price on Request</div>
                                <div className="text-xs text-ink-secondary">
                                    Hides the exact figure on public cards. Displays "Price on
                                    request / Request a quote".
                                </div>
                            </div>
                        </label>
                    </div>
                </div>
            </div>

            {/* SECTION 4: The Included vs Excluded Amenities Grid */}
            <div className="rounded-lg border border-border bg-surface p-6 space-y-6">
                <div>
                    <h3 className="text-base font-semibold text-ink">
                        4. Included vs. Excluded Amenities Grid
                    </h3>
                    <p className="text-xs text-ink-secondary mt-1">
                        Select which amenities are included in the base rental versus which are
                        excluded or require additional fees. Total transparency prevents event-day
                        disputes.
                    </p>
                </div>

                {Object.entries(groupedAmenities).map(([category, items]) => {
                    const meta = CATEGORY_META[category] || { label: category, icon: Building2 };
                    const CategoryIcon = meta.icon;

                    return (
                        <div
                            key={category}
                            className="space-y-3 pt-2 border-t border-border first:border-t-0 first:pt-0"
                        >
                            <div className="flex items-center gap-2 text-sm font-semibold text-ink">
                                <CategoryIcon className="h-4 w-4 text-accent" />
                                {meta.label}
                            </div>
                            <div className="grid grid-cols-1 gap-2.5">
                                {items.map((amenity) => {
                                    const selection =
                                        amenitySelections[amenity.slug] ||
                                        amenitySelections[amenity.id];
                                    const isIncluded = selection?.is_included === true;
                                    const isExcluded = selection?.is_included === false;

                                    return (
                                        <div
                                            key={amenity.id}
                                            className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 rounded-md border border-border p-3 text-sm bg-surface-hover/30"
                                        >
                                            <div className="font-medium text-ink min-w-[200px]">
                                                {amenity.name}
                                            </div>

                                            <div className="flex items-center gap-2">
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        handleAmenityToggle(amenity.id, true)
                                                    }
                                                    className={`inline-flex items-center gap-1.5 rounded px-2.5 py-1 text-xs font-medium transition-colors ${
                                                        isIncluded
                                                            ? 'bg-success-bg text-success-fg border border-success-border font-semibold'
                                                            : 'border border-border text-ink-secondary hover:bg-surface'
                                                    }`}
                                                >
                                                    <CheckCircle2 className="h-3.5 w-3.5" />
                                                    Included
                                                </button>

                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        handleAmenityToggle(amenity.id, false)
                                                    }
                                                    className={`inline-flex items-center gap-1.5 rounded px-2.5 py-1 text-xs font-medium transition-colors ${
                                                        isExcluded
                                                            ? 'bg-danger-bg text-danger-fg border border-danger-border font-semibold'
                                                            : 'border border-border text-ink-secondary hover:bg-surface'
                                                    }`}
                                                >
                                                    <XCircle className="h-3.5 w-3.5" />
                                                    Excluded
                                                </button>

                                                <input
                                                    type="text"
                                                    placeholder="Optional notes (e.g. 300 chairs, extra fee)"
                                                    value={selection?.notes || ''}
                                                    onChange={(e) =>
                                                        handleAmenityNoteChange(
                                                            amenity.id,
                                                            e.target.value
                                                        )
                                                    }
                                                    className="w-full sm:w-64 rounded border border-border bg-surface px-2.5 py-1 text-xs text-ink placeholder:text-ink-secondary focus:border-accent focus:outline-none"
                                                />
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    );
                })}
            </div>

            {/* SECTION 5: Status & Submit */}
            <div className="flex items-center justify-between rounded-lg border border-border bg-surface p-6">
                <Select
                    label="Listing Status"
                    value={data.status}
                    onChange={(e) => setData('status', e.target.value)}
                >
                    <option value="published">Published (Visible in Marketplace)</option>
                    <option value="draft">Draft (Hidden from Marketplace)</option>
                    <option value="suspended">Suspended (Temporarily Unavailable)</option>
                </Select>

                <div className="flex items-center gap-3">
                    <Button href={route('tenant.venue.spaces.index')} variant="default">
                        Cancel
                    </Button>
                    <Button type="submit" variant="primary" icon={Save} disabled={processing}>
                        {isEdit ? 'Save Changes' : 'Create Space'}
                    </Button>
                </div>
            </div>
        </form>
    );
}

SpaceForm.layout = (page) => <ConsoleLayout>{page}</ConsoleLayout>;
