import { Download, RotateCcw } from 'lucide-react';
import Button from '@/Components/Console/Button';
import Checkbox from '@/Components/Console/Checkbox';
import Input from '@/Components/Console/Input';
import ArtworkUpload from './ArtworkUpload';
import ArtifactLayoutPreview from './ArtifactLayoutPreview';

export default function CertificateDesignEditor({ form, setForm, template, eventId, saving, onSave, onCancel }) {
    const backgroundUrl = form.background ? URL.createObjectURL(form.background) : null;
    const update = (key, value) => setForm((current) => ({ ...current, [key]: value }));
    const resetLayout = () => update('layout', {});

    return (
        <form onSubmit={onSave} className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(320px,0.9fr)]">
            <div className="space-y-4">
                <div className="rounded-lg bg-indigo-50 p-3 text-xs text-indigo-900 dark:bg-indigo-950/40 dark:text-indigo-200">
                    Changes apply only to certificates issued after you save. Existing credentials keep their original design.
                </div>
                <div className="grid grid-cols-2 gap-2 rounded-lg bg-slate-100 p-1 dark:bg-slate-800">
                    {['miconvener', 'custom_background'].map((mode) => <button key={mode} type="button" onClick={() => update('design_mode', mode)} className={`rounded-md px-3 py-2 text-xs font-semibold focus:outline-none focus:ring-2 focus:ring-indigo-500 ${form.design_mode === mode ? 'bg-white text-indigo-700 shadow-sm dark:bg-slate-700 dark:text-indigo-300' : 'text-slate-600 dark:text-slate-300'}`}>{mode === 'miconvener' ? 'MiConvener design' : 'My artwork'}</button>)}
                </div>
                {form.design_mode === 'custom_background' && <div className="grid gap-3 sm:grid-cols-2"><ArtworkUpload label="Background artwork" file={form.background} existing={template?.background_path && !form.remove_background} onChange={(file) => setForm((current) => ({ ...current, background: file, remove_background: false }))} onRemove={() => setForm((current) => ({ ...current, background: null, remove_background: true }))} /><ArtworkUpload label="Signature" file={form.signature} existing={template?.signature_path && !form.remove_signature} onChange={(file) => setForm((current) => ({ ...current, signature: file, remove_signature: false }))} onRemove={() => setForm((current) => ({ ...current, signature: null, remove_signature: true }))} /></div>}
                <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300">Certificate title<Input required value={form.title} onChange={(event) => update('title', event.target.value)} /></label>
                <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300">Body wording<textarea rows={4} className="mt-1 w-full rounded-lg border border-slate-300 bg-white p-2.5 text-xs text-slate-900 focus:ring-2 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-800 dark:text-white" value={form.body_template} onChange={(event) => update('body_template', event.target.value)} /></label>
                <div className="grid gap-3 sm:grid-cols-2"><label className="text-xs font-semibold text-slate-700 dark:text-slate-300">Issuer name<Input value={form.issuer_name} onChange={(event) => update('issuer_name', event.target.value)} /></label><label className="text-xs font-semibold text-slate-700 dark:text-slate-300">Issuer title<Input value={form.issuer_title} onChange={(event) => update('issuer_title', event.target.value)} /></label></div>
                <div className="flex flex-wrap gap-5"><label className="flex items-center gap-2 text-xs"><Checkbox checked={form.show_qr} onChange={(checked) => update('show_qr', checked)} />Verification QR</label><label className="flex items-center gap-2 text-xs"><Checkbox checked={form.show_cpd_hours} onChange={(checked) => update('show_cpd_hours', checked)} />CPD/CME hours</label></div>
            </div>
            <aside className="space-y-3 lg:sticky lg:top-0 lg:self-start">
                <ArtifactLayoutPreview form={form} backgroundUrl={backgroundUrl} />
                <p className="text-[11px] text-slate-500">Preview uses representative data. Download the PDF to confirm print output.</p>
                <div className="flex flex-wrap gap-2"><Button type="button" onClick={resetLayout}><RotateCcw className="mr-1.5 h-4 w-4" />Reset layout</Button>{template?.id && <a href={route('tenant.events.certificates.templates.preview', { event: eventId, template: template.id })} target="_blank" rel="noreferrer" className="inline-flex items-center rounded-md border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:border-slate-700 dark:text-slate-200"><Download className="mr-1.5 h-4 w-4" />Test PDF</a>}</div>
                <div className="flex justify-end gap-2 border-t border-slate-200 pt-3 dark:border-slate-700"><Button type="button" onClick={onCancel}>Cancel</Button><Button variant="primary" type="submit" disabled={saving}>{saving ? 'Saving…' : 'Save design'}</Button></div>
            </aside>
        </form>
    );
}
