import { useState } from 'react';
import { useForm, usePage, router } from '@inertiajs/react';
import { Plus, Copy } from 'lucide-react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import PageHeader from '@/Components/Console/PageHeader';
import Button from '@/Components/Console/Button';
import Input from '@/Components/Console/Input';
import StatusPill from '@/Components/Console/StatusPill';
import ConfirmModal from '@/Components/Console/ConfirmModal';
import { useToast } from '@/Components/Console/Toast';
import { Table, Thead, Th, Tr, Td, TableEmpty } from '@/Components/Console/Table';

export default function Index({ apiKeys }) {
    const { flash } = usePage().props;
    const showToast = useToast();
    const { data, setData, post, processing, errors, reset } = useForm({ name: '' });
    const [revoking, setRevoking] = useState(null);

    const submit = (event) => {
        event.preventDefault();
        post(route('tenant.api-keys.store'), { onSuccess: () => reset() });
    };

    const confirmRevoke = () => {
        router.delete(route('tenant.api-keys.destroy', { api_key: revoking.id }), {
            onFinish: () => setRevoking(null),
        });
    };

    const copyKey = () => {
        navigator.clipboard.writeText(flash.plainKey);
        showToast?.('Copied to clipboard');
    };

    return (
        <ConsoleLayout>
            <PageHeader title="API Keys" />

            <div className="space-y-6 px-8 py-6">
                {flash?.plainKey && (
                    <div className="rounded-lg border border-warning-fg/30 bg-warning-bg p-5">
                        <h3 className="text-sm font-semibold text-warning-fg">Copy your new key now</h3>
                        <p className="mt-1 text-sm text-warning-fg">
                            This is the only time it will be shown. Store it somewhere safe.
                        </p>
                        <div className="mt-3 flex items-center gap-2">
                            <code className="num flex-1 truncate rounded-md border border-warning-fg/30 bg-surface px-3 py-2 text-sm text-ink">
                                {flash.plainKey}
                            </code>
                            <Button icon={Copy} onClick={copyKey}>Copy</Button>
                        </div>
                    </div>
                )}

                <form onSubmit={submit} className="flex items-end gap-3 rounded-lg border border-border p-5">
                    <div className="flex-1">
                        <Input
                            label="New key name"
                            type="text"
                            placeholder="e.g. Production integration"
                            value={data.name}
                            onChange={(event) => setData('name', event.target.value)}
                            error={errors.name}
                        />
                    </div>
                    <Button type="submit" disabled={processing} icon={Plus}>Generate key</Button>
                </form>

                <Table>
                    <Thead>
                        <Th>Name</Th>
                        <Th>Key</Th>
                        <Th>Created by</Th>
                        <Th>Last used</Th>
                        <Th>Status</Th>
                        <Th />
                    </Thead>
                    <tbody>
                        {apiKeys.map((key) => (
                            <Tr key={key.id}>
                                <Td>{key.name}</Td>
                                <Td muted><code className="num">sk_••••{key.key_hint}</code></Td>
                                <Td muted>{key.created_by ?? '—'}</Td>
                                <Td muted numeric>{key.last_used_at ?? 'Never'}</Td>
                                <Td>
                                    {key.revoked_at ? (
                                        <StatusPill status="neutral">Revoked {key.revoked_at}</StatusPill>
                                    ) : (
                                        <StatusPill status="success">Active</StatusPill>
                                    )}
                                </Td>
                                <Td>
                                    {!key.revoked_at && (
                                        <button
                                            type="button"
                                            onClick={() => setRevoking(key)}
                                            className="text-sm font-medium text-danger-fg hover:underline"
                                        >
                                            Revoke
                                        </button>
                                    )}
                                </Td>
                            </Tr>
                        ))}
                        {apiKeys.length === 0 && (
                            <tr>
                                <td colSpan={6}>
                                    <TableEmpty title="No API keys yet" />
                                </td>
                            </tr>
                        )}
                    </tbody>
                </Table>
            </div>

            <ConfirmModal
                open={revoking !== null}
                onClose={() => setRevoking(null)}
                onConfirm={confirmRevoke}
                title="Revoke API key"
                description={revoking && `Revoke "${revoking.name}"? Any integration using it will stop working immediately.`}
                confirmLabel="Revoke"
                danger
            />
        </ConsoleLayout>
    );
}
