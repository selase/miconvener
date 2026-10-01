import { Link, router } from '@inertiajs/react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import Button from '@/Components/Console/Button';
import PageHeader from '@/Components/Console/PageHeader';
import StatusPill from '@/Components/Console/StatusPill';
import { Table, Thead, Tr, Th, Td } from '@/Components/Console/Table';
import {
    Plus,
    Building2,
    MapPin,
    Users,
    CheckCircle2,
    AlertCircle,
    Edit,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import ConfirmModal from '@/Components/Console/ConfirmModal';

const STATUS_VARIANT = {
    published: 'success',
    draft: 'neutral',
    suspended: 'failed',
};

export default function SpacesIndex({ shop, spaces }) {
    const [deletingSpace, setDeletingSpace] = useState(null);

    const formatCurrency = (pesewas) => {
        if (!pesewas) return 'GHS 0.00';
        return `GHS ${(pesewas / 100).toLocaleString('en-US', { minimumFractionDigits: 2 })}`;
    };

    const confirmDelete = () => {
        if (!deletingSpace) return;
        router.delete(route('tenant.venue.spaces.destroy', { listing: deletingSpace.id }), {
            onFinish: () => setDeletingSpace(null),
        });
    };

    return (
        <div className="space-y-6">
            <PageHeader
                title="Venue Spaces & Halls"
                actions={
                    <div className="flex items-center gap-3">
                        <Button
                            href={route('tenant.venue.profile')}
                            variant="default"
                            icon={Building2}
                        >
                            Venue Profile
                        </Button>
                        <Button
                            href={route('tenant.venue.spaces.create')}
                            variant="primary"
                            icon={Plus}
                        >
                            Add Space
                        </Button>
                    </div>
                }
            />

            {shop.verification_status !== 'verified' && (
                <div className="flex items-center justify-between rounded-lg border border-warning-border bg-warning-bg p-4 text-sm text-warning-fg">
                    <div className="flex items-center gap-3">
                        <AlertCircle className="h-5 w-5 shrink-0" />
                        <div>
                            <span className="font-semibold">Verification Status: </span>
                            {shop.verification_status === 'pending'
                                ? 'Your credentials are under review by the MiConvener team.'
                                : 'Your venue is unverified. Request a Blue Tick verification to unlock verified marketplace placement.'}
                        </div>
                    </div>
                    {shop.verification_status === 'unverified' && (
                        <Button href={route('tenant.venue.profile')} variant="default">
                            Submit Verification
                        </Button>
                    )}
                </div>
            )}

            {spaces.data.length > 0 ? (
                <Table>
                    <Thead>
                        <Th>Space / Hall</Th>
                        <Th>Rental Price</Th>
                        <Th>Capacities</Th>
                        <Th>Amenities Included</Th>
                        <Th>Status</Th>
                        <Th align="right">Actions</Th>
                    </Thead>
                    <tbody>
                        {spaces.data.map((space) => {
                            const banquetCap = space.capacity_breakdown?.banquet;
                            const theaterCap = space.capacity_breakdown?.theater;
                            const includedCount =
                                space.amenities?.filter((a) => a.is_included).length ?? 0;
                            const excludedCount =
                                space.amenities?.filter((a) => !a.is_included).length ?? 0;

                            return (
                                <Tr key={space.id}>
                                    <Td>
                                        <div className="font-semibold text-ink">{space.title}</div>
                                        <div className="text-xs text-ink-secondary">
                                            {space.floor_area_sqm
                                                ? `${space.floor_area_sqm} m² · `
                                                : ''}
                                            {space.pricing_model.replace('_', ' ')}
                                        </div>
                                    </Td>
                                    <Td>
                                        {space.price_visibility === 'public' ? (
                                            <div className="font-mono text-sm font-medium">
                                                {formatCurrency(space.rental_price_pesewas)}
                                            </div>
                                        ) : (
                                            <span className="rounded bg-neutral-bg px-2 py-0.5 text-xs text-neutral-fg">
                                                Price on request
                                            </span>
                                        )}
                                    </Td>
                                    <Td>
                                        <div className="flex flex-wrap gap-1.5 text-xs">
                                            {banquetCap && (
                                                <span className="rounded bg-surface-hover px-2 py-0.5 text-ink-secondary">
                                                    Banquet: {banquetCap}
                                                </span>
                                            )}
                                            {theaterCap && (
                                                <span className="rounded bg-surface-hover px-2 py-0.5 text-ink-secondary">
                                                    Theater: {theaterCap}
                                                </span>
                                            )}
                                            {!banquetCap && !theaterCap && (
                                                <span className="text-ink-secondary">—</span>
                                            )}
                                        </div>
                                    </Td>
                                    <Td>
                                        <div className="text-xs text-ink-secondary">
                                            <span className="text-success-fg font-medium">
                                                {includedCount} included
                                            </span>
                                            {excludedCount > 0 && (
                                                <span> · {excludedCount} excluded</span>
                                            )}
                                        </div>
                                    </Td>
                                    <Td>
                                        <StatusPill status={STATUS_VARIANT[space.status]}>
                                            {space.status}
                                        </StatusPill>
                                    </Td>
                                    <Td align="right">
                                        <div className="flex items-center justify-end gap-2">
                                            <Link
                                                href={route('tenant.venue.spaces.edit', {
                                                    listing: space.id,
                                                })}
                                                className="rounded p-1 text-ink-secondary hover:bg-surface-hover hover:text-ink"
                                                title="Edit Space"
                                            >
                                                <Edit className="h-4 w-4" />
                                            </Link>
                                            <button
                                                type="button"
                                                onClick={() => setDeletingSpace(space)}
                                                className="rounded p-1 text-ink-secondary hover:bg-danger-bg hover:text-danger-fg"
                                                title="Delete Space"
                                            >
                                                <Trash2 className="h-4 w-4" />
                                            </button>
                                        </div>
                                    </Td>
                                </Tr>
                            );
                        })}
                    </tbody>
                </Table>
            ) : (
                <div className="rounded-lg border border-dashed border-border p-12 text-center">
                    <Building2 className="mx-auto h-12 w-12 text-ink-secondary opacity-40" />
                    <h3 className="mt-4 text-base font-semibold text-ink">
                        No venue spaces listed yet
                    </h3>
                    <p className="mt-1 text-sm text-ink-secondary">
                        List your auditoriums, banquet halls, or meeting rooms to showcase them on
                        the MiConvener Marketplace.
                    </p>
                    <div className="mt-6 flex justify-center">
                        <Button
                            href={route('tenant.venue.spaces.create')}
                            variant="primary"
                            icon={Plus}
                        >
                            Add Your First Space
                        </Button>
                    </div>
                </div>
            )}

            <ConfirmModal
                open={Boolean(deletingSpace)}
                title="Delete Venue Space"
                description={`Are you sure you want to delete ${deletingSpace?.title ?? 'this space'}? This action cannot be undone.`}
                confirmLabel="Delete Space"
                danger
                onConfirm={confirmDelete}
                onClose={() => setDeletingSpace(null)}
            />
        </div>
    );
}

SpacesIndex.layout = (page) => <ConsoleLayout>{page}</ConsoleLayout>;
