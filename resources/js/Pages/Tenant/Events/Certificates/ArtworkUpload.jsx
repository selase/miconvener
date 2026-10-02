import { ImagePlus, Trash2 } from 'lucide-react';

export default function ArtworkUpload({
    label,
    accept = 'image/png,image/jpeg',
    file,
    existing,
    onChange,
    onRemove,
}) {
    return (
        <div className="rounded-lg border border-dashed border-slate-300 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-900/50">
            <div className="flex items-center justify-between gap-3">
                <div>
                    <p className="text-xs font-semibold text-slate-800 dark:text-slate-200">
                        {label}
                    </p>
                    <p className="mt-0.5 text-[11px] text-slate-500">PNG or JPEG, up to 10 MB</p>
                </div>
                {(file || existing) && (
                    <button
                        type="button"
                        onClick={onRemove}
                        className="rounded-md p-2 text-rose-600 hover:bg-rose-50 focus:outline-none focus:ring-2 focus:ring-rose-500"
                        aria-label={`Remove ${label}`}
                    >
                        <Trash2 className="h-4 w-4" />
                    </button>
                )}
            </div>
            <label className="mt-3 flex cursor-pointer items-center justify-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:border-indigo-400 focus-within:ring-2 focus-within:ring-indigo-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">
                <ImagePlus className="h-4 w-4" />
                {file ? file.name : existing ? 'Replace image' : 'Choose image'}
                <input
                    type="file"
                    className="sr-only"
                    accept={accept}
                    onChange={(event) => onChange(event.target.files?.[0] || null)}
                />
            </label>
        </div>
    );
}
