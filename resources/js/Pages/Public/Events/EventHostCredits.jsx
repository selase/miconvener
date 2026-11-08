export default function EventHostCredits({ hosts = [], byline = false }) {
    if (hosts.length === 0) return null;
    return (
        <div className="min-w-0">
            <p
                className={
                    byline
                        ? 'text-[10px] text-ink-tertiary'
                        : 'mb-5 text-xs uppercase tracking-[0.12em] text-ink-tertiary'
                }
            >
                Hosted by
            </p>
            <ul
                className={
                    byline
                        ? 'flex flex-wrap gap-x-3 gap-y-1 text-[13px] font-medium'
                        : 'grid gap-5 sm:grid-cols-2'
                }
            >
                {hosts.map((host) => (
                    <li key={host.id} className="flex min-w-0 items-center gap-3">
                        {!byline && host.logo_url && (
                            <img
                                src={host.logo_url}
                                alt=""
                                loading="lazy"
                                className="h-14 w-14 shrink-0 border border-border bg-white object-contain p-1"
                            />
                        )}
                        {host.website_url ? (
                            <a
                                className="min-w-0 break-words hover:text-accent"
                                href={host.website_url}
                                target="_blank"
                                rel="noopener noreferrer nofollow"
                            >
                                {host.name}
                            </a>
                        ) : (
                            <span className="min-w-0 break-words">{host.name}</span>
                        )}
                    </li>
                ))}
            </ul>
        </div>
    );
}
