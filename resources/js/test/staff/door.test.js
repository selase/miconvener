import { describe, expect, it, vi } from 'vitest';
import { createStaffDoor } from '@/lib/staffDoor/door';
import { createMemoryStore } from '@/lib/staffDoor/store';
import { sha256Hex } from '@/lib/staffDoor/hash';

const urls = {
    scan: '/scan',
    search: '/search',
    checkIn: (id) => `/checkin/${id}`,
    pack: '/pack',
    sync: '/sync',
};

const json = (body, status = 200) => ({ ok: status < 400, status, json: async () => body });
const offline = () => Promise.reject(new TypeError('Failed to fetch'));

async function packWith(guests) {
    return {
        version: '2026-11-02T08:00:00Z',
        day: '2026-11-02',
        timezone: 'Africa/Accra',
        ends_at: '2099-01-01T00:00:00Z',
        guests: await Promise.all(
            guests.map(async (guest) => ({
                in_today_at: null,
                seat: null,
                room: null,
                ...guest,
                qr_hash: await sha256Hex(guest.qr),
            }))
        ),
    };
}

async function doorWith(fetcher, pack) {
    const store = createMemoryStore();
    if (pack) await store.set('pack', pack);
    let n = 0;
    return createStaffDoor({
        urls,
        store,
        fetcher,
        now: () => new Date('2026-11-02T09:00:00Z'),
        newId: () => `id-${++n}`,
    });
}

describe('staff door', () => {
    it('matches PHP hash("sha256", ...)', async () => {
        expect(await sha256Hex('abc')).toBe(
            'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad'
        );
    });

    it('uses the server when it answers', async () => {
        const fetcher = vi.fn(async () =>
            json({ message: 'Kofi checked in.', outcome: 'admitted' })
        );
        const door = await doorWith(fetcher);

        expect((await door.scan('QR-1')).message).toBe('Kofi checked in.');
        expect(door.status().queued).toBe(0);
        expect(door.status().online).toBe(true);
    });

    it('falls back to the stored list when the network fails, and queues the admission', async () => {
        const door = await doorWith(
            offline,
            await packWith([{ id: 'r1', name: 'Ama Owusu', ticket_code: 'T1', qr: 'QR-1' }])
        );

        const result = await door.scan('QR-1');

        expect(result.offline).toBe(true);
        expect(result.registration.full_name).toBe('Ama Owusu');
        expect(door.status().queued).toBe(1);
        expect(door.status().online).toBe(false);
    });

    it('falls back after the timeout when the server does not answer', async () => {
        vi.useFakeTimers();
        const hang = () => new Promise(() => {});
        const door = await doorWith(
            hang,
            await packWith([{ id: 'r1', name: 'Ama', ticket_code: 'T1', qr: 'QR-1' }])
        );

        const pending = door.scan('QR-1');
        await vi.advanceTimersByTimeAsync(3000);

        expect((await pending).offline).toBe(true);
        vi.useRealTimers();
    });

    it('refuses its own repeat offline, and an unknown code', async () => {
        const door = await doorWith(
            offline,
            await packWith([{ id: 'r1', name: 'Ama', ticket_code: 'T1', qr: 'QR-1' }])
        );
        await door.scan('QR-1');

        expect((await door.scan('QR-1')).already_checked_in).toBe(true);
        expect((await door.scan('NOPE')).refused).toBe(true);
        expect(door.status().queued).toBe(1);
    });

    it('treats a guest the server already admitted today as already in', async () => {
        const door = await doorWith(
            offline,
            await packWith([
                {
                    id: 'r1',
                    name: 'Ama',
                    ticket_code: 'T1',
                    qr: 'QR-1',
                    in_today_at: '2026-11-02T08:30:00Z',
                },
            ])
        );

        expect((await door.scan('QR-1')).already_checked_in).toBe(true);
    });

    it('admits a guest who was in yesterday', async () => {
        const door = await doorWith(
            offline,
            await packWith([
                {
                    id: 'r1',
                    name: 'Ama',
                    ticket_code: 'T1',
                    qr: 'QR-1',
                    in_today_at: '2026-11-01T08:30:00Z',
                },
            ])
        );

        expect((await door.scan('QR-1')).already_checked_in).toBeUndefined();
    });

    it('searches the stored list by name or ticket code when offline', async () => {
        const door = await doorWith(
            offline,
            await packWith([{ id: 'r1', name: 'Ama Owusu', ticket_code: 'EVT-77', qr: 'QR-1' }])
        );

        expect((await door.search('owu')).map((r) => r.id)).toEqual(['r1']);
        expect((await door.search('evt-77')).map((r) => r.id)).toEqual(['r1']);
    });

    it('checks in by id offline', async () => {
        const door = await doorWith(
            offline,
            await packWith([{ id: 'r1', name: 'Ama', ticket_code: 'T1', qr: 'QR-1' }])
        );

        expect((await door.checkIn('r1')).offline).toBe(true);
        expect(door.status().queued).toBe(1);
    });

    it('keeps unsent scans while offline, sends them on sync, and surfaces refusals', async () => {
        const pack = await packWith([{ id: 'r1', name: 'Ama', ticket_code: 'T1', qr: 'QR-1' }]);
        let online = false;
        const fetcher = vi.fn(async (url) => {
            if (!online) throw new TypeError('offline');
            if (url === '/sync') {
                return json({
                    results: [
                        {
                            client_scan_id: 'id-1',
                            outcome: 'refused',
                            message: 'This registration is not confirmed.',
                        },
                    ],
                    admitted_since: [],
                    server_time: '2026-11-02T09:05:00Z',
                });
            }
            return json(pack);
        });
        const door = await doorWith(fetcher, pack);
        await door.scan('QR-1');

        await door.sync();
        expect(door.status().queued).toBe(1);

        online = true;
        await door.sync();
        expect(door.status().queued).toBe(0);
        expect(door.status().attention[0]).toContain('not confirmed');
    });

    it('wipes stored data when the server says the link is closed', async () => {
        const store = createMemoryStore();
        await store.set('pack', await packWith([]));
        const door = createStaffDoor({
            urls,
            store,
            fetcher: async () => json({}, 410),
            now: () => new Date(),
        });

        await door.refreshPack();

        expect(await store.get('pack')).toBeUndefined();
        expect(door.status().closed).toBe(true);
    });

    it('drops a stored list whose event ended more than 12 hours ago', async () => {
        const store = createMemoryStore();
        await store.set('pack', { ...(await packWith([])), ends_at: '2020-01-01T00:00:00Z' });
        const door = createStaffDoor({
            urls,
            store,
            fetcher: offline,
            now: () => new Date('2026-11-02T09:00:00Z'),
        });

        await door.ready;

        expect(await store.get('pack')).toBeUndefined();
    });

    it('survives a reload: the queue is kept in storage', async () => {
        const store = createMemoryStore();
        await store.set(
            'pack',
            await packWith([{ id: 'r1', name: 'Ama', ticket_code: 'T1', qr: 'QR-1' }])
        );
        const first = createStaffDoor({
            urls,
            store,
            fetcher: offline,
            now: () => new Date('2026-11-02T09:00:00Z'),
            newId: () => 'q1',
        });
        await first.scan('QR-1');

        const second = createStaffDoor({
            urls,
            store,
            fetcher: offline,
            now: () => new Date('2026-11-02T09:01:00Z'),
        });
        await second.ready;

        expect(second.status().queued).toBe(1);
        expect((await second.scan('QR-1')).already_checked_in).toBe(true);
    });
});
