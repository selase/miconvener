import { artifactFontFamily } from './ArtifactFonts';

export default function ArtifactLayoutPreview({
    form,
    layoutDefaults = {},
    backgroundUrl,
    signatureUrl,
}) {
    const role = (form.role || 'delegate').replaceAll('_', ' ');
    const hours = Number(form.default_cpd_hours || 0);
    const replacements = {
        name: 'Akosua Élise Mensah',
        event_name: 'Your event',
        date: 'October 8, 2026',
        role: role.charAt(0).toUpperCase() + role.slice(1),
        hours: hours > 0 ? hours.toFixed(1) : '',
    };
    const values = {
        title: form.title || 'Certificate of Participation',
        recipient_name: replacements.name,
        body: (form.body_template || 'This certifies that {name} attended {event_name}.').replace(
            /\{(name|event_name|date|role|hours)\}/g,
            (_, key) => replacements[key]
        ),
        event_name: replacements.event_name,
        issuer: [form.issuer_name, form.issuer_title].filter(Boolean).join(' · '),
        verification_code: 'MC-PREVIEW-2026',
        cpd_hours: `${hours.toFixed(1)} Continuing Education (CPD/CME) Contact Hours`,
        signature: signatureUrl,
        qr: 'Illustrative QR',
    };
    const custom = form.design_mode === 'custom_background';
    const layout = custom ? { ...layoutDefaults, ...form.layout } : layoutDefaults;
    return (
        <div
            className="overflow-hidden rounded-lg border border-slate-300 bg-white shadow-sm dark:border-slate-700"
            aria-label="Certificate preview"
        >
            <div
                className="relative aspect-[1.414/1] bg-white"
                style={{
                    containerType: 'inline-size',
                    backgroundImage: custom && backgroundUrl ? `url(${backgroundUrl})` : undefined,
                    backgroundSize: '100% 100%',
                    boxShadow: custom
                        ? undefined
                        : 'inset 0 0 0 6px #1e3a8a, inset 0 0 0 9px #ffffff, inset 0 0 0 10px #d97706',
                }}
            >
                {Object.entries(values).map(([key, value]) => {
                    const item = layout[key];
                    if (
                        !item ||
                        item.visible === false ||
                        !value ||
                        (key === 'qr' && !form.show_qr) ||
                        (key === 'cpd_hours' && !form.show_cpd_hours)
                    )
                        return null;
                    return (
                        <div
                            key={key}
                            data-field={key}
                            className="absolute overflow-hidden leading-tight"
                            style={{
                                left: `${item.x * 100}%`,
                                top: `${item.y * 100}%`,
                                width: `${item.width * 100}%`,
                                height: `${item.height * 100}%`,
                                textAlign: item.align,
                                color: item.color,
                                fontFamily: artifactFontFamily(item.font_family),
                                fontWeight: item.font_weight,
                                fontSize: `${(item.font_size / 841.89) * 100}cqw`,
                            }}
                        >
                            {key === 'signature' ? (
                                <img
                                    src={value}
                                    alt="Signature"
                                    className="h-full w-full object-contain"
                                />
                            ) : key === 'qr' ? (
                                <div className="grid h-full place-items-center border border-slate-900 bg-white text-[8px] text-slate-900">
                                    {value}
                                </div>
                            ) : (
                                value
                            )}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
