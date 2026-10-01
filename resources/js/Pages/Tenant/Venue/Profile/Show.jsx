import { useForm, router } from '@inertiajs/react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import Button from '@/Components/Console/Button';
import Input from '@/Components/Console/Input';
import PageHeader from '@/Components/Console/PageHeader';
import {
    Building2,
    MapPin,
    CheckCircle2,
    AlertCircle,
    Clock,
    Shield,
    Save,
    ArrowLeft,
} from 'lucide-react';
import { useState } from 'react';
import ConfirmModal from '@/Components/Console/ConfirmModal';

export default function VenueProfileShow({ shop }) {
    const [confirmModalOpen, setConfirmModalOpen] = useState(false);

    const { data, setData, post, processing, errors } = useForm({
        name: shop.name || '',
        slug: shop.slug || '',
        description: shop.description || '',
        email: shop.email || '',
        phone: shop.phone || '',
        address: shop.address || '',
        city: shop.city || '',
        region: shop.region || '',
        latitude: shop.latitude || '',
        longitude: shop.longitude || '',
    });

    const handleSave = (e) => {
        e.preventDefault();
        router.post(route('tenant.venue.profile.update'), data);
    };

    const handleRequestVerification = () => {
        router.post(
            route('tenant.venue.profile.verify'),
            {},
            {
                onFinish: () => setConfirmModalOpen(false),
            }
        );
    };

    return (
        <div className="space-y-8 max-w-4xl pb-12">
            <PageHeader
                title="Venue & Storefront Profile"
                actions={
                    <Button
                        href={route('tenant.venue.spaces.index')}
                        variant="default"
                        icon={Building2}
                    >
                        View Spaces
                    </Button>
                }
            />

            {/* Verification Status Banner */}
            <div className="rounded-lg border border-border bg-surface p-6">
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div className="flex items-start gap-4">
                        {shop.verification_status === 'verified' && (
                            <div className="rounded-full bg-success-bg p-3 text-success-fg">
                                <CheckCircle2 className="h-6 w-6" />
                            </div>
                        )}
                        {shop.verification_status === 'pending' && (
                            <div className="rounded-full bg-warning-bg p-3 text-warning-fg">
                                <Clock className="h-6 w-6" />
                            </div>
                        )}
                        {shop.verification_status === 'unverified' && (
                            <div className="rounded-full bg-neutral-bg p-3 text-neutral-fg">
                                <Shield className="h-6 w-6" />
                            </div>
                        )}
                        {shop.verification_status === 'rejected' && (
                            <div className="rounded-full bg-danger-bg p-3 text-danger-fg">
                                <AlertCircle className="h-6 w-6" />
                            </div>
                        )}

                        <div>
                            <div className="flex items-center gap-2">
                                <h3 className="text-base font-semibold text-ink">
                                    Trust & Verification
                                </h3>
                                {shop.verification_status === 'verified' && (
                                    <span className="inline-flex items-center gap-1 rounded-full bg-success-bg px-2.5 py-0.5 text-xs font-semibold text-success-fg">
                                        <CheckCircle2 className="h-3 w-3" /> Blue Tick Verified
                                    </span>
                                )}
                                {shop.verification_status === 'pending' && (
                                    <span className="inline-flex items-center gap-1 rounded-full bg-warning-bg px-2.5 py-0.5 text-xs font-semibold text-warning-fg">
                                        Under Review
                                    </span>
                                )}
                            </div>

                            <p className="mt-1 text-sm text-ink-secondary">
                                {shop.verification_status === 'verified' &&
                                    `Your venue was verified on ${new Date(shop.verified_at).toLocaleDateString()}. Your listings appear with a Blue Tick.`}
                                {shop.verification_status === 'pending' &&
                                    'Your credentials have been submitted. Our compliance team is verifying your business registration and event references.'}
                                {shop.verification_status === 'unverified' &&
                                    'Unverified venues appear with an unverified badge. Submit your business registration and Ghana Card to unlock top search placement and verified badges.'}
                                {shop.verification_status === 'rejected' &&
                                    `Verification declined: ${shop.rejection_reason || 'Please contact support with updated documentation.'}`}
                            </p>
                        </div>
                    </div>

                    {shop.verification_status === 'unverified' && (
                        <Button
                            type="button"
                            variant="primary"
                            onClick={() => setConfirmModalOpen(true)}
                        >
                            Request Verification
                        </Button>
                    )}
                </div>
            </div>

            {/* Venue Profile Form */}
            <form
                onSubmit={handleSave}
                className="rounded-lg border border-border bg-surface p-6 space-y-6"
            >
                <h3 className="text-base font-semibold text-ink">Venue Brand & Contact Details</h3>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <Input
                        label="Venue / Brand Name"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        error={errors.name}
                        required
                    />
                    <Input
                        label="Storefront Handle (Slug)"
                        value={data.slug}
                        onChange={(e) => setData('slug', e.target.value)}
                        error={errors.slug}
                        required
                    />
                </div>

                <div>
                    <label className="block text-xs font-medium text-ink-secondary mb-1">
                        About the Venue
                    </label>
                    <textarea
                        rows={3}
                        className="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm text-ink placeholder:text-ink-secondary focus:border-accent focus:outline-none"
                        placeholder="Tell event organizers about your property, accessibility, and reputation..."
                        value={data.description}
                        onChange={(e) => setData('description', e.target.value)}
                    />
                    {errors.description && (
                        <p className="mt-1 text-xs text-danger-fg">{errors.description}</p>
                    )}
                </div>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <Input
                        label="Contact Email"
                        type="email"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        error={errors.email}
                        required
                    />
                    <Input
                        label="Contact Phone"
                        value={data.phone}
                        onChange={(e) => setData('phone', e.target.value)}
                        error={errors.phone}
                        required
                    />
                </div>

                <div className="space-y-4 pt-4 border-t border-border">
                    <h4 className="text-sm font-semibold text-ink flex items-center gap-1.5">
                        <MapPin className="h-4 w-4 text-accent" /> Physical Location & Proximity
                        Search
                    </h4>
                    <p className="text-xs text-ink-secondary">
                        Organizers search for venues "near [landmark/city]". Providing exact
                        coordinates enables accurate proximity sorting.
                    </p>

                    <Input
                        label="Physical Street Address"
                        placeholder="e.g. 1 La Bypass, South La, Accra"
                        value={data.address}
                        onChange={(e) => setData('address', e.target.value)}
                        error={errors.address}
                        required
                    />

                    <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <Input
                            label="City"
                            placeholder="e.g. Accra"
                            value={data.city}
                            onChange={(e) => setData('city', e.target.value)}
                            error={errors.city}
                            required
                        />
                        <Input
                            label="Region"
                            placeholder="e.g. Greater Accra"
                            value={data.region}
                            onChange={(e) => setData('region', e.target.value)}
                            error={errors.region}
                            required
                        />
                        <Input
                            label="Latitude (GPS)"
                            type="number"
                            step="0.000001"
                            placeholder="e.g. 5.560000"
                            value={data.latitude}
                            onChange={(e) => setData('latitude', e.target.value)}
                            error={errors.latitude}
                        />
                        <Input
                            label="Longitude (GPS)"
                            type="number"
                            step="0.000001"
                            placeholder="e.g. -0.150000"
                            value={data.longitude}
                            onChange={(e) => setData('longitude', e.target.value)}
                            error={errors.longitude}
                        />
                    </div>
                </div>

                <div className="flex justify-end pt-4 border-t border-border">
                    <Button type="submit" variant="primary" icon={Save} disabled={processing}>
                        Save Profile
                    </Button>
                </div>
            </form>

            <ConfirmModal
                open={confirmModalOpen}
                title="Submit for Blue Tick Verification"
                description={`Are you ready to submit ${data.name} for verification? Our team will review your business credentials and contact details.`}
                confirmLabel="Submit for Verification"
                onConfirm={handleRequestVerification}
                onClose={() => setConfirmModalOpen(false)}
            />
        </div>
    );
}

VenueProfileShow.layout = (page) => <ConsoleLayout>{page}</ConsoleLayout>;
