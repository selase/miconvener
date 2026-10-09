const SAMPLE = {
    event_name: 'Your event',
    attendee_name: 'Akosua Élise Mensah',
    ticket_type: 'Professional pass',
    tier_label: 'VIP',
    ticket_code: 'MC-2026-1042',
    seat_label: 'Seat A14',
};

export default function BadgeLayoutPreview({
    template,
    backgroundUrl,
    badge,
    event,
    tenantLogo,
    selectedField,
    onSelectField,
}) {
    const ratio = `${template.width_mm || 100}/${template.height_mm || 70}`;
    const values = badge
        ? {
              event_name: event?.name || '',
              attendee_name: badge.full_name || '',
              ticket_type: badge.ticket_type_name || '',
              tier_label: (badge.badge_tier || 'general').toUpperCase(),
              ticket_code: badge.ticket_code || '',
              seat_label: badge.seat_label || '',
          }
        : SAMPLE;
    const tierStyle = template.tier_styles?.[badge?.badge_tier || 'vip'] || {};
    const longName = values.attendee_name.length > 28;

    return (
        <div
            style={
                onSelectField
                    ? {
                          maxWidth: `${((template.width_mm || 100) / (template.height_mm || 70)) * 60}dvh`,
                          marginInline: 'auto',
                      }
                    : undefined
            }
        >
            <div
                className="relative w-full overflow-hidden border border-slate-300 bg-white shadow-sm dark:border-slate-700"
                style={{
                    aspectRatio: ratio,
                    backgroundImage: backgroundUrl ? `url(${backgroundUrl})` : undefined,
                    backgroundSize:
                        template.background_settings?.fit === 'contain'
                            ? 'contain'
                            : template.background_settings?.fit === 'cover'
                              ? 'cover'
                              : '100% 100%',
                    backgroundRepeat: 'no-repeat',
                    backgroundColor: tierStyle.background_color || '#FFFFFF',
                    containerType: 'inline-size',
                    backgroundPosition: (
                        template.background_settings?.position || 'center'
                    ).replaceAll('-', ' '),
                }}
                aria-label="Badge design preview"
            >
                {Object.entries(template.layout || {}).map(([key, item]) => {
                    if (item.visible === false) return null;
                    if (key === 'tenant_logo') {
                        return tenantLogo ? (
                            <img
                                key={key}
                                src={tenantLogo}
                                alt="Tenant logo"
                                className="absolute object-contain"
                                style={{
                                    left: `${item.x * 100}%`,
                                    top: `${item.y * 100}%`,
                                    width: `${item.width * 100}%`,
                                    height: `${item.height * 100}%`,
                                }}
                            />
                        ) : null;
                    }
                    if (key === 'qr' && badge && !badge.qr_image) return null;
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
                                {badge?.qr_image ? (
                                    <img
                                        src={badge.qr_image}
                                        alt="Attendee QR"
                                        className="h-full w-full object-contain"
                                    />
                                ) : (
                                    'Illustrative QR'
                                )}
                            </div>
                        );
                    const Element = onSelectField ? 'button' : 'div';
                    return (
                        <Element
                            key={key}
                            type={onSelectField ? 'button' : undefined}
                            aria-label={
                                onSelectField
                                    ? `Select ${key.replaceAll('_', ' ').replace(/^./, (letter) => letter.toUpperCase())}`
                                    : undefined
                            }
                            aria-pressed={onSelectField ? selectedField === key : undefined}
                            onClick={onSelectField ? () => onSelectField(key) : undefined}
                            className="absolute overflow-hidden leading-tight focus-visible:outline-2 focus-visible:outline-indigo-600"
                            style={{
                                left: `${item.x * 100}%`,
                                top: `${item.y * 100}%`,
                                width: `${item.width * 100}%`,
                                height: `${item.height * 100}%`,
                                display: 'block',
                                alignContent: 'start',
                                margin: 0,
                                padding: 0,
                                border: 0,
                                background: 'transparent',
                                outline:
                                    onSelectField && selectedField === key
                                        ? '2px solid #6366f1'
                                        : undefined,
                                outlineOffset: '-2px',
                                textDecoration: item.underline ? 'underline' : 'none',
                                textAlign: item.align || 'center',
                                color: tierStyle.text_color || item.color || '#111827',
                                fontFamily: {
                                    Helvetica: 'Arial, sans-serif',
                                    Times: '"Times New Roman", serif',
                                    Courier: '"Courier New", monospace',
                                    'DejaVu Sans': '"DejaVu Sans", sans-serif',
                                }[item.font_family],
                                fontWeight: item.font_weight || 400,
                                fontSize: `${((item.font_size || 12) / (((template.width_mm || 100) * 72) / 25.4)) * 100}cqw`,
                            }}
                        >
                            {values[key] || ''}
                        </Element>
                    );
                })}
            </div>
            {template.layout?.tenant_logo?.visible && !tenantLogo && (
                <p className="mt-2 text-xs text-ink-secondary">
                    Upload a PNG or JPEG organization logo in organization settings to show it on
                    badges.
                </p>
            )}
            {longName && (
                <p className="mt-2 text-[11px] text-amber-700 dark:text-amber-300">
                    Check long names in the PDF before printing. Names over 80 characters are
                    rejected.
                </p>
            )}
            <p className="mt-1 text-[11px] text-slate-500">
                Advisory preview; the generated PDF is authoritative. Keep the QR area high contrast
                and at least 20 mm wide.
            </p>
        </div>
    );
}
