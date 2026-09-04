import { Link } from '@inertiajs/react';

export default function PageHeader({ title, tabs, activeTab, actions }) {
    return (
        <div className="sticky top-0 z-5 bg-surface px-8 pt-7">
            <div className="flex items-center justify-between">
                <h1 className="text-[30px] font-bold leading-9 tracking-tight text-ink">{title}</h1>
                {actions && <div className="flex items-center gap-3">{actions}</div>}
            </div>

            {tabs && tabs.length > 0 && (
                <div className="mt-5 flex gap-5 border-b border-border">
                    {tabs.map((tab) => (
                        <Link
                            key={tab.key}
                            href={tab.href}
                            className={`relative pb-3 text-[15px] font-medium transition-colors duration-120 ease-out ${
                                activeTab === tab.key ? 'text-ink' : 'text-ink-secondary hover:text-ink'
                            }`}
                        >
                            {tab.label}
                            {activeTab === tab.key && (
                                <span className="absolute inset-x-0 -bottom-px h-0.5 bg-inverse" />
                            )}
                        </Link>
                    ))}
                </div>
            )}
        </div>
    );
}
