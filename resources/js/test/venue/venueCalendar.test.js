import { expect, it } from 'vitest';
import { calendarEventNote, calendarEventTone } from '@/lib/venueCalendar';

const base = { status: 'confirmed', kind: 'marketplace', is_blocked: false, approval_flow: false };

it('keeps the existing tones for legacy and staff bookings', () => {
    expect(calendarEventTone(base)).toBe('confirmed');
    expect(calendarEventTone({ ...base, status: 'pending_payment' })).toBe('pending');
    expect(calendarEventTone({ ...base, status: 'blocked', is_blocked: true })).toBe('blocked');
    expect(calendarEventTone({ ...base, kind: 'internal' })).toBe('internal');
});

it('draws a public request as not holding the dates', () => {
    const request = {
        ...base,
        status: 'pending_host_approval',
        approval_flow: true,
        holds_calendar: false,
    };

    expect(calendarEventTone(request)).toBe('request');
    expect(calendarEventNote(request)).toBe('Request, dates not held');
});

it('draws an active hold as tentative with its exact end in the venue zone', () => {
    const hold = {
        ...base,
        status: 'approved_hold',
        approval_flow: true,
        holds_calendar: true,
        hold_expires_at: '2030-04-20T15:30:00+00:00',
        timezone: 'Africa/Accra',
    };

    expect(calendarEventTone(hold)).toBe('hold');
    expect(calendarEventNote(hold)).toMatch(/Held until .*3:30 pm.*Africa\/Accra/);
});

it('says nothing extra for an ordinary booking', () => {
    expect(calendarEventNote(base)).toBeNull();
});
