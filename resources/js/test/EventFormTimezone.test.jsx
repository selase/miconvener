import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import EventFormModal from '@/Pages/Tenant/Events/EventFormModal';

const event = {
    id: 1,
    name: 'Sorkor at 70',
    status: 'published',
    starts_at: '2026-11-28T20:00:00+00:00',
    ends_at: '2026-11-29T00:00:00+00:00',
    timezone: 'America/New_York',
    location_type: 'in_person',
};

describe('EventFormModal times', () => {
    it("shows an event's times in its own timezone, whatever the browser's is", () => {
        const { container } = render(
            <EventFormModal mode="edit" event={event} onClose={() => {}} />
        );
        const inputs = container.querySelectorAll('input[type="datetime-local"]');

        expect(inputs[0].value).toBe('2026-11-28T15:00');
        expect(inputs[1].value).toBe('2026-11-28T19:00');
    });

    it('offers a timezone choice that includes the event’s own', () => {
        const { container } = render(
            <EventFormModal mode="edit" event={event} onClose={() => {}} />
        );
        const zone = [...container.querySelectorAll('select')].find((select) =>
            [...select.options].some((option) => option.value === 'America/New_York')
        );

        expect(zone.value).toBe('America/New_York');
    });
});
