import { useState } from 'react';
import { useForm, router } from '@inertiajs/react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import PageHeader from '@/Components/Console/PageHeader';
import Button from '@/Components/Console/Button';
import Input from '@/Components/Console/Input';
import Checkbox from '@/Components/Console/Checkbox';
import ConfirmModal from '@/Components/Console/ConfirmModal';

const LABELS = {
    openai: 'OpenAI',
    anthropic: 'Anthropic',
    google: 'Google',
};

function ProviderCard({ provider, config, value, onChange, onRequestRemove }) {
    return (
        <div className="rounded-lg border border-border bg-surface p-6">
            <div className="flex items-center justify-between">
                <h3 className="text-sm font-semibold text-ink">{LABELS[provider]}</h3>
                {config.configured && (
                    <span className={`text-xs font-medium ${config.is_active ? 'text-success-fg' : 'text-ink-secondary'}`}>
                        {config.is_active ? 'Active' : 'Inactive'}
                    </span>
                )}
            </div>

            <div className="mt-4">
                <Input
                    label="API key"
                    type="password"
                    placeholder={config.configured ? 'Leave blank to keep the current key' : `${LABELS[provider]} API key`}
                    value={value.api_key}
                    onChange={(event) => onChange({ ...value, api_key: event.target.value })}
                />
            </div>

            <div className="mt-3 flex items-center justify-between">
                <Checkbox
                    label={<span className="text-sm text-ink">Use this key</span>}
                    checked={value.is_active}
                    onChange={(event) => onChange({ ...value, is_active: event.target.checked })}
                />

                {config.configured && (
                    <button type="button" onClick={() => onRequestRemove(provider)} className="text-sm font-medium text-danger-fg hover:text-danger-fg">
                        Remove
                    </button>
                )}
            </div>
        </div>
    );
}

export default function Index({ providers, configs }) {
    const [removing, setRemoving] = useState(null);
    const { data, setData, put, processing } = useForm({
        configs: providers.map((provider) => ({
            provider,
            api_key: '',
            is_active: configs[provider]?.is_active ?? false,
        })),
    });

    const updateProvider = (provider, value) => {
        setData(
            'configs',
            data.configs.map((entry) => (entry.provider === provider ? { ...entry, ...value } : entry))
        );
    };

    const submit = (event) => {
        event.preventDefault();
        put(route('tenant.llm-config.update'));
    };

    return (
        <ConsoleLayout>
            <PageHeader title="LLM Configuration" />

            <form onSubmit={submit} className="max-w-2xl space-y-4 px-8 py-6">
                <p className="text-sm text-ink-secondary">
                    Bring your own API key for AI features. When a provider is inactive, MiConvener's shared quota is used instead.
                </p>

                {providers.map((provider) => (
                    <ProviderCard
                        key={provider}
                        provider={provider}
                        config={configs[provider]}
                        value={data.configs.find((entry) => entry.provider === provider)}
                        onChange={(value) => updateProvider(provider, value)}
                        onRequestRemove={setRemoving}
                    />
                ))}

                <Button type="submit" disabled={processing}>Save changes</Button>
            </form>

            <ConfirmModal
                open={removing !== null}
                onClose={() => setRemoving(null)}
                onConfirm={() => router.delete(route('tenant.llm-config.destroy', { provider: removing }), { onFinish: () => setRemoving(null) })}
                title="Remove API key"
                description={removing && `Remove your ${LABELS[removing]} key? MiConvener's shared quota will be used instead.`}
                confirmLabel="Remove"
                danger
            />
        </ConsoleLayout>
    );
}
