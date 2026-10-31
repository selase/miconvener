import { useCallback, useEffect, useState } from 'react';
import Button from '@/Components/Console/Button';
import Input from '@/Components/Console/Input';
import Modal from '@/Components/Console/Modal';
import { sendJson } from '@/lib/dues';

function MemberPicker({ group, onClose, onChanged }) {
    const [page, setPage] = useState(null);
    const [search, setSearch] = useState('');
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);
    const load = useCallback(async (url) => {
        setBusy(true);
        const result = await sendJson('GET', url);
        setBusy(false);
        if (result.ok) {
            setPage(result.body.members);
            setError(null);
        } else setError(result.message);
    }, []);
    const base = route('tenant.community.groups.member-options', { group: group.id });
    useEffect(() => {
        load(base);
    }, [base, load]);
    const toggle = async (member) => {
        setBusy(true);
        const result = await sendJson(
            'PATCH',
            route('tenant.community.groups.members.patch', { group: group.id }),
            { action: member.selected ? 'detach' : 'attach', member_ids: [member.id] }
        );
        setBusy(false);
        if (!result.ok) {
            setError(result.message);
            return;
        }
        setPage((current) => ({
            ...current,
            data: current.data.map((row) =>
                row.id === member.id ? { ...row, selected: !row.selected } : row
            ),
        }));
        onChanged();
    };
    return (
        <Modal
            open
            onClose={onClose}
            title={`Members · ${group.name}`}
            className="max-w-xl"
            fullScreenOnMobile
        >
            <p className="mb-4 text-sm text-ink-secondary">
                Changes save one member at a time. Other group members stay as they are.
            </p>
            <form
                className="mb-4 flex items-end gap-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    load(`${base}?search=${encodeURIComponent(search)}`);
                }}
            >
                <Input
                    label="Search members"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    maxLength={100}
                />
                <Button type="submit" disabled={busy}>
                    Search
                </Button>
            </form>
            {error && (
                <p role="alert" className="mb-3 text-danger-fg">
                    {error} <button onClick={() => load(base)}>Retry</button>
                </p>
            )}
            {!page && !error && <p role="status">Loading members…</p>}
            <ul className="divide-y divide-border">
                {page?.data.map((member) => (
                    <li key={member.id} className="flex items-center justify-between gap-3 py-3">
                        <span className="min-w-0 break-words text-ink">
                            {member.name}
                            <span className="ml-2 text-xs text-ink-secondary">{member.status}</span>
                        </span>
                        <Button type="button" disabled={busy} onClick={() => toggle(member)}>
                            {member.selected ? 'Remove' : 'Add'}
                        </Button>
                    </li>
                ))}
            </ul>
            {page?.data.length === 0 && <p>No members match this search.</p>}
            <div className="mt-4 flex flex-wrap gap-3">
                {page?.prev_page_url && (
                    <Button type="button" disabled={busy} onClick={() => load(page.prev_page_url)}>
                        Previous
                    </Button>
                )}
                {page?.next_page_url && (
                    <Button type="button" disabled={busy} onClick={() => load(page.next_page_url)}>
                        Next
                    </Button>
                )}
                <Button type="button" onClick={onClose}>
                    Done
                </Button>
            </div>
        </Modal>
    );
}

