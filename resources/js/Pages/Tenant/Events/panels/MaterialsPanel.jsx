import { useState } from 'react';
import { router } from '@inertiajs/react';
import Button from '@/Components/Console/Button';
import StatusPill from '@/Components/Console/StatusPill';
import Input from '@/Components/Console/Input';
import { Table, Thead, Th, Tr, Td, TableEmpty } from '@/Components/Console/Table';
import { Upload, FileText, Trash2 } from 'lucide-react';
import { useToast } from '@/Components/Console/Toast';
import csrfFetch, { csrfFetchFormData } from '@/lib/csrfFetch';

function formatSize(bytes) {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

export default function MaterialsPanel({ event, materials }) {
    const [form, setForm] = useState({ title: '', download_limit: 3, release_at: '', file: null });
    const [saving, setSaving] = useState(false);
    const toast = useToast();

    const totalDownloads = materials.reduce((sum, m) => sum + m.downloads_count, 0);
    const waitingToRelease = materials.filter((m) => !m.is_released).length;

    const reload = () => router.reload({ only: ['event'] });

    const upload = async (e) => {
        e.preventDefault();
        if (!form.file) return;
        setSaving(true);

        const body = new FormData();
        body.append('title', form.title || form.file.name);
        body.append('download_limit', form.download_limit);
        if (form.release_at) body.append('release_at', form.release_at);
        body.append('file', form.file);

        const response = await csrfFetchFormData(route('tenant.events.materials.store', { event: event.id }), body);

        setSaving(false);

        if (!response.ok) {
            const json = await response.json();
            toast?.(json.message ?? 'Could not upload material.');
            return;
        }

        setForm({ title: '', download_limit: 3, release_at: '', file: null });
        reload();
    };

    const remove = async (material) => {
        await csrfFetch(route('tenant.events.materials.destroy', { event: event.id, material: material.id }), { method: 'DELETE' });
        reload();
    };

    return (
        <div className="max-w-4xl">
            <div className="mb-5 grid grid-cols-3 gap-4">
                <div className="border border-border p-4">
                    <div className="text-xs text-ink-secondary">Files</div>
                    <div className="mt-1 text-2xl font-semibold text-ink">{materials.length}</div>
                </div>
                <div className="border border-border p-4">
                    <div className="text-xs text-ink-secondary">Downloads so far</div>
                    <div className="mt-1 text-2xl font-semibold text-accent">{totalDownloads}</div>
                </div>
                <div className="border border-border p-4">
                    <div className="text-xs text-ink-secondary">Waiting to release</div>
                    <div className="mt-1 text-2xl font-semibold text-ink">{waitingToRelease}</div>
                </div>
            </div>

            {materials.length > 0 ? (
                <Table>
                    <Thead>
                        <Th>File</Th>
                        <Th>Attempts each</Th>
                        <Th>Downloads</Th>
                        <Th>Release</Th>
                        <Th />
                    </Thead>
                    <tbody>
                        {materials.map((m) => (
                            <Tr key={m.id}>
                                <Td>
                                    <div className="flex items-center gap-2.5">
                                        <FileText className="h-4 w-4 shrink-0 text-ink-secondary" strokeWidth={1.5} />
                                        <div>
                                            <div className="text-ink">{m.title}</div>
                                            <div className="text-xs text-ink-secondary">{formatSize(m.file_size)}</div>
                                        </div>
                                    </div>
                                </Td>
                                <Td muted>{m.download_limit}</Td>
                                <Td muted>{m.downloads_count}</Td>
                                <Td>
                                    <StatusPill status={m.is_released ? 'success' : 'pending'}>
                                        {m.is_released ? 'Released' : 'Scheduled'}
                                    </StatusPill>
                                </Td>
                                <Td align="right">
                                    <button onClick={() => remove(m)} className="text-ink-secondary hover:text-danger-fg">
                                        <Trash2 className="h-4 w-4" strokeWidth={1.75} />
                                    </button>
                                </Td>
                            </Tr>
                        ))}
                    </tbody>
                </Table>
            ) : (
                <Table>
                    <Thead>
                        <Th>File</Th>
                        <Th>Attempts each</Th>
                        <Th>Downloads</Th>
                        <Th>Release</Th>
                        <Th />
                    </Thead>
                    <tbody>
                        <tr>
                            <td colSpan={5}>
                                <TableEmpty title="No materials yet" description="Upload slides or handouts for attendees to download." />
                            </td>
                        </tr>
                    </tbody>
                </Table>
            )}

            <form onSubmit={upload} className="mt-5 border border-border p-4">
                <b className="text-sm font-medium text-ink">Upload material</b>
                <div className="mt-3.5 grid grid-cols-3 gap-3.5">
                    <Input label="Title (optional)" value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} placeholder="Uses filename if blank" />
                    <Input label="Attempts each" type="number" min="1" value={form.download_limit} onChange={(e) => setForm({ ...form, download_limit: e.target.value })} />
                    <Input label="Release (optional)" type="datetime-local" value={form.release_at} onChange={(e) => setForm({ ...form, release_at: e.target.value })} />
                </div>
                <div className="mt-3.5">
                    <input type="file" onChange={(e) => setForm({ ...form, file: e.target.files[0] ?? null })} className="text-sm text-ink-secondary" />
                </div>
                <Button type="submit" icon={Upload} variant="primary" className="mt-3.5" disabled={saving || !form.file}>
                    {saving ? 'Uploading…' : 'Upload material'}
                </Button>
            </form>
        </div>
    );
}
