import { useState } from 'react';
import { expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import BadgeDesignEditor from '@/Pages/Tenant/Events/Badges/BadgeDesignEditor';
const field = {
    x: 0.1,
    y: 0.1,
    width: 0.8,
    height: 0.2,
    font_family: 'DejaVu Sans',
    font_size: 20,
    font_weight: 700,
    color: '#111827',
    align: 'center',
    visible: true,
};
function Editor() {
    const [form, setForm] = useState({
        width_mm: 100,
        height_mm: 70,
        layout: { attendee_name: { ...field, x: 0.2, width: 0.6 }, event_name: field },
        sheet_settings: { paper: 'a4', margin_mm: 8, gap_mm: 3, crop_marks: true },
    });
    return (
        <>
            <BadgeDesignEditor
                form={form}
                setForm={setForm}
                layoutDefaults={{ attendee_name: field }}
                onSave={vi.fn()}
            />
            <output aria-label="Design values">{JSON.stringify(form)}</output>
        </>
    );
}
it('provides complete field controls, real boolean visibility, canonical reset and orientation updates', () => {
    render(<Editor />);
    fireEvent.change(screen.getByLabelText('Attendee name Width'), { target: { value: '0.5' } });
    fireEvent.change(screen.getByLabelText('Attendee name Height'), { target: { value: '0.3' } });
    fireEvent.change(screen.getByLabelText('Attendee name Font'), { target: { value: 'Courier' } });
    fireEvent.change(screen.getByLabelText('Attendee name Alignment'), {
        target: { value: 'left' },
    });
    fireEvent.change(screen.getByLabelText('Attendee name Color'), {
        target: { value: '#abcdef' },
    });
    fireEvent.change(screen.getByLabelText('Attendee name Font size'), { target: { value: '24' } });
    fireEvent.change(screen.getByLabelText('Attendee name Weight'), { target: { value: '400' } });
    fireEvent.click(screen.getByLabelText('Show Attendee name'));
    let form = JSON.parse(screen.getByLabelText('Design values').textContent);
    expect(form.layout.attendee_name).toMatchObject({
        font_size: 24,
        font_weight: 400,
        width: 0.5,
        height: 0.3,
        font_family: 'Courier',
        align: 'left',
        color: '#abcdef',
        visible: false,
    });
    fireEvent.click(screen.getByRole('button', { name: 'Reset fields' }));
    expect(
        JSON.parse(screen.getByLabelText('Design values').textContent).layout.attendee_name
    ).toEqual(field);
    fireEvent.change(screen.getByLabelText('Width (mm)'), { target: { value: '50' } });
    form = JSON.parse(screen.getByLabelText('Design values').textContent);
    expect(form.orientation).toBe('portrait');
    fireEvent.click(screen.getByRole('checkbox', { name: 'Crop marks' }));
    expect(
        JSON.parse(screen.getByLabelText('Design values').textContent).sheet_settings.crop_marks
    ).toBe(false);
});

it.each([
    ['Width (mm)', '90.5'],
    ['Height (mm)', '70.25'],
    ['Margin (mm)', '8.25'],
    ['Gap (mm)', '3.5'],
])('keeps server-valid fractional %s editable', (label, value) => {
    render(<Editor />);
    const input = screen.getByLabelText(label);
    fireEvent.change(input, { target: { value } });
    expect(input.checkValidity()).toBe(true);
});

it('selects canvas fields and edits their underline without submitting the form', () => {
    render(<Editor />);
    fireEvent.click(screen.getByRole('button', { name: 'Select Event name' }));
    expect(screen.getByLabelText('Field to position').value).toBe('event_name');
    fireEvent.click(screen.getByLabelText('Underline Event name'));
    expect(
        JSON.parse(screen.getByLabelText('Design values').textContent).layout.event_name.underline
    ).toBe(true);
    expect(screen.getByRole('button', { name: 'Select Event name' }).style.textDecoration).toBe(
        'underline'
    );
    fireEvent.change(screen.getByLabelText('Field to position'), {
        target: { value: 'attendee_name' },
    });
    expect(
        screen.getByRole('button', { name: 'Select Attendee name' }).getAttribute('aria-pressed')
    ).toBe('true');
});

it('changes background fit and positioning and provides mobile workspace views', () => {
    render(<Editor />);
    fireEvent.change(screen.getByLabelText('Background fit'), { target: { value: 'contain' } });
    fireEvent.change(screen.getByLabelText('Background position'), {
        target: { value: 'bottom-right' },
    });
    expect(
        JSON.parse(screen.getByLabelText('Design values').textContent).background_settings
    ).toMatchObject({ fit: 'contain', position: 'bottom-right' });
    fireEvent.click(screen.getByRole('button', { name: 'Canvas', exact: true }));
    expect(
        screen.getByRole('button', { name: 'Canvas', exact: true }).getAttribute('aria-pressed')
    ).toBe('true');
    fireEvent.click(screen.getByRole('button', { name: 'Select Event name' }));
    expect(
        screen.getByRole('button', { name: 'Properties', exact: true }).getAttribute('aria-pressed')
    ).toBe('true');
});
