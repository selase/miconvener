import { cloneElement, useId } from 'react';

function Field({ label, error, hint, children }) {
    const id = useId();
    return (
        <div>
            <label htmlFor={id} className="mb-1.5 block text-sm font-medium text-ink">
                {label}
            </label>
            {cloneElement(children, { id })}
            {hint && !error && <p className="mt-1 text-sm text-ink-secondary">{hint}</p>}
            {error && <p className="mt-1 text-sm text-danger-fg">{error}</p>}
        </div>
    );
}

const inputClasses =
    'w-full rounded-lg border border-border px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent';

export default function TeamMemberForm({
    data,
    setData,
    errors,
    roles,
    statuses,
    roleLocked = false,
    editing = false,
}) {
    return (
        <div className="space-y-6">
            <Field
                label="Email"
                error={errors.email}
                hint={
                    editing
                        ? 'Account details belong to this person. Only their access here can be changed.'
                        : 'They can accept with an existing account or create their own.'
                }
            >
                <input
                    type="email"
                    value={data.email}
                    disabled={editing}
                    onChange={(event) => setData('email', event.target.value)}
                    className={inputClasses}
                    required
                />
            </Field>

            <div className="grid grid-cols-2 gap-4">
                <Field
                    label="Role"
                    error={errors.role}
                    hint={
                        roleLocked
                            ? 'Your own role can only be changed by another administrator.'
                            : undefined
                    }
                >
                    <select
                        value={data.role}
                        onChange={(event) => setData('role', event.target.value)}
                        disabled={roleLocked}
                        className={inputClasses}
                    >
                        <option value="">Select a role</option>
                        {roles.map((role) => (
                            <option key={role.id} value={role.id}>
                                {role.name}
                            </option>
                        ))}
                    </select>
                </Field>

                {editing && (
                    <Field label="Organisation access" error={errors.status}>
                        <select
                            value={data.status}
                            onChange={(event) => setData('status', event.target.value)}
                            className={inputClasses}
                        >
                            {statuses.map((status) => (
                                <option key={status} value={status}>
                                    {status.charAt(0).toUpperCase() + status.slice(1)}
                                </option>
                            ))}
                        </select>
                    </Field>
                )}
            </div>
        </div>
    );
}