export default function GroupsPanel() {
    const [groups, setGroups] = useState(null);
    const [next, setNext] = useState(null);
    const [error, setError] = useState(null);
    const [editor, setEditor] = useState(null);
    const [picker, setPicker] = useState(null);
    const [archive, setArchive] = useState(null);
    const [busy, setBusy] = useState(false);
    const load = useCallback(async (url, append = false) => {
        const result = await sendJson('GET', url ?? route('tenant.community.groups.index'));
        if (result.ok) {
            setGroups((current) =>
                append ? [...current, ...result.body.groups] : result.body.groups
            );
            setNext(result.body.next_page_url);
            setError(null);
        } else setError(result.message);
    }, []);
    useEffect(() => {
        load();
    }, [load]);
    const save = async (e) => {
        e.preventDefault();
        setBusy(true);
        const result = await sendJson(
            editor.id ? 'PATCH' : 'POST',
            route(
                editor.id ? 'tenant.community.groups.update' : 'tenant.community.groups.store',
                editor.id ? { group: editor.id } : {}
            ),
            { name: editor.name, description: editor.description }
        );
        setBusy(false);
        if (!result.ok) {
            setError(Object.values(result.errors ?? {}).join(' ') || result.message);
            return;
        }
        setEditor(null);
        load();
    };
    const confirmArchive = async () => {
        setBusy(true);
        const result = await sendJson(
            'POST',
            route('tenant.community.groups.archive', { group: archive.id })
        );
        setBusy(false);
        if (result.ok) {
            setArchive(null);
            load();
        } else setError(result.message);
    };
    return (
        <section className="px-4 py-6 sm:px-8" aria-label="Groups">
            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <p className="max-w-xl text-sm text-ink-secondary">
                    Bring people together in simple groups, such as a choir, committee or volunteer
                    team. A member can belong to several groups.
                </p>
                <Button
                    type="button"
                    variant="primary"
                    onClick={() => {
                        setError(null);
                        setEditor({ name: '', description: '' });
                    }}
                >
                    Create group
                </Button>
            </div>
            {error && !editor && !archive && (
                <p role="alert" className="mb-4 text-danger-fg">
                    {error} <button onClick={() => load()}>Retry</button>
                </p>
            )}
            {!groups && !error && <p role="status">Loading groups…</p>}
            {groups?.length === 0 && (
                <div className="border border-dashed border-border p-8">
                    <h2 className="text-lg font-semibold text-ink">No groups yet</h2>
                    <p className="mt-2 text-sm text-ink-secondary">
                        Create your first group, then add its members.
                    </p>
                </div>
            )}
            <ul className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                {groups?.map((group) => (
                    <li key={group.id} className="min-w-0 border border-border bg-surface p-5">
                        <div className="flex items-start justify-between gap-3">
                            <h2 className="break-words font-semibold text-ink">{group.name}</h2>
                            {group.archived_at && (
                                <span className="text-xs text-ink-secondary">Archived</span>
                            )}
                        </div>
                        <p className="mt-2 whitespace-pre-wrap break-words text-sm text-ink-secondary">
                            {group.description}
                        </p>
                        <p className="mt-4 text-sm text-ink-secondary">
                            {group.members_count} members
                        </p>
                        {!group.archived_at && (
                            <div className="mt-4 flex flex-wrap gap-2">
                                <Button type="button" onClick={() => setPicker(group)}>
                                    Manage members
                                </Button>
                                <Button
                                    type="button"
                                    onClick={() => {
                                        setError(null);
                                        setEditor({
                                            ...group,
                                            description: group.description ?? '',
                                        });
                                    }}
                                >
                                    Edit
                                </Button>
                                <Button
                                    type="button"
                                    onClick={() => {
                                        setError(null);
                                        setArchive(group);
                                    }}
                                >
                                    Archive
                                </Button>
                            </div>
                        )}
                    </li>
                ))}
            </ul>
            {next && (
                <div className="mt-5">
                    <Button type="button" onClick={() => load(next, true)}>
                        More groups
                    </Button>
                </div>
            )}
            {editor && (
                <Modal
                    open
                    onClose={() => !busy && setEditor(null)}
                    title={editor.id ? 'Edit group' : 'Create group'}
                >
                    <form className="space-y-4" onSubmit={save}>
                        <Input
                            label="Group name"
                            maxLength={120}
                            required
                            value={editor.name}
                            onChange={(e) => setEditor({ ...editor, name: e.target.value })}
                        />
                        <Input
                            label="Description"
                            maxLength={1000}
                            value={editor.description}
                            onChange={(e) => setEditor({ ...editor, description: e.target.value })}
                        />
                        {error && (
                            <p role="alert" className="text-sm text-danger-fg">
                                {error}
                            </p>
                        )}
                        <div className="flex gap-3">
                            <Button type="submit" variant="primary" disabled={busy}>
                                {busy ? 'Saving…' : 'Save group'}
                            </Button>
                            <Button type="button" disabled={busy} onClick={() => setEditor(null)}>
                                Cancel
                            </Button>
                        </div>
                    </form>
                </Modal>
            )}
            {picker && (
                <MemberPicker
                    group={picker}
                    onClose={() => setPicker(null)}
                    onChanged={() => load()}
                />
            )}
            {archive && (
                <Modal open onClose={() => !busy && setArchive(null)} title="Archive group">
                    <p className="mb-4 text-sm text-ink-secondary">
                        Archive {archive.name}? Members and message history are kept. This group
                        will no longer be available for new announcements.
                    </p>
                    {error && <p role="alert">{error}</p>}
                    <div className="flex gap-3">
                        <Button type="button" disabled={busy} onClick={confirmArchive}>
                            Archive group
                        </Button>
                        <Button type="button" disabled={busy} onClick={() => setArchive(null)}>
                            Cancel
                        </Button>
                    </div>
                </Modal>
            )}
        </section>
    );
}
