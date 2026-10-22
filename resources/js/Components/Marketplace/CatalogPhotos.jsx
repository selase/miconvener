import { useState } from 'react';
import Button from '@/Components/Console/Button';
import ConfirmModal from '@/Components/Console/ConfirmModal';
import { sendJson } from '@/lib/dues';
import { csrfFetchFormData } from '@/lib/csrfFetch';
import { artifactJson } from '@/lib/artifactResponse';

export default function CatalogPhotos({ item, onChange }) {
    const [file, setFile] = useState(null);
    const [removing, setRemoving] = useState(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const upload = async (event) => {
        event.preventDefault();
        if (!file) return;
        const body = new FormData();
        body.append('photo', file);
        setBusy(true);
        setError('');
        try {
            const data = await artifactJson(
                await csrfFetchFormData(
                    route('tenant.store.catalog.photos', { listing: item.id }),
                    body
                ),
                'The photo could not be uploaded. Try again.'
            );
            onChange(data.listing);
            setFile(null);
            event.target.reset();
        } catch (failure) {
            setError(Object.values(failure.fields ?? {}).flat()[0] ?? failure.message);
        } finally {
            setBusy(false);
        }
    };
    const remove = async () => {
        setBusy(true);
        setError('');
        const result = await sendJson(
            'DELETE',
            route('tenant.store.catalog.photos.destroy', { listing: item.id, media: removing })
        );
        setBusy(false);
        setRemoving(null);
        if (!result.ok) {
            setError(Object.values(result.errors)[0] || result.message);
            return;
        }
        onChange(result.body.listing);
    };
    return (
        <section className="space-y-5 border border-border bg-surface p-5 sm:p-6">
            <div>
                <h2 className="text-xl font-semibold text-ink">Product photos</h2>
                <p className="mt-2 text-sm leading-6 text-ink-secondary">
                    JPEG, PNG or WebP, up to 5MB each. Add up to eight photos. Access follows the
                    item’s current visibility.
                </p>
            </div>
            {item.photos.length > 0 && (
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    {item.photos.map((photo, index) => (
                        <div
                            key={photo.id}
                            className="space-y-2 border border-border bg-canvas p-2"
                        >
                            <img
                                src={photo.url}
                                alt={`Product photo ${index + 1}`}
                                className="aspect-square w-full object-contain"
                            />
                            <button
                                type="button"
                                disabled={busy}
                                onClick={() => setRemoving(photo.id)}
                                className="w-full text-xs text-ink-secondary underline"
                                aria-label={`Remove product photo ${index + 1}`}
                            >
                                Remove photo
                            </button>
                        </div>
                    ))}
                </div>
            )}
            <form onSubmit={upload} className="space-y-4">
                <label className="block text-sm font-medium text-ink" htmlFor="catalog-photo">
                    Choose a product photo
                </label>
                <input
                    id="catalog-photo"
                    type="file"
                    required
                    accept="image/jpeg,image/png,image/webp"
                    disabled={busy || item.photos.length >= 8}
                    className="block w-full min-w-0 text-sm text-ink file:mr-3 file:border file:border-border file:bg-canvas file:px-3 file:py-2 file:text-ink"
                    onChange={(event) => setFile(event.target.files?.[0] ?? null)}
                />
                {error && (
                    <p role="alert" className="text-sm text-danger-fg">
                        {error}
                    </p>
                )}
                <Button
                    type="submit"
                    className="rounded-none"
                    disabled={busy || !file || item.photos.length >= 8}
                >
                    {busy ? 'Uploading…' : 'Upload photo'}
                </Button>
            </form>
            <ConfirmModal
                open={removing !== null}
                onClose={() => setRemoving(null)}
                onConfirm={remove}
                processing={busy}
                title="Remove this photo?"
                description="This removes the image from the item and frees its storage allowance."
                confirmLabel="Remove photo"
            />
        </section>
    );
}
