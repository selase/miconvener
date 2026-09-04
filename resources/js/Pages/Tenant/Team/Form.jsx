function Field({ label, error, children }) {
    return (
        <div>
            <label className="mb-1.5 block text-sm font-medium text-ink">{label}</label>
            {children}
            {error && <p className="mt-1 text-sm text-danger-fg">{error}</p>}
        </div>
    );
}

const inputClasses =
    'w-full rounded-lg border border-border px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent';

export default function TeamMemberForm({ data, setData, errors, roles, statuses }) {
    return (
        <div className="space-y-6">
            <div className="grid grid-cols-2 gap-4">
                <Field label="First name" error={errors.first_name}>
                    <input
                        type="text"
                        value={data.first_name}
                        onChange={(event) => setData('first_name', event.target.value)}
                        className={inputClasses}
                    />
                </Field>

                <Field label="Last name" error={errors.last_name}>
                    <input
                        type="text"
                        value={data.last_name}
                        onChange={(event) => setData('last_name', event.target.value)}
                        className={inputClasses}
                    />
                </Field>
            </div>

            <Field label="Email" error={errors.email}>
                <input
                    type="email"
                    value={data.email}
                    onChange={(event) => setData('email', event.target.value)}
                    className={inputClasses}
                />
            </Field>

            <Field label="Phone number" error={errors.phone_no}>
                <input
                    type="text"
                    placeholder="+233 20 000 0000"
                    value={data.phone_no}
                    onChange={(event) => setData('phone_no', event.target.value)}
                    className={inputClasses}
                />
            </Field>

            <div className="grid grid-cols-2 gap-4">
                <Field label="Role" error={errors.role}>
                    <select
                        value={data.role}
                        onChange={(event) => setData('role', event.target.value)}
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

                <Field label="Status" error={errors.status}>
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
            </div>
        </div>
    );
}
