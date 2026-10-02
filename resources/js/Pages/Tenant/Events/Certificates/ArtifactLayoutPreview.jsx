const FALLBACKS = {
    title: { x: 0.16, y: 0.15, width: 0.68, height: 0.08 },
    recipient_name: { x: 0.15, y: 0.34, width: 0.7, height: 0.1 },
    body: { x: 0.15, y: 0.48, width: 0.7, height: 0.16 },
    verification_code: { x: 0.68, y: 0.9, width: 0.25, height: 0.04 },
};

export default function ArtifactLayoutPreview({ form, backgroundUrl }) {
    const values = {
        title: form.title || 'Certificate of participation',
        recipient_name: 'Akosua Élise Mensah',
        body: (form.body_template || 'This certifies that {name} attended {event_name}.').replace('{name}', 'Akosua Élise Mensah').replace('{event_name}', 'Your event'),
        verification_code: 'MC-PREVIEW-2026',
    };

    return (
        <div className="overflow-hidden rounded-lg border border-slate-300 bg-white shadow-sm dark:border-slate-700" aria-label="Certificate preview">
            <div className="relative aspect-[1.414/1] bg-[linear-gradient(135deg,#eef2ff,#fff_45%,#fef3c7)]" style={backgroundUrl ? { backgroundImage: `url(${backgroundUrl})`, backgroundSize: 'cover', backgroundPosition: 'center' } : undefined}>
                {Object.entries(values).map(([key, value]) => {
                    const item = form.layout?.[key] || FALLBACKS[key];
                    if (item?.visible === false) return null;
                    return <div key={key} className={`absolute overflow-hidden px-1 text-slate-900 ${key === 'recipient_name' ? 'font-serif text-lg font-bold sm:text-2xl' : key === 'title' ? 'text-sm font-bold sm:text-lg' : 'text-[8px] sm:text-xs'}`} style={{ left: `${item.x * 100}%`, top: `${item.y * 100}%`, width: `${item.width * 100}%`, height: `${item.height * 100}%`, textAlign: item.align || 'center', color: item.color || '#111827' }}>
                        {value}
                    </div>;
                })}
            </div>
        </div>
    );
}
