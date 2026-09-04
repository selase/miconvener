import { Search } from 'lucide-react';

export default function SearchInput({ className = '', ...props }) {
    return (
        <div className={`flex h-control min-w-70 items-center gap-2.5 rounded-md bg-surface-sunken px-3.5 text-ink-secondary ${className}`}>
            <Search className="h-4 w-4 shrink-0" strokeWidth={1.75} />
            <input {...props} className="w-full bg-transparent text-sm text-ink outline-none placeholder:text-ink-secondary" />
        </div>
    );
}
