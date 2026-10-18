import { expect, it, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import EventFormModal from '@/Pages/Tenant/Events/EventFormModal';
vi.mock('@inertiajs/react', async () => {
    const { useState } = await import('react');
    return {
        usePage: () => ({ props: { tenant: { features: { community_event_benefits: true } } } }),
        useForm: (initial) => {
            const [data, set] = useState(initial);
            return {
                data,
                setData: (key, value) => set((old) => ({ ...old, [key]: value })),
                transform: vi.fn(),
                post: vi.fn(),
                put: vi.fn(),
                processing: false,
                errors: {},
            };
        },
    };
});
it('offers member restrictions before the first event save', () => {
    render(<EventFormModal mode="create" onClose={vi.fn()} />);
    const only = screen.getByLabelText('Members-only registration');
    fireEvent.click(only);
    expect(only.checked).toBe(true);
    const price = screen.getByLabelText('Member ticket price (GHS)');
    fireEvent.change(price, { target: { value: '0' } });
    expect(price.value).toBe('0');
});
