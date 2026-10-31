import { useState } from 'react';
export default function AttendeeMe({
    identity,
    preferences,
    onPreference,
    onRemove,
    onSignOut,
    onVerify,
    onPage,
    busy = false,
    status = 'success',
}) {
    const [consents, setConsents] = useState({});
    return (
        <div className="max-w-2xl">
            <p className="text-xs uppercase tracking-widest text-accent">
                Your account on this device
            </p>
            <h1 className="mt-2 text-3xl font-medium">A space that’s yours.</h1>
            <p className="mt-3 text-sm text-ink-secondary">
                Email is your primary sign-in. Phone verification is independent and never reveals
                someone else’s membership.
            </p>
            <section className="mt-7 divide-y divide-border border border-border bg-surface px-5">
                {[
                    ['email', 'Email'],
                    ['phone', 'Phone'],
                ].map(([key, label]) => (
                    <div
                        key={key}
                        className="flex flex-wrap items-center justify-between gap-3 py-5"
                    >
                        <div>
                            <h2 className="text-sm font-medium">{label}</h2>
                            <p className="mt-1 text-sm text-ink-secondary">
                                {identity?.[key]?.masked || 'Not verified on this device'}
                            </p>
                        </div>
                        {identity?.[key] ? (
                            <button
                                disabled={busy}
                                type="button"
                                onClick={() => onRemove(key)}
                                className="min-h-11 text-xs underline"
                            >
                                Remove {key} from this device
                            </button>
                        ) : (
                            <button
                                type="button"
                                onClick={() => onVerify(key)}
                                className="min-h-11 text-sm text-accent underline"
                            >
                                Verify {key}
                            </button>
                        )}
                    </div>
                ))}
            </section>
            <section className="mt-7">
                <h2 className="text-xl font-medium">Announcements, your way.</h2>
                <p className="mt-2 text-sm text-ink-secondary">
                    Choose organisation announcements. Sign-in codes, receipts and essential service
                    notices stay available.
                </p>
                {status !== 'success' ? (
                    <p
                        role={status === 'error' ? 'alert' : 'status'}
                        className="mt-4 border border-border p-5 text-sm text-ink-secondary"
                    >
                        {status === 'error'
                            ? 'Your announcement preferences could not be loaded. Use Retry to reconnect.'
                            : identity?.email || identity?.phone
                              ? 'Loading your announcement preferences…'
                              : 'Verify a contact to manage your announcements.'}
                    </p>
                ) : preferences?.data?.length ? (
                    preferences.data.map((row) => (
                        <article
                            key={row.tenant_slug}
                            className="mt-4 border border-border bg-surface p-5"
                        >
                            <h3 className="font-medium">{row.name}</h3>
                            {Object.entries(row.channels).map(([channel, value]) => {
                                const key = `${row.tenant_slug}:${channel}`;
                                return (
                                    <div key={channel} className="mt-4 text-sm">
                                        <p>{channel === 'email' ? 'Email' : 'SMS'} announcements</p>
                                        {!value.marketing_enabled && (
                                            <label className="my-2 flex min-h-11 items-start gap-2 text-xs text-ink-secondary">
                                                <input
                                                    type="checkbox"
                                                    checked={Boolean(consents[key])}
                                                    onChange={(event) =>
                                                        setConsents({
                                                            ...consents,
                                                            [key]: event.target.checked,
                                                        })
                                                    }
                                                    className="mt-1"
                                                />
                                                I agree to receive{' '}
                                                {channel === 'email' ? 'email' : 'SMS'}{' '}
                                                announcements from {row.name}.
                                            </label>
                                        )}
                                        <button
                                            type="button"
                                            disabled={
                                                busy || (!value.marketing_enabled && !consents[key])
                                            }
                                            onClick={() =>
                                                onPreference(
                                                    row.tenant_slug,
                                                    channel,
                                                    !value.marketing_enabled
                                                )
                                            }
                                            className="min-h-11 text-xs underline disabled:opacity-40"
                                        >
                                            {value.marketing_enabled ? 'Stop' : 'Receive'}{' '}
                                            {channel === 'email' ? 'email' : 'SMS'} announcements
                                        </button>
                                    </div>
                                );
                            })}
                        </article>
                    ))
                ) : (
                    <p className="mt-4 border border-border p-5 text-sm text-ink-secondary">
                        Follow or join an organisation to manage its announcements here.
                    </p>
                )}
                {preferences?.last_page > 1 && (
                    <div className="mt-4 flex gap-4 text-sm">
                        <button
                            type="button"
                            disabled={preferences.current_page <= 1}
                            onClick={() => onPage(preferences.current_page - 1)}
                            className="min-h-11 underline disabled:opacity-40"
                        >
                            Previous organisations
                        </button>
                        <button
                            type="button"
                            disabled={preferences.current_page >= preferences.last_page}
                            onClick={() => onPage(preferences.current_page + 1)}
                            className="min-h-11 underline disabled:opacity-40"
                        >
                            Next organisations
                        </button>
                    </div>
                )}
            </section>
            <button
                type="button"
                disabled={busy}
                onClick={onSignOut}
                className="mt-7 min-h-11 border border-border px-5 text-sm"
            >
                Sign out of this device
            </button>
            <p className="mt-3 text-xs text-ink-secondary">
                Removes both verified contacts and saved private passes from this browser.
            </p>
        </div>
    );
}
