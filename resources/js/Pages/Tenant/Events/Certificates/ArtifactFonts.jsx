import { useEffect, useState } from 'react';
import Input from '@/Components/Console/Input';
import Button from '@/Components/Console/Button';
import Checkbox from '@/Components/Console/Checkbox';
import csrfFetch, { csrfFetchFormData } from '@/lib/csrfFetch';
import { artifactJson } from '@/lib/artifactResponse';

const bundled = {
    'DejaVu Sans': ['DejaVuSans.ttf', 'DejaVuSans-Bold.ttf'],
    'DejaVu Serif': ['DejaVuSerif.ttf', 'DejaVuSerif-Bold.ttf'],
    'DejaVu Sans Mono': ['DejaVuSansMono.ttf', 'DejaVuSansMono-Bold.ttf'],
    Lato: ['lato-Lato-Regular.ttf', 'lato-Lato-Bold.ttf'],
    Arvo: ['arvo-Arvo-Regular.ttf', 'arvo-Arvo-Bold.ttf'],
    'PT Serif': ['ptserif-PT_Serif-Web-Regular.ttf', 'ptserif-PT_Serif-Web-Bold.ttf'],
    'PT Sans': ['ptsans-PT_Sans-Web-Regular.ttf', 'ptsans-PT_Sans-Web-Bold.ttf'],
    Abel: ['abel-Abel-Regular.ttf'],
    Anton: ['anton-Anton-Regular.ttf'],
};
export const DEFAULT_ARTIFACT_FONTS = [
    ...['Helvetica', 'Times', 'Courier'].map((family) => ({
        family,
        name: family,
        weights: [400, 700],
        faces: [],
    })),
    ...Object.entries(bundled).map(([family, files]) => ({
        family,
        name: family,
        weights: files.map((_, index) => (index === 0 ? 400 : 700)),
        faces: files.map((file, index) => ({
            weight: index === 0 ? 400 : 700,
            url: `/assets/fonts/artifacts/${file}`,
        })),
    })),
];
export const artifactFontFamily = (family) =>
    ({
        Helvetica: 'Arial, sans-serif',
        Times: '"Times New Roman", serif',
        Courier: '"Courier New", monospace',
    })[family] || `"${family}", sans-serif`;

export function ArtifactFontStyles({ fonts = DEFAULT_ARTIFACT_FONTS }) {
    const css = fonts.flatMap((font) => font.faces || []).length
        ? fonts
              .flatMap((font) =>
                  (font.faces || []).map(
                      (face) =>
                          `@font-face{font-family:${JSON.stringify(font.family)};src:url(${JSON.stringify(face.url)}) format("truetype");font-weight:${face.weight};font-style:normal;font-display:swap;}`
                  )
              )
              .join('\n')
        : '';
    return <style>{css}</style>;
}

export function useArtifactFonts(eventId, initialFonts, onFontsChange) {
    const [fonts, setFonts] = useState(initialFonts || DEFAULT_ARTIFACT_FONTS);
    const [error, setError] = useState(null);
    useEffect(() => {
        if (initialFonts) {
            setFonts(initialFonts);
            return undefined;
        }
        if (!eventId) return undefined;
        let active = true;
        csrfFetch(route('tenant.events.artifact-fonts.index', { event: eventId }))
            .then((response) => artifactJson(response, 'Fonts could not be loaded.'))
            .then((data) => {
                if (active) {
                    setFonts(data.fonts || DEFAULT_ARTIFACT_FONTS);
                    setError(null);
                }
            })
            .catch((reason) => {
                if (active) setError(reason.message);
            });
        return () => {
            active = false;
        };
    }, [eventId, initialFonts]);
    return {
        fonts: onFontsChange && initialFonts ? initialFonts : fonts,
        setFonts: onFontsChange || setFonts,
        error,
    };
}

