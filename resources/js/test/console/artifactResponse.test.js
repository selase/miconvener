import { describe, expect, it } from 'vitest';
import { artifactJson, artifactPdf } from '../../lib/artifactResponse';

describe('artifact response errors', () => {
    it.each([null, [], 'unexpected'])('uses the friendly fallback for invalid JSON shapes (%s)', async (data) => {
        await expect(artifactJson({ ok: false, json: async () => data }, 'Could not save the design.'))
            .rejects.toThrow('Could not save the design.');
    });
    it.each([null, 'text/html', 'application/json'])('rejects successful responses without a PDF content type (%s)', async (type) => {
        await expect(artifactPdf({ ok: true, headers: { get: () => type }, blob: async () => new Blob(['unexpected']) }, 'Could not download the PDF.'))
            .rejects.toThrow('Could not download the PDF.');
    });
});
