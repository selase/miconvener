import BadgeCanvasElement from './BadgeCanvasElement';
import { artifactFontFamily } from '../Certificates/ArtifactFonts';
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
    onActivateField,
    onChangeElement,
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
                    backgroundImage:
                        backgroundUrl && template.background_settings?.visible !== false && !template.background_settings?.removed
                            ? `url(${backgroundUrl})`
                            : undefined,
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
                data-badge-canvas
            >
                {Object.entries(template.layout || {}).map(([key, item]) => {
                    if (item.visible === false || item.removed === true) return null;
                    const props = {
                        elementKey: key,
                        item,
                        template,
                        selected: selectedField === key,
                        onSelect: onSelectField,
                        onActivate: onActivateField,
                        onChange: onChangeElement,
                    };
                    if (key === 'tenant_logo') {
                        return tenantLogo ? (
                            <BadgeCanvasElement key={key} {...props}>
                                <img
                                    src={tenantLogo}
                                    alt="Badge logo"
                                    className="h-full w-full object-contain"
                                    draggable={false}
                                />
                            </BadgeCanvasElement>
                        ) : null;
                    }
                    if (key === 'qr' && badge && !badge.qr_image) return null;
                    if (key === 'qr')
                        return (
                            <BadgeCanvasElement
                                key={key}
                                {...props}
                                style={{ background: '#FFFFFF' }}
                            >
                                {badge?.qr_image ? (
                                    <img
                                        src={badge.qr_image}
                                        alt="Attendee QR"
                                        className="h-full w-full object-contain"
                                        draggable={false}
                                    />
                                ) : (
                                    <div className="grid h-full w-full place-items-center border-2 border-slate-900 text-[9px] font-bold text-slate-900">
                                        Illustrative QR
                                    </div>
                                )}
                            </BadgeCanvasElement>
                        );
                    return (
                        <BadgeCanvasElement
                            key={key}
                            {...props}
                            style={{
                                textDecoration: item.underline ? 'underline' : 'none',
                                textAlign: item.align || 'center',
                                color: tierStyle.text_color || item.color || '#111827',
                                fontFamily: artifactFontFamily(item.font_family),
                                fontWeight: item.font_weight || 400,
                                fontSize: `${((item.font_size || 12) / (((template.width_mm || 100) * 72) / 25.4)) * 100}cqw`,
                            }}
                        >
                            {values[key] || ''}
                        </BadgeCanvasElement>
                    );
                })}
            </div>
            {template.layout?.tenant_logo?.visible &&
                !template.layout?.tenant_logo?.removed &&
                !tenantLogo && (
                    <p className="mt-2 text-xs text-ink-secondary">
                        Add a PNG or JPEG logo in the badge designer, or use your organization logo.
                    </p>
                )}
            {onSelectField &&
                template.layout?.qr?.visible !== false &&
                !template.layout?.qr?.removed &&
                Math.min(
                    (template.layout?.qr?.width || 0) * template.width_mm,
                    (template.layout?.qr?.height || 0) * template.height_mm
                ) < 20 && (
                    <p className="mt-2 text-xs text-amber-700">
                        The QR is smaller than 20 mm. Increase its size and test a printed badge
                        before use.
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
