import { useEffect, useRef, useState } from 'react';
import { Download, RotateCcw } from 'lucide-react';
import Button from '@/Components/Console/Button';
import Checkbox from '@/Components/Console/Checkbox';
import Input from '@/Components/Console/Input';
import ArtworkUpload from './ArtworkUpload';
import ArtifactLayoutPreview from './ArtifactLayoutPreview';
import ArtifactLayoutControls from './ArtifactLayoutControls';
import ArtifactFontLibrary, { ArtifactFontStyles, useArtifactFonts } from './ArtifactFonts';
import { artifactPdf } from '@/lib/artifactResponse';
import ArtifactErrors from './ArtifactErrors';
import { csrfFetchFormData } from '@/lib/csrfFetch';

export default function CertificateDesignEditor({
    form,
    layoutDefaults = {},
    setForm,
    template,
    eventId,
    saving,
    onSave,
    onCancel,
    fieldErrors = {},
}) {
    const { fonts, setFonts, error: fontError } = useArtifactFonts(eventId);
    const mounted = useRef(true);
    useEffect(() => {
        mounted.current = true;
        return () => {
            mounted.current = false;
        };
    }, []);
    const [backgroundUrl, setBackgroundUrl] = useState(null);
    const [signatureUrl, setSignatureUrl] = useState(null);
    const [pdfPending, setPdfPending] = useState(false);
    const [pdfError, setPdfError] = useState(null);
    const [pdfWarning, setPdfWarning] = useState(null);
    const [pdfUrl, setPdfUrl] = useState(null);
    useEffect(() => {
        if (!form.signature) {
            setSignatureUrl(null);
            return undefined;
        }
        const url = URL.createObjectURL(form.signature);
        setSignatureUrl(url);
        return () => URL.revokeObjectURL(url);
    }, [form.signature]);
    useEffect(
        () => () => {
            if (pdfUrl) URL.revokeObjectURL(pdfUrl);
        },
        [pdfUrl]
    );
    const testPdf = async () => {
        setPdfPending(true);
        setPdfError(null);
        setPdfWarning(null);
        const payload = new FormData();
        for (const key of [
            'role',
            'design_mode',
            'orientation',
            'page_size',
            'title',
            'body_template',
            'issuer_name',
            'issuer_title',
            'default_cpd_hours',
            'show_qr',
            'show_cpd_hours',
            'layout',
            'background',
            'signature',
            'remove_background',
            'remove_signature',
        ]) {
            const value = form[key];
            if (value === null || value === undefined) continue;
            payload.append(
                key,
                key === 'layout'
                    ? JSON.stringify(value)
                    : typeof value === 'boolean'
                      ? value
                          ? '1'
                          : '0'
                      : value
            );
        }
        try {
            const response = await csrfFetchFormData(
                route('tenant.events.certificates.templates.draft-preview', { event: eventId }),
                payload
            );
            const blob = await artifactPdf(
                response,
                'The test PDF could not be generated. Please try again.'
            );
            if (!mounted.current) return;
            setPdfWarning(response.headers?.get('X-Certificate-Preview-Warning') || null);
            const url = URL.createObjectURL(blob);
            setPdfUrl(url);
            window.open(url, '_blank', 'noopener,noreferrer');
        } catch (reason) {
            if (mounted.current) setPdfError(reason);
        } finally {
            if (mounted.current) setPdfPending(false);
        }
    };
    const update = (key, value) => setForm((current) => ({ ...current, [key]: value }));
    const resetLayout = () => update('layout', structuredClone(layoutDefaults));
    useEffect(() => {
        if (!form.background) {
            setBackgroundUrl(null);
            return undefined;
        }
        const url = URL.createObjectURL(form.background);
        setBackgroundUrl(url);
        return () => URL.revokeObjectURL(url);
    }, [form.background]);

    return (
        <form
            onSubmit={onSave}
            className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(320px,0.9fr)]"
        >
            <div className="space-y-4">
                <div className="rounded-lg bg-indigo-50 p-3 text-xs text-indigo-900 dark:bg-indigo-950/40 dark:text-indigo-200">
                    Changes apply only to certificates issued after you save. Existing credentials
                    keep their original design.
                </div>
                <div className="grid grid-cols-2 gap-2 rounded-lg bg-slate-100 p-1 dark:bg-slate-800">
                    {['miconvener', 'custom_background'].map((mode) => (
                        <button
                            key={mode}
                            type="button"
                            onClick={() => update('design_mode', mode)}
                            className={`rounded-md px-3 py-2 text-xs font-semibold focus:outline-none focus:ring-2 focus:ring-indigo-500 ${form.design_mode === mode ? 'bg-white text-indigo-700 shadow-sm dark:bg-slate-700 dark:text-indigo-300' : 'text-slate-600 dark:text-slate-300'}`}
                        >
                            {mode === 'miconvener' ? 'MiConvener design' : 'My artwork'}
                        </button>
                    ))}
                </div>
                {form.design_mode === 'custom_background' && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        <ArtworkUpload
                            label="Background artwork"
                            file={form.background}
                            existing={template?.background_path && !form.remove_background}
                            onChange={(file) =>
                                setForm((current) => ({
                                    ...current,
                                    background: file,
                                    remove_background: false,
                                }))
                            }
                            onRemove={() =>
                                setForm((current) => ({
                                    ...current,
                                    background: null,
                                    remove_background: true,
                                }))
                            }
                        />
                        <ArtworkUpload
                            label="Signature"
                            file={form.signature}
                            existing={template?.signature_path && !form.remove_signature}
                            onChange={(file) =>
                                setForm((current) => ({
                                    ...current,
                                    signature: file,
                                    remove_signature: false,
                                }))
                            }
                            onRemove={() =>
                                setForm((current) => ({
                                    ...current,
                                    signature: null,
                                    remove_signature: true,
                                }))
                            }
                        />
                    </div>
                )}
                <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300">
                    Certificate title
                    <Input
                        required
                        aria-label="Certificate title"
                        error={fieldErrors.title?.join(' ')}
                        value={form.title}
                        onChange={(event) => update('title', event.target.value)}
                    />
                </label>
                <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300">
                    Body wording
                    <textarea
                        rows={4}
                        className="mt-1 w-full rounded-lg border border-slate-300 bg-white p-2.5 text-xs text-slate-900 focus:ring-2 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                        value={form.body_template}
                        onChange={(event) => update('body_template', event.target.value)}
                    />
                </label>
                <div className="grid gap-3 sm:grid-cols-2">
                    <label className="text-xs font-semibold text-slate-700 dark:text-slate-300">
                        Issuer name
                        <Input
                            error={fieldErrors.issuer_name?.join(' ')}
                            value={form.issuer_name}
                            onChange={(event) => update('issuer_name', event.target.value)}
                        />
                    </label>
                    <label className="text-xs font-semibold text-slate-700 dark:text-slate-300">
                        Issuer title
                        <Input
                            error={fieldErrors.issuer_title?.join(' ')}
                            value={form.issuer_title}
                            onChange={(event) => update('issuer_title', event.target.value)}
                        />
                    </label>
                </div>
                <Input
                    label="Default CPD/CME hours"
                    aria-label="Default CPD/CME hours"
                    type="number"
                    min={0}
                    max={1000}
                    step="any"
                    error={fieldErrors.default_cpd_hours?.join(' ')}
                    value={form.default_cpd_hours}
                    onChange={(event) => update('default_cpd_hours', event.target.value)}
                />
                {form.design_mode === 'custom_background' && (
                    <>
                        <ArtifactFontStyles fonts={fonts} />
                        {fontError && (
                            <p role="alert" className="text-xs text-rose-600">
                                {fontError}
                            </p>
                        )}
                        <ArtifactFontLibrary eventId={eventId} fonts={fonts} setFonts={setFonts} />
                        <ArtifactLayoutControls
                            fonts={fonts}
                            layout={form.layout || {}}
                            onChange={(layout) => update('layout', layout)}
                            requiredFields={['recipient_name', 'verification_code']}
                        />
                    </>
                )}
                <div className="flex flex-wrap gap-5">
                    <div className="flex items-center gap-2 text-xs">
                        <Checkbox
                            label="Verification QR"
                            checked={form.show_qr}
                            onChange={(event) => update('show_qr', event.target.checked)}
                        />
                    </div>
                    <div className="flex items-center gap-2 text-xs">
                        <Checkbox
                            label="CPD/CME hours"
                            checked={form.show_cpd_hours}
                            onChange={(event) => update('show_cpd_hours', event.target.checked)}
                        />
                    </div>
                </div>
            </div>
            <aside className="space-y-3 lg:sticky lg:top-0 lg:self-start">
                <ArtifactLayoutPreview
                    form={form}
                    layoutDefaults={layoutDefaults}
                    signatureUrl={
                        signatureUrl ||
                        (!form.remove_signature && template?.signature_path
                            ? route('tenant.events.certificates.templates.artwork', {
                                  event: eventId,
                                  template: template.id,
                                  type: 'signature',
                              })
                            : null)
                    }
                    backgroundUrl={
                        backgroundUrl ||
                        (!form.remove_background && template?.background_path
                            ? route('tenant.events.certificates.templates.artwork', {
                                  event: eventId,
                                  template: template.id,
                                  type: 'background',
                              })
                            : null)
                    }
                />
                <p className="text-[11px] text-slate-500">
                    Preview uses representative data. Download the PDF to confirm print output.
                </p>
                <div className="flex flex-wrap gap-2">
                    <Button type="button" onClick={resetLayout}>
                        <RotateCcw className="mr-1.5 h-4 w-4" />
                        Reset layout
                    </Button>
                    <Button type="button" onClick={testPdf} disabled={pdfPending}>
                        <Download className="mr-1.5 h-4 w-4" />
                        {pdfPending ? 'Generating…' : 'Test PDF'}
                    </Button>
                </div>
                <ArtifactErrors error={pdfError} />
                {pdfWarning && (
                    <p
                        role="status"
                        className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-200"
                    >
                        {pdfWarning}
                    </p>
                )}
                {pdfUrl && (
                    <a
                        href={pdfUrl}
                        target="_blank"
                        rel="noreferrer"
                        className="text-xs text-indigo-700 dark:text-indigo-300"
                    >
                        Open generated test PDF
                    </a>
                )}
                <div className="flex justify-end gap-2 border-t border-slate-200 pt-3 dark:border-slate-700">
                    <Button type="button" onClick={onCancel}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" disabled={saving}>
                        {saving ? 'Saving…' : 'Save design'}
                    </Button>
                </div>
            </aside>
        </form>
    );
}
