const SAMPLE = {
    event_name: 'Your event',
    attendee_name: 'Akosua Élise Mensah',
    ticket_type: 'Professional pass',
    tier_label: 'VIP',
    ticket_code: 'MC-2026-1042',
    seat_label: 'Seat A14',
};

export default function BadgeLayoutPreview({ template, backgroundUrl }) {
    const ratio = `${template.width_mm || 100}/${template.height_mm || 70}`;
    const longName = SAMPLE.attendee_name.length > 28;

    return (
        <div>
            <div
                className="relative w-full overflow-hidden border border-slate-300 bg-white shadow-sm dark:border-slate-700"
                style={{
                    aspectRatio: ratio,
                    backgroundImage: backgroundUrl ? `url(${backgroundUrl})` : undefined,
                    backgroundSize: 'cover',
                    backgroundPosition: 'center',
                }}
                aria-label="Badge design preview"
            >
                {Object.entries(template.layout || {}).map(([key, item]) => {
                    if (item.visible === false) return null;
                    if (key === 'qr')
                        return (
                            <div
                                key={key}
                                className="absolute grid place-items-center border-2 border-slate-900 bg-white text-[9px] font-bold text-slate-900"
                                style={{
                                    left: `${item.x * 100}%`,
                                    top: `${item.y * 100}%`,
                                    width: `${item.width * 100}%`,
                                    height: `${item.height * 100}%`,
                                }}
                            >
                                QR
                            </div>
                        );
                    return (
                        <div
                            key={key}
                            className="absolute overflow-hidden leading-tight"
                            style={{
                                left: `${item.x * 100}%`,
                                top: `${item.y * 100}%`,
                                width: `${item.width * 100}%`,
                                height: `${item.height * 100}%`,
                                textAlign: item.align || 'center',
                                color: item.color || '#111827',
                                fontWeight: item.font_weight || 400,
                                fontSize: `${Math.max(8, Math.min(24, (item.font_size || 12) * 0.75))}px`,
                            }}
                        >
                            {SAMPLE[key] || ''}
                        </div>
                    );
                })}
            </div>
            {longName && (
                <p className="mt-2 text-[11px] text-amber-700 dark:text-amber-300">
                    Check long names in the PDF before printing. Names over 80 characters are
                    rejected.
                </p>
            )}
            <p className="mt-1 text-[11px] text-slate-500">
                Keep the QR area high contrast and at least 20 mm wide.
            </p>
        </div>
    );
}
