import { expect, it } from 'vitest';
import { safePortalReturn } from '@/lib/portalReturn';

it('lets a verified visitor go back to the portal or their own venue request page', () => {
    expect(safePortalReturn('/my/join/some-event')).toBe('/my/join/some-event');
    expect(safePortalReturn('/my')).toBe('/my');
    expect(safePortalReturn('/marketplace/bookings/VRQ-ABCD1234')).toBe(
        '/marketplace/bookings/VRQ-ABCD1234'
    );
});

it('ignores anything that could send them somewhere else', () => {
    for (const bad of [
        null,
        '',
        '//evil.test/my',
        'https://evil.test/my',
        '/marketplace/bookings/VRQ-1/../../x',
        '/marketplace/bookings/VRQ-1?next=//evil.test',
        '/marketplace/bookings/',
        '/mystery',
        '/my\\evil',
    ]) {
        expect(safePortalReturn(bad)).toBeNull();
    }
});
