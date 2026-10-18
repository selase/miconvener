import { useState } from 'react';
import { router } from '@inertiajs/react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import PageHeader from '@/Components/Console/PageHeader';
import Button from '@/Components/Console/Button';
import LibraryItemFields from './LibraryItemFields';
import ServiceNoteEditor from './ServiceNoteEditor';
import Modal from '@/Components/Console/Modal';
import { csrfFetchFormData } from '@/lib/csrfFetch';
import { communityRequest } from '@/lib/community';

export default function Library({ items, groups = [], canPublish, initialService = null }) {
    const [serviceEditor, setServiceEditor] = useState(canPublish ? initialService : null);
    const [editing, setEditing] = useState(null);
    const [form, setForm] = useState({});
    const [file, setFile] = useState(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [versions, setVersions] = useState(null);
    const open = (item) => {
        setFile(null);
        setError('');
        setEditing(item || {});
        setForm({
            title: item?.title || '',
            description: item?.description || '',
            plain_text: item?.plain_text || '',
            audience: item?.audience?.kind || 'all_members',
            group_ids: item?.audience?.group_ids || [],
            state: item?.state === 'published' ? 'published' : 'draft',
            edition: '',
            release_at: item?.release_at
                ? new Date(
                      new Date(item.release_at).getTime() -
                          new Date(item.release_at).getTimezoneOffset() * 60000
                  )
                      .toISOString()
                      .slice(0, 16)
                : '',
        });
    };
    const save = async (event) => {
        event.preventDefault();
        setBusy(true);
        setError('');
        const data = new FormData();
        ['title', 'description', 'plain_text', 'state', 'edition'].forEach((key) =>
            data.append(key, form[key])
        );
        data.append('release_at', form.release_at ? new Date(form.release_at).toISOString() : '');
        data.append('audience[kind]', form.audience);
        if (form.audience === 'groups')
            form.group_ids.forEach((id) => data.append('audience[group_ids][]', id));
        if (file) data.append('file', file);
        try {
            const response = await csrfFetchFormData(
                editing.id
                    ? route('tenant.community.library.update', { item: editing.id })
                    : route('tenant.community.library.store'),
                data
            );
            const body = await response.json();
            if (!response.ok)
                throw new Error(
                    Object.values(body.errors || {}).flat()[0] ||
                        body.message ||
                        'The document could not be saved.'
                );
            setEditing(null);
            router.reload({ only: ['items'], preserveScroll: true });
        } catch (failure) {
            setError(failure.message);
        } finally {
            setBusy(false);
        }
    };
    const withdraw = async (item) => {
        setBusy(true);
        setError('');
        try {
            await communityRequest(
                route('tenant.community.library.withdraw', { item: item.id }),
                'POST',
                {}
            );
            router.reload({ only: ['items'], preserveScroll: true });
        } catch (failure) {
            setError(failure.message);
        } finally {
            setBusy(false);
        }
    };
    const history = async (item, url = null) => {
        setError('');
        setVersions({ id: item.id, title: item.title, entries: null });
        try {
            const data = await communityRequest(
                url || route('tenant.community.library.history', { item: item.id })
            );
            setVersions({ id: item.id, title: item.title, entries: data.versions });
        } catch (failure) {
            setVersions(null);
            setError(failure.message);
        }
    };
    return (
        <ConsoleLayout>
            <PageHeader
                title="Library"
                actions={
                    canPublish && (
                        <div className="flex flex-wrap gap-3">
                            <Button onClick={() => open(null)}>Add document</Button>
                            <Button onClick={() => setServiceEditor({})}>Add service notes</Button>
                        </div>
                    )
                }
            />
            <div className="space-y-5 px-4 py-6 sm:px-8">
                {!canPublish && (
                    <p
                        role="status"
                        className="border border-border p-4 text-sm text-ink-secondary"
                    >
                        Existing documents remain available. Publishing requires the Library feature
                        and an eligible plan.
                    </p>
                )}
                {error && !editing && (
                    <p role="alert" className="text-danger-fg">
                        {error}
                    </p>
                )}
                {!items?.data?.length && (
                    <div className="border border-dashed border-border p-8 text-center">
                        <h2 className="font-semibold text-ink">Build your community library</h2>
                        <p className="mt-2 text-sm text-ink-secondary">
                            Share a constitution, minutes or service notes with the right members.
                        </p>
                        {canPublish && (
                            <Button className="mt-4" onClick={() => open(null)}>
                                Create your first document
                            </Button>
                        )}
                    </div>
                )}
                <div className="grid gap-4 lg:grid-cols-2">
                    {items?.data?.map((item) => (
                        <article
                            key={item.id}
                            className="min-w-0 space-y-4 border border-border bg-surface p-5"
                        >
                            <div>
                                <p className="text-xs uppercase tracking-wider text-ink-secondary">
                                    {item.kind === 'service_note' ? 'Service notes' : 'Document'} ·{' '}
                                    {item.state}
                                </p>
                                <h2 className="mt-2 break-words text-lg font-semibold text-ink">
                                    {item.title}
                                </h2>
                                {item.description && (
                                    <p className="mt-2 text-sm text-ink-secondary">
                                        {item.description}
                                    </p>
                                )}
                                {item.file && (
                                    <p className="mt-2 text-xs text-ink-secondary">
                                        {item.file.edition} · {Math.ceil(item.file.size / 1024)}KB
                                    </p>
                                )}
                            </div>
                            <div className="flex flex-wrap gap-3">
                                {canPublish && <Button onClick={() => open(item)}>Edit</Button>}
                                {item.file && (
                                    <>
                                        <a
                                            href={item.file.url}
                                            className="inline-flex min-h-11 items-center text-sm text-accent underline"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            Open document
                                        </a>
                                        <Button onClick={() => history(item)}>
                                            Edition history
                                        </Button>
                                    </>
                                )}
                                {item.state !== 'withdrawn' && (
                                    <Button disabled={busy} onClick={() => withdraw(item)}>
                                        Withdraw
                                    </Button>
                                )}
                            </div>
                        </article>
                    ))}
                </div>
                <div className="flex gap-4">
                    {items?.prev_page_url && (
                        <a className="min-h-11 text-accent underline" href={items.prev_page_url}>
                            Previous
                        </a>
                    )}
                    {items?.next_page_url && (
                        <a className="min-h-11 text-accent underline" href={items.next_page_url}>
                            More documents
                        </a>
                    )}
                </div>
            </div>
            {serviceEditor && (
                <ServiceNoteEditor
                    initial={serviceEditor}
                    groups={groups}
                    onClose={() => setServiceEditor(null)}
                    onSaved={() => {
                        setServiceEditor(null);
                        router.reload({ only: ['items'], preserveScroll: true });
                    }}
                />
            )}
            {editing && (
                <Modal
                    open
                    title={editing.id ? 'Edit document' : 'Add document'}
                    onClose={() => {
                        if (!busy) setEditing(null);
                    }}
                >
                    <form onSubmit={save} className="space-y-4">
                        {error && (
                            <p role="alert" className="text-danger-fg">
                                {error}
                            </p>
                        )}
                        <LibraryItemFields
                            form={form}
                            setForm={setForm}
                            setFile={setFile}
                            groups={groups}
                        />
                        <p className="text-xs text-ink-secondary">
                            Members need no dues balance to read. Approved representatives can read
                            for their member. Files stay private.
                        </p>
                        <Button type="submit" disabled={busy}>
                            Save document
                        </Button>
                    </form>
                </Modal>
            )}
            {versions && (
                <Modal
                    open
                    title={`Editions · ${versions.title}`}
                    onClose={() => setVersions(null)}
                >
                    {versions.entries ? (
                        <div className="space-y-4">
                            {versions.entries.data.map((version) => (
                                <p key={version.id}>
                                    <a
                                        className="text-accent underline"
                                        href={version.url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        {version.edition}
                                    </a>
                                </p>
                            ))}
                            {versions.entries.prev_page_url && (
                                <button
                                    className="min-h-11 text-accent underline"
                                    onClick={() =>
                                        history(versions, versions.entries.prev_page_url)
                                    }
                                >
                                    Previous editions
                                </button>
                            )}
                            {versions.entries.next_page_url && (
                                <button
                                    className="min-h-11 text-accent underline"
                                    onClick={() =>
                                        history(versions, versions.entries.next_page_url)
                                    }
                                >
                                    More editions
                                </button>
                            )}
                        </div>
                    ) : (
                        <p role="status">Loading editions…</p>
                    )}
                </Modal>
            )}
        </ConsoleLayout>
    );
}
