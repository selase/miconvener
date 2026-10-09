import { expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import ArtifactLayoutPreview from '@/Pages/Tenant/Events/Certificates/ArtifactLayoutPreview';
import BadgeLayoutPreview from '@/Pages/Tenant/Events/Badges/BadgeLayoutPreview';
const field = {
    x: 0.1,
    y: 0.1,
    width: 0.7,
    height: 0.1,
    visible: true,
    font_family: 'Courier',
    font_size: 14,
    font_weight: 700,
    color: '#123456',
    align: 'left',
};
const layout = Object.fromEntries(
    [
        'title',
        'recipient_name',
        'body',
        'issuer',
        'signature',
        'cpd_hours',
        'qr',
        'verification_code',
    ].map((key) => [key, field])
);
it('certificate preview replaces every supported placeholder and renders optional fields and fonts', () => {
    const form = {
        role: 'delegate',
        title: 'Draft',
        design_mode: 'custom_background',
        layout: { ...layout, recipient_name: { ...field, x: 0.2 } },
        body_template: '{name} {name} {event_name} {date} {role} {hours}',
        issuer_name: 'Dr Ama',
        issuer_title: 'Chair',
        default_cpd_hours: 8.5,
        show_qr: true,
        show_cpd_hours: true,
    };
    const { container, rerender } = render(
        <ArtifactLayoutPreview
            form={form}
            layoutDefaults={layout}
            backgroundUrl="stored"
            signatureUrl="signature"
        />
    );
    expect(screen.getByText(/Akosua Élise Mensah Akosua/).textContent).toContain(
        'Your event October 8, 2026 Delegate 8.5'
    );
    expect(screen.getByText('Dr Ama · Chair')).toBeTruthy();
    expect(screen.getByAltText('Signature').getAttribute('src')).toBe('signature');
    expect(screen.getByText('Illustrative QR')).toBeTruthy();
    expect(container.querySelector('[data-field="title"]').style.fontFamily).toBe(
        '"Courier New", monospace'
    );
    expect(container.querySelector('[style*="background-image"]').style.backgroundSize).toBe(
        '100% 100%'
    );
    expect(container.querySelector('[data-field="recipient_name"]').style.left).toBe('20%');
    rerender(
        <ArtifactLayoutPreview
            form={{ ...form, design_mode: 'miconvener', show_qr: false, show_cpd_hours: false }}
            layoutDefaults={layout}
            backgroundUrl="stored"
            signatureUrl="signature"
        />
    );
    expect(screen.queryByText('Illustrative QR')).toBeNull();
    expect(screen.queryByText(/Continuing Education/)).toBeNull();
    expect(screen.getByAltText('Signature').getAttribute('src')).toBe('signature');
    expect(container.querySelector('[style*="background-image"]')).toBeNull();
    expect(container.querySelector('[data-field="recipient_name"]').style.left).toBe('10%');
});
it('badges use actual attendee data and saved tier styles while honoring hidden fields', () => {
    const template = {
        width_mm: 100,
        height_mm: 70,
        layout: { attendee_name: field, ticket_code: { ...field, visible: false }, qr: field },
        tier_styles: { vip: { background_color: '#112233', text_color: '#abcdef' } },
    };
    const { container } = render(
        <BadgeLayoutPreview
            template={template}
            event={{ name: 'Conference' }}
            badge={{
                full_name: 'Real attendee',
                badge_tier: 'vip',
                ticket_code: 'HIDDEN',
                qr_image: 'real-qr',
            }}
        />
    );
    expect(screen.getByText('Real attendee').style.color).toBe('rgb(171, 205, 239)');
    expect(screen.queryByText('HIDDEN')).toBeNull();
    expect(screen.getByAltText('Attendee QR').getAttribute('src')).toBe('real-qr');
    expect(
        container.querySelector('[aria-label="Badge design preview"]').style.backgroundColor
    ).toBe('rgb(17, 34, 51)');
    expect(screen.getByText(/Advisory preview/)).toBeTruthy();
});

it.each(['speaker', 'presenter', 'volunteer', 'special_guest'])(
    'uses the selected %s role in every body placeholder',
    (role) => {
        const expected = role.replaceAll('_', ' ');
        const label = expected[0].toUpperCase() + expected.slice(1);
        const { container } = render(
            <ArtifactLayoutPreview
                form={{
                    role,
                    design_mode: 'custom_background',
                    layout,
                    body_template: '{role}/{role}',
                }}
                layoutDefaults={layout}
            />
        );
        expect(container.querySelector('[data-field="body"]').textContent).toBe(
            `${label}/${label}`
        );
    }
);

it.each([0, '0.0', null, undefined, ''])(
    'leaves hours placeholders empty for zero or absent hours (%s)',
    (hours) => {
        const { container } = render(
            <ArtifactLayoutPreview
                form={{
                    role: 'delegate',
                    design_mode: 'custom_background',
                    layout,
                    default_cpd_hours: hours,
                    body_template: 'Before{hours}Middle{hours}After',
                }}
                layoutDefaults={layout}
            />
        );
        expect(container.querySelector('[data-field="body"]').textContent).toBe(
            'BeforeMiddleAfter'
        );
    }
);

it('badge preview shows the tenant logo and studio tier colors and hides optional elements', () => {
    const template = {
        width_mm: 100,
        height_mm: 70,
        layout: { tenant_logo: field, seat_label: { ...field, visible: false } },
        tier_styles: { vip: { background_color: '#112233' } },
    };
    const { container, rerender } = render(
        <BadgeLayoutPreview template={template} tenantLogo="data:image/png;base64,logo" />
    );
    expect(screen.getByAltText('Badge logo').getAttribute('src')).toBe(
        'data:image/png;base64,logo'
    );
    expect(
        container.querySelector('[aria-label="Badge design preview"]').style.backgroundColor
    ).toBe('rgb(17, 34, 51)');
    expect(screen.queryByText('Seat A14')).toBeNull();
    rerender(
        <BadgeLayoutPreview
            template={{ ...template, layout: { tenant_logo: { ...field, visible: false } } }}
            tenantLogo="logo"
        />
    );
    expect(screen.queryByAltText('Badge logo')).toBeNull();
});
