import Checkbox from '@/Components/Console/Checkbox';

function groupPermissions(permissions) {
    const groups = {};

    for (const permission of permissions) {
        const [verb, ...rest] = permission.split(' ');
        const noun = rest.join(' ') || 'general';

        if (!groups[noun]) {
            groups[noun] = [];
        }

        groups[noun].push({ verb, permission });
    }

    return Object.entries(groups).sort(([a], [b]) => a.localeCompare(b));
}

export default function PermissionPicker({ permissions, selected, onChange }) {
    const groups = groupPermissions(permissions);

    const toggle = (permission) => {
        if (selected.includes(permission)) {
            onChange(selected.filter((item) => item !== permission));
        } else {
            onChange([...selected, permission]);
        }
    };

    return (
        <div className="divide-y divide-border rounded-lg border border-border bg-surface">
            {groups.map(([noun, items]) => (
                <div key={noun} className="flex items-center justify-between px-5 py-3">
                    <span className="text-sm font-medium capitalize text-ink">{noun}</span>
                    <div className="flex gap-4">
                        {items.map(({ verb, permission }) => (
                            <Checkbox
                                key={permission}
                                labelClassName="flex items-center gap-1.5 text-sm text-ink-secondary"
                                label={<span className="capitalize">{verb}</span>}
                                checked={selected.includes(permission)}
                                onChange={() => toggle(permission)}
                            />
                        ))}
                    </div>
                </div>
            ))}
        </div>
    );
}