export default function ArtifactFontLibrary({ eventId, fonts, setFonts, onSelect }) {
    const [source, setSource] = useState('');
    const [file, setFile] = useState(null);
    const [fileKey, setFileKey] = useState(0);
    const [name, setName] = useState('');
    const [licensed, setLicensed] = useState(false);
    const [pending, setPending] = useState(false);
    const [error, setError] = useState(null);
    const [status, setStatus] = useState(null);
    if (!eventId) return null;
    const importFont = async () => {
        setPending(true);
        setError(null);
        setStatus(null);
        const payload = new FormData();
        if (file) payload.append('font', file);
        else payload.append('source', source.trim());
        if (name.trim() && file) payload.append('name', name.trim());
        payload.append('license_confirmed', licensed ? '1' : '0');
        try {
            const data = await artifactJson(
                await csrfFetchFormData(
                    route('tenant.events.artifact-fonts.store', { event: eventId }),
                    payload
                ),
                'The font could not be imported.'
            );
            setFonts((current) => [...current, data.font]);
            onSelect?.(data.font);
            setStatus(`${data.font.name} is ready to use.`);
            setFileKey((current) => current + 1);
            setSource('');
            setFile(null);
        } catch (reason) {
            setError(reason.message);
        } finally {
            setPending(false);
        }
    };
    const archive = async (font) => {
        setPending(true);
        setError(null);
        try {
            const response = await csrfFetch(
                route('tenant.events.artifact-fonts.destroy', { event: eventId, font: font.id }),
                { method: 'DELETE' }
            );
            if (!response.ok) throw new Error('The font could not be removed from the library.');
            setFonts((current) =>
                current.map((item) =>
                    item.family === font.family ? { ...item, archived: true } : item
                )
            );
            setStatus('Font removed from the library. Saved designs can still use it.');
        } catch (reason) {
            setError(reason.message);
        } finally {
            setPending(false);
        }
    };
    return (
        <details className="rounded-xl border border-border p-3">
            <summary className="cursor-pointer text-sm font-semibold">Add your own font</summary>
            <div className="mt-3 space-y-3">
                <Input
                    label="Google Fonts family or link"
                    aria-label="Google Fonts family or link"
                    value={source}
                    onChange={(event) => {
                        setSource(event.target.value);
                        setFile(null);
                    }}
                    placeholder="e.g. Lato or a Google Fonts link"
                />
                <Input
                    key={fileKey}
                    label="Upload a font (.ttf)"
                    aria-label="Upload a font (.ttf)"
                    type="file"
                    accept=".ttf,font/ttf"
                    onChange={(event) => setFile(event.target.files?.[0] || null)}
                />
                {file && (
                    <Input
                        label="Font display name"
                        aria-label="Font display name"
                        value={name}
                        onChange={(event) => setName(event.target.value)}
                        maxLength={80}
                    />
                )}
                <p className="text-xs text-ink-secondary">
                    Use a static regular or bold TrueType font, up to 4 MB. Imported fonts are
                    available only to your organization’s badge and certificate designers.
                </p>
                <Checkbox
                    label="I have permission to use and embed this font"
                    checked={licensed}
                    onChange={(event) => setLicensed(event.target.checked)}
                />
                <Button
                    type="button"
                    disabled={pending || !licensed || (!file && !source.trim())}
                    onClick={importFont}
                >
                    {pending ? 'Preparing font…' : 'Import font'}
                </Button>
                {error && (
                    <p role="alert" className="text-sm text-rose-600">
                        {error}
                    </p>
                )}
                {status && (
                    <p role="status" className="text-xs text-ink-secondary">
                        {status}
                    </p>
                )}
                {fonts
                    .filter((font) => font.id && !font.archived)
                    .map((font) => (
                        <div
                            key={font.family}
                            className="flex items-center justify-between gap-2 text-sm"
                        >
                            <span style={{ fontFamily: artifactFontFamily(font.family) }}>
                                {font.name}
                            </span>
                            <Button
                                type="button"
                                disabled={pending}
                                aria-label={`Remove font ${font.name} from library`}
                                onClick={() => archive(font)}
                            >
                                Remove
                            </Button>
                        </div>
                    ))}
            </div>
        </details>
    );
}
