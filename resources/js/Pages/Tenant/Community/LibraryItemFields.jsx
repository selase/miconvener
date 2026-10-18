import Input from '@/Components/Console/Input';
import Select from '@/Components/Console/Select';

export default function LibraryItemFields({ form, setForm, setFile, groups }) {
    return (
        <>
            <Input
                label="Title"
                value={form.title}
                onChange={(event) => setForm({ ...form, title: event.target.value })}
                required
                maxLength={255}
            />
            <Input
                label="Description"
                value={form.description}
                onChange={(event) => setForm({ ...form, description: event.target.value })}
                maxLength={2000}
            />
            <label className="block text-sm text-ink">
                Notes
                <textarea
                    className="mt-1 block min-h-36 w-full border border-border bg-surface p-3 text-ink"
                    value={form.plain_text}
                    onChange={(event) => setForm({ ...form, plain_text: event.target.value })}
                    maxLength={50000}
                />
            </label>
            <Select
                label="Audience"
                value={form.audience}
                onChange={(event) => setForm({ ...form, audience: event.target.value })}
            >
                <option value="all_members">Active members</option>
                <option value="groups">Selected groups</option>
            </Select>
            {form.audience === 'groups' && (
                <fieldset className="space-y-2">
                    <legend className="text-sm text-ink">Groups</legend>
                    {groups.map((group) => (
                        <label
                            key={group.id}
                            className="flex min-h-11 items-center gap-2 text-sm text-ink"
                        >
                            <input
                                type="checkbox"
                                checked={form.group_ids.includes(group.id)}
                                onChange={(event) =>
                                    setForm({
                                        ...form,
                                        group_ids: event.target.checked
                                            ? [...form.group_ids, group.id]
                                            : form.group_ids.filter((id) => id !== group.id),
                                    })
                                }
                            />
                            {group.name}
                        </label>
                    ))}
                </fieldset>
            )}
            <Input
                label="Attachment"
                type="file"
                accept=".pdf,.docx,.jpg,.jpeg,.png,.webp"
                onChange={(event) => setFile(event.target.files?.[0] || null)}
                hint="PDF, DOCX or image up to20MB. A replacement is saved as a new immutable edition."
            />
            <Input
                label="Edition label"
                value={form.edition}
                onChange={(event) => setForm({ ...form, edition: event.target.value })}
                placeholder="For example, November2026"
                maxLength={120}
            />
            <Select
                label="Publication"
                value={form.state}
                onChange={(event) => setForm({ ...form, state: event.target.value })}
            >
                <option value="draft">Draft</option>
                <option value="published">Published</option>
            </Select>
            <Input
                label="Release time (your local time)"
                type="datetime-local"
                value={form.release_at}
                onChange={(event) => setForm({ ...form, release_at: event.target.value })}
                hint="Leave empty to release when published."
            />
        </>
    );
}
