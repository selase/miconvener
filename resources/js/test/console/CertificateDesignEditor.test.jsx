import { useState } from 'react';
import { beforeEach, describe, it, expect, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import csrfFetch from '@/lib/csrfFetch';
import CertificateDesignEditor from '@/Pages/Tenant/Events/Certificates/CertificateDesignEditor';

vi.mock('@/lib/csrfFetch', async (importOriginal) => ({ ...(await importOriginal()), default: vi.fn() }));

beforeEach(() => { csrfFetch.mockResolvedValue({ ok: true, json: async () => ({ fonts: undefined }) }); });

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

it('posts unsaved multipart design and shows PDF failures within the editor', async () => {
    vi.stubGlobal('route', (name) => name);
    vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue({
            ok: false,
            json: async () => ({ errors: { background: ['Artwork is corrupt.'] } }),
        })
    );
    render(<Editor onSave={vi.fn()} />);
    fireEvent.change(screen.getByLabelText('Certificate title'), {
        target: { value: 'Unsaved draft' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Test PDF' }));
    expect((await screen.findByRole('alert')).textContent).toContain('Artwork is corrupt.');
    expect(fetch.mock.calls[0][0]).toBe('tenant.events.certificates.templates.draft-preview');
    expect(fetch.mock.calls[0][1].body.get('title')).toBe('Unsaved draft');
    expect(fetch.mock.calls[0][1].body.get('layout')).toBe(JSON.stringify(defaults));
    vi.unstubAllGlobals();
});

it('retains a generated PDF link and releases its blob when the editor closes', async () => {
    vi.stubGlobal('route', (name) => name);
    vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue({ ok: true, headers: { get: () => 'application/pdf' }, blob: async () => new Blob(['%PDF']) })
    );
    const create = vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:pdf');
    const revoke = vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => {});
    const open = vi.spyOn(window, 'open').mockImplementation(() => null);
    const { unmount } = render(<Editor onSave={vi.fn()} />);
    fireEvent.click(screen.getByRole('button', { name: 'Test PDF' }));
    expect(
        (await screen.findByRole('link', { name: 'Open generated test PDF' })).getAttribute('href')
    ).toBe('blob:pdf');
    expect(open).toHaveBeenCalledWith('blob:pdf', '_blank', 'noopener,noreferrer');
    expect(revoke).not.toHaveBeenCalled();
    unmount();
    expect(revoke).toHaveBeenCalledWith('blob:pdf');
    create.mockRestore();
    revoke.mockRestore();
    open.mockRestore();
    vi.unstubAllGlobals();
});

it('shows a pending PDF state and releases uploaded artwork URLs on removal', async () => {
    vi.stubGlobal('route', (name) => name);
    let resolve;
    vi.stubGlobal(
        'fetch',
        vi.fn(
            () =>
                new Promise((done) => {
                    resolve = done;
                })
        )
    );
    const create = vi
        .spyOn(URL, 'createObjectURL')
        .mockImplementation((file) => `blob:${file.name}`);
    const revoke = vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => {});
    const form = {
        role: 'delegate',
        design_mode: 'custom_background',
        title: 'Draft',
        layout: { ...defaults, signature: element },
        background: new File(['png'], 'background.png'),
        signature: new File(['png'], 'signature.png'),
    };
    const { rerender, unmount } = render(
        <CertificateDesignEditor
            form={form}
            setForm={vi.fn()}
            layoutDefaults={defaults}
            eventId="event"
        />
    );
    expect(screen.getByAltText('Signature').getAttribute('src')).toBe('blob:signature.png');
    fireEvent.click(screen.getByRole('button', { name: 'Test PDF' }));
    expect(screen.getByRole('button', { name: 'Generating…' }).disabled).toBe(true);
    rerender(
        <CertificateDesignEditor
            form={{
                ...form,
                background: null,
                signature: null,
                remove_background: true,
                remove_signature: true,
            }}
            setForm={vi.fn()}
            layoutDefaults={defaults}
            eventId="event"
        />
    );
    expect(revoke).toHaveBeenCalledWith('blob:background.png');
    expect(revoke).toHaveBeenCalledWith('blob:signature.png');
    expect(screen.queryByAltText('Signature')).toBeNull();
    resolve({ ok: false, json: async () => ({ message: 'Failed PDF' }) });
    expect((await screen.findByRole('alert')).textContent).toBe('Failed PDF');
    unmount();
    create.mockRestore();
    revoke.mockRestore();
    vi.unstubAllGlobals();
});

it.each([
    ['403', { ok: false, status: 403, json: async () => ({}) }],
    ['500', { ok: false, status: 500, json: async () => ({}) }],
    [
        'non-JSON',
        {
            ok: false,
            status: 500,
            json: async () => {
                throw new Error('HTML');
            },
        },
    ],
    [
        'unexpected successful HTML',
        { ok: true, headers: { get: () => 'text/html' }, blob: async () => new Blob(['HTML']) },
    ],
    ['network', null],
])('keeps an edited draft and reports %s test-PDF errors', async (_, response) => {
    vi.stubGlobal(
        'fetch',
        response
            ? vi.fn().mockResolvedValue(response)
            : vi.fn().mockRejectedValue(new Error('Connection lost'))
    );
    try {
        render(<Editor onSave={vi.fn()} />);
        fireEvent.change(screen.getByLabelText('Certificate title'), {
            target: { value: 'Keep this draft' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Test PDF' }));
        await screen.findByRole('alert');
        expect(screen.getByLabelText('Certificate title').value).toBe('Keep this draft');
        expect(screen.getByRole('button', { name: 'Test PDF' }).disabled).toBe(false);
    } finally {
        vi.unstubAllGlobals();
    }
});

it('announces a fallback warning when preview artwork is unavailable', async () => {
    vi.stubGlobal(
        'fetch',
        vi
            .fn()
            .mockResolvedValue({
                ok: true,
                headers: {
                    get: (name) =>
                        name === 'X-Certificate-Preview-Warning'
                            ? 'Background unavailable; the default design is shown.'
                            : 'application/pdf',
                },
                blob: async () => new Blob(['%PDF']),
            })
    );
    vi.spyOn(window, 'open').mockReturnValue(null);
    URL.createObjectURL = vi.fn(() => 'blob:preview');
    URL.revokeObjectURL = vi.fn();
    try {
        render(<Editor onSave={vi.fn()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Test PDF' }));
        expect((await screen.findByRole('status')).textContent).toContain('Background unavailable');
    } finally {
        vi.unstubAllGlobals();
    }
});
