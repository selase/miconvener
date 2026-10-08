export async function artifactJson(response, fallback) {
    let data;
    try {
        data = await response.json();
    } catch {
        throw new Error(fallback);
    }
    if (!data || typeof data !== 'object' || Array.isArray(data)) throw new Error(fallback);
    if (!response.ok) {
        const error = new Error(
            response.status === 403
                ? 'You do not have permission to perform this action.'
                : data.message || fallback
        );
        error.fields = data.errors || {};
        throw error;
    }
    return data;
}

export async function artifactPdf(response, fallback) {
    if (!response.ok) await artifactJson(response, fallback);
    const type = response.headers?.get('Content-Type');
    if (!type || !type.toLowerCase().includes('application/pdf')) throw new Error(fallback);
    return response.blob();
}

export function downloadArtifact(blob, filename) {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    // Browsers must have time to consume the URL before it is released.
    setTimeout(() => URL.revokeObjectURL(url), 60000);
}
