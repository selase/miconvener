export default function ArtifactErrors({ error }) {
    if (!error) return null;
    return (
        <div
            role="alert"
            className="mb-4 rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-200"
        >
            <p>{error.message || 'The request could not be completed. Please try again.'}</p>
            {Object.entries(error.fields || {}).map(([field, messages]) => (
                <p key={field} className="mt-1" data-error-field={field}>
                    <strong>{field.replaceAll('_', ' ').replaceAll('.', ' › ')}:</strong>{' '}
                    {Array.isArray(messages) ? messages.join(' ') : messages}
                </p>
            ))}
        </div>
    );
}
