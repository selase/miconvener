import { useState } from 'react';
import { describe, it, expect, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import CertificateDesignEditor from '@/Pages/Tenant/Events/Certificates/CertificateDesignEditor';

const element = {
    x: 0.15,
    y: 0.34,
    width: 0.7,
    height: 0.1,
    font_family: 'DejaVu Sans',
    font_size: 32,
    font_weight: 700,
    align: 'center',
    color: '#111827',
    visible: true,
};
const defaults = {
    recipient_name: element,
    title: { ...element, y: 0.15 },
    verification_code: { ...element, y: 0.8 },
};
function Editor({ onSave, layout = defaults, cpdHours = 0 }) {
    const [form, setForm] = useState({
        design_mode: 'custom_background',
        title: 'Certificate',
        body_template: '',
        issuer_name: '',
        issuer_title: '',
        show_qr: true,
        show_cpd_hours: false,
        default_cpd_hours: cpdHours,
        layout,
    });
    return (
        <CertificateDesignEditor
            form={form}
            setForm={setForm}
            layoutDefaults={defaults}
            eventId="event"
            onSave={(event) => {
                event.preventDefault();
                onSave(form);
            }}
            onCancel={() => {}}
        />
    );
}

describe('certificate layout editor', () => {
    it('submits the field positions dimensions typography color and optional visibility', () => {
        const save = vi.fn();
        render(<Editor onSave={save} />);
        for (const [label, value] of Object.entries({
            'Recipient name X': '0.2',
            'Recipient name Y': '0.3',
            'Recipient name Width': '0.6',
            'Recipient name Height': '0.12',
            'Recipient name Font': 'Times',
            'Recipient name Font size': '24',
            'Recipient name Weight': '400',
            'Recipient name Alignment': 'left',
            'Recipient name Color': '#123456',
        })) {
            fireEvent.change(screen.getByLabelText(label), { target: { value } });
        }
        fireEvent.change(screen.getByLabelText('Field to position'), {
            target: { value: 'title' },
        });
        fireEvent.click(screen.getByLabelText('Show Title'));
        fireEvent.change(screen.getByLabelText('Default CPD/CME hours'), {
            target: { value: '8.5' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save design' }));
        expect(save.mock.calls[0][0].layout.recipient_name).toEqual({
            x: 0.2,
            y: 0.3,
            width: 0.6,
            height: 0.12,
            font_family: 'Times',
            font_size: 24,
            font_weight: 400,
            align: 'left',
            color: '#123456',
            visible: true,
        });
        expect(save.mock.calls[0][0].layout.title.visible).toBe(false);
        expect(save.mock.calls[0][0].default_cpd_hours).toBe('8.5');
        expect(screen.queryByLabelText('Show Recipient name')).toBeNull();
        expect(screen.queryByLabelText('Show Verification code')).toBeNull();
    });
    it('resets to canonical defaults rather than the saved layout', () => {
        const save = vi.fn();
        render(
            <Editor
                onSave={save}
                layout={{ ...defaults, recipient_name: { ...element, x: 0.01 } }}
            />
        );
        fireEvent.click(screen.getByRole('button', { name: 'Reset layout' }));
        fireEvent.click(screen.getByRole('button', { name: 'Save design' }));
        expect(save.mock.calls[0][0].layout).toEqual(defaults);
    });
    it('persists checkbox flags as booleans', () => {
        const save = vi.fn();
        render(<Editor onSave={save} />);
        fireEvent.click(screen.getByLabelText('Verification QR'));
        fireEvent.click(screen.getByLabelText('CPD/CME hours'));
        fireEvent.click(screen.getByRole('button', { name: 'Save design' }));
        expect(save.mock.calls[0][0].show_qr).toBe(false);
        expect(save.mock.calls[0][0].show_cpd_hours).toBe(true);
    });
    it('keeps server-valid fractional values valid when saving unrelated edits', () => {
        const save = vi.fn();
        const fractionalLayout = {
            ...defaults,
            recipient_name: {
                ...element,
                x: 0.125,
                y: 0.335,
                width: 0.675,
                height: 0.105,
                font_size: 12.5,
            },
        };
        render(<Editor onSave={save} layout={fractionalLayout} cpdHours={6.25} />);
        for (const label of [
            'Recipient name X',
            'Recipient name Y',
            'Recipient name Width',
            'Recipient name Height',
            'Recipient name Font size',
            'Default CPD/CME hours',
        ]) {
            expect(screen.getByLabelText(label).checkValidity(), label).toBe(true);
        }
        fireEvent.change(screen.getByLabelText('Certificate title'), {
            target: { value: 'Updated wording' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save design' }));
        expect(save).toHaveBeenCalledTimes(1);
        expect(save.mock.calls[0][0].layout).toEqual(fractionalLayout);
        expect(save.mock.calls[0][0].default_cpd_hours).toBe(6.25);
    });
    it('accepts positive dimensions below a hundredth without losing the saved values', () => {
        const save = vi.fn();
        const smallLayout = {
            ...defaults,
            recipient_name: { ...element, width: 0.005, height: 0.005 },
        };
        render(<Editor onSave={save} layout={smallLayout} />);
        expect(screen.getByLabelText('Recipient name Width').checkValidity()).toBe(true);
        expect(screen.getByLabelText('Recipient name Height').checkValidity()).toBe(true);
        fireEvent.click(screen.getByRole('button', { name: 'Save design' }));
        expect(save.mock.calls[0][0].layout).toEqual(smallLayout);
    });
});
