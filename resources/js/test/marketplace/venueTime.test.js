import { expect, it } from 'vitest';
import { formatInZone, localToOffsetIso } from '@/lib/venueTime';

it('turns a wall-clock time in the venue zone into an ISO timestamp with its offset', () => {
    expect(localToOffsetIso('2030-03-10T09:00', 'Africa/Accra')).toEqual({
        ok: true,
        iso: '2030-03-10T09:00:00+00:00',
    });
    expect(localToOffsetIso('2030-07-01T09:30', 'Europe/London')).toEqual({
        ok: true,
        iso: '2030-07-01T09:30:00+01:00',
    });
    expect(localToOffsetIso('2030-12-01T09:30', 'America/New_York')).toEqual({
        ok: true,
        iso: '2030-12-01T09:30:00-05:00',
    });
});

it('rejects a wall-clock time that does not exist when clocks jump forward', () => {
    const result = localToOffsetIso('2030-03-31T01:30', 'Europe/London');

    expect(result.ok).toBe(false);
    expect(result.reason).toBe('missing');
});

it('rejects a wall-clock time that happens twice when clocks go back', () => {
    const result = localToOffsetIso('2030-10-27T01:30', 'Europe/London');

    expect(result.ok).toBe(false);
    expect(result.reason).toBe('ambiguous');
});

it('accepts the hour either side of a clock change', () => {
    expect(localToOffsetIso('2030-10-27T00:30', 'Europe/London').iso).toBe(
        '2030-10-27T00:30:00+01:00'
    );
    expect(localToOffsetIso('2030-10-27T02:30', 'Europe/London').iso).toBe(
        '2030-10-27T02:30:00+00:00'
    );
});

it('refuses input that is not a date and time or a zone it does not know', () => {
    expect(localToOffsetIso('', 'Africa/Accra').ok).toBe(false);
    expect(localToOffsetIso('2030-03-10', 'Africa/Accra').ok).toBe(false);
    expect(localToOffsetIso('2030-03-10T09:00', 'Not/AZone').ok).toBe(false);
});

it('shows an instant in the venue zone with the zone named', () => {
    expect(formatInZone('2030-07-01T08:30:00+00:00', 'Europe/London')).toContain('9:30');
    expect(formatInZone('2030-07-01T08:30:00+00:00', 'Europe/London')).toContain('Europe/London');
});
