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
        expect(door.status().attention[0].message).toContain('not confirmed');
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

describe('staff door, after review', () => {
    const pack1 = () => packWith([{ id: 'r1', name: 'Ama', ticket_code: 'T1', qr: 'QR-1' }]);

    async function doorWithWaitingScan(fetcher) {
        const store = createMemoryStore();
        await store.set('pack', await pack1());
        await store.set('queue', [
            { client_scan_id: 'id-1', registration_id: 'r1', scanned_at: '2026-11-02T08:59:00Z' },
        ]);
        return createStaffDoor({
            urls,
            store,
            fetcher,
            now: () => new Date('2026-11-02T09:00:00Z'),
        });
    }

    it('sends a long queue in batches the server accepts', async () => {
        const pack = await packWith(
            Array.from({ length: 450 }, (_, i) => ({
                id: `r${i}`,
                name: `G${i}`,
                ticket_code: `T${i}`,
                qr: `QR-${i}`,
            }))
        );
        let online = false;
        const sizes = [];
        const fetcher = vi.fn(async (url, options) => {
            if (!online) throw new TypeError('offline');
            if (url === '/sync') {
                const { scans } = JSON.parse(options.body);
                sizes.push(scans.length);
                return json({
                    results: scans.map((s) => ({
                        client_scan_id: s.client_scan_id,
                        outcome: 'admitted',
                        message: '',
                    })),
                    admitted_since: [],
                    server_time: '2026-11-02T09:05:00Z',
                });
            }
            return json(pack);
        });
        const door = await doorWith(fetcher, pack);
        for (let i = 0; i < 450; i++) await door.checkIn(`r${i}`);

        online = true;
        await door.sync();

        expect(sizes.every((n) => n <= 200)).toBe(true);
        expect(door.status().queued).toBe(0);
    }, 30_000); // 450 check-ins through the real store: slow on a busy machine, not stuck

    it('shows a sync failure instead of hiding it', async () => {
        const fetcher = vi.fn(async (url) =>
            url === '/sync' ? json({ message: 'Invalid' }, 422) : json({})
        );
        const door = await doorWithWaitingScan(fetcher);

        await door.sync();

        expect(door.status().syncError).toBeTruthy();
        expect(door.status().queued).toBe(1);
    });

    it('keeps unsent scans when the link is switched off, and keeps trying to send them', async () => {
        const store = createMemoryStore();
        await store.set('pack', await pack1());
        await store.set('queue', [
            { client_scan_id: 'q1', registration_id: 'r1', scanned_at: '2026-11-02T08:59:00Z' },
        ]);
        const door = createStaffDoor({
            urls,
            store,
            fetcher: async () => json({}, 410),
            now: () => new Date('2026-11-02T09:00:00Z'),
        });

        await door.refreshPack();

        expect(door.status().closed).toBe(true);
        expect(door.status().queued).toBe(1);
        expect(await store.get('pack')).toBeUndefined();
    });

    it('keeps unsent scans when the event is over, dropping only the guest list', async () => {
        const store = createMemoryStore();
        await store.set('pack', { ...(await pack1()), ends_at: '2020-01-01T00:00:00Z' });
        await store.set('queue', [
            { client_scan_id: 'q1', registration_id: 'r1', scanned_at: '2019-12-31T23:00:00Z' },
        ]);
        const door = createStaffDoor({
            urls,
            store,
            fetcher: offline,
            now: () => new Date('2026-11-02T09:00:00Z'),
        });

        await door.ready;

        expect(door.status().queued).toBe(1);
        expect(door.status().guests).toBe(0);
    });

    it('sends the live scan with the same id it would queue, so a slow answer is not counted twice', async () => {
        vi.useFakeTimers();
        const sent = [];
        const fetcher = vi.fn((url, options) => {
            if (url === '/scan') sent.push(JSON.parse(options.body).client_scan_id);
            return new Promise(() => {});
        });
        const door = await doorWith(fetcher, await pack1());

        const pending = door.scan('QR-1');
        await vi.advanceTimersByTimeAsync(3000);
        await pending;
        vi.useRealTimers();

        expect(sent[0]).toBeTruthy();
        expect(door.queueSnapshot()[0].client_scan_id).toBe(sent[0]);
    });

    it('gives the guest list and sync longer than a scan to answer', async () => {
        vi.useFakeTimers();
        let answered = false;
        const pack = await pack1();
        const fetcher = vi.fn(
            () =>
                new Promise((resolve) =>
                    setTimeout(() => {
                        answered = true;
                        resolve(json(pack));
                    }, 10000)
                )
        );
        const door = await doorWith(fetcher);

        const refreshing = door.refreshPack();
        await vi.advanceTimersByTimeAsync(10000);
        await refreshing;
        vi.useRealTimers();

        expect(answered).toBe(true);
        expect(door.status().guests).toBe(1);
    });

    it('records the scanned code with an offline admission, so a transferred ticket can be caught', async () => {
        const door = await doorWith(offline, await pack1());

        await door.scan('QR-1');

        expect(door.queueSnapshot()[0].qr_hash).toBe(await sha256Hex('QR-1'));
    });

    it('runs one sync at a time', async () => {
        let calls = 0;
        const fetcher = vi.fn(async (url) => {
            if (url === '/sync') {
                calls++;
                await new Promise((resolve) => setTimeout(resolve, 20));
                return json({
                    results: [],
                    admitted_since: [],
                    server_time: '2026-11-02T09:05:00Z',
                });
            }
            return json(await pack1());
        });
        const door = await doorWithWaitingScan(fetcher);

        await Promise.all([door.sync(), door.sync(), door.sync()]);

        expect(calls).toBe(1);
    });

    it('when the PIN unlock has lapsed, keeps scanning offline and says so', async () => {
        const fetcher = vi.fn(async () => json({ message: 'Enter the PIN first.' }, 403));
        const door = await doorWith(fetcher, await pack1());

        const result = await door.scan('QR-1');

        expect(result.offline).toBe(true);
        expect(door.status().locked).toBe(true);
        expect(door.status().queued).toBe(1);
    });

    it('treats a single-day event as one day, even past midnight', async () => {
        const pack = {
            ...(await packWith([
                {
                    id: 'r1',
                    name: 'Ama',
                    ticket_code: 'T1',
                    qr: 'QR-1',
                    in_today_at: '2026-11-06T23:00:00Z',
                },
            ])),
            day: '2026-11-06',
            multi_day: false,
        };
        const store = createMemoryStore();
        await store.set('pack', pack);
        const door = createStaffDoor({
            urls,
            store,
            fetcher: offline,
            now: () => new Date('2026-11-07T00:30:00Z'),
        });

        expect((await door.scan('QR-1')).already_checked_in).toBe(true);
    });

    it('names the guest in a refusal and lets the usher dismiss it', async () => {
        const fetcher = vi.fn(async (url) =>
            url === '/sync'
                ? json({
                      results: [
                          {
                              client_scan_id: 'id-1',
                              outcome: 'refused',
                              message: 'Ama (T1): this registration is not confirmed.',
                          },
                      ],
                      admitted_since: [],
                      server_time: '2026-11-02T09:05:00Z',
                  })
                : json(await pack1())
        );
        const store = createMemoryStore();
        await store.set('pack', await pack1());
        await store.set('queue', [
            { client_scan_id: 'id-1', registration_id: 'r1', scanned_at: '2026-11-02T08:59:00Z' },
        ]);
        const door = createStaffDoor({
            urls,
            store,
            fetcher,
            now: () => new Date('2026-11-02T09:00:00Z'),
        });

        await door.sync();
        const [note] = door.status().attention;
        expect(note.message).toContain('Ama (T1)');

        await door.dismiss(note.id);
        expect(door.status().attention).toHaveLength(0);
    });

    it('still scans online when the phone storage is unavailable', async () => {
        const broken = {
            get: () => Promise.reject(new Error('blocked')),
            set: () => Promise.reject(new Error('blocked')),
            clear: () => Promise.reject(new Error('blocked')),
        };
        const fetcher = vi.fn(async () => json({ message: 'Kofi checked in.' }));
        const door = createStaffDoor({ urls, store: broken, fetcher, now: () => new Date() });

        expect((await door.scan('QR-1')).message).toBe('Kofi checked in.');
    });

    it('tells the server what time the phone thinks it is', async () => {
        const bodies = [];
        const fetcher = vi.fn(async (url, options) => {
            if (url === '/sync') {
                bodies.push(JSON.parse(options.body));
                return json({
                    results: [],
                    admitted_since: [],
                    server_time: '2026-11-02T09:05:00Z',
                });
            }
            return json(await pack1());
        });
        const door = await doorWithWaitingScan(fetcher);

        await door.sync();

        expect(bodies[0].sent_at).toBe('2026-11-02T09:00:00.000Z');
    });
});

describe('staff door, minor gaps', () => {
    it('sends waiting scans as soon as a live scan succeeds', async () => {
        const store = createMemoryStore();
        await store.set(
            'pack',
            await packWith([{ id: 'r1', name: 'Ama', ticket_code: 'T1', qr: 'QR-1' }])
        );
        await store.set('queue', [
            { client_scan_id: 'q1', registration_id: 'r1', scanned_at: '2026-11-02T08:59:00Z' },
        ]);
        const urlsCalled = [];
        const fetcher = vi.fn(async (url, options) => {
            urlsCalled.push(url);
            if (url === '/sync') {
                const { scans } = JSON.parse(options.body);
                return json({
                    results: scans.map((s) => ({
                        client_scan_id: s.client_scan_id,
                        outcome: 'admitted',
                        message: '',
                    })),
                    admitted_since: [],
                    server_time: '2026-11-02T09:05:00Z',
                });
            }
            if (url === '/pack') return json(await packWith([]));
            return json({ message: 'Kofi checked in.' });
        });
        const door = createStaffDoor({
            urls,
            store,
            fetcher,
            now: () => new Date('2026-11-02T09:00:00Z'),
        });

        await door.scan('QR-2');
        await vi.waitFor(() => expect(door.status().queued).toBe(0));

        expect(urlsCalled).toContain('/sync');
    });

    it('says the link is finished once its guest list is gone and nothing is waiting', async () => {
        const store = createMemoryStore();
        await store.set('pack', { ...(await packWith([])), ends_at: '2020-01-01T00:00:00Z' });
        const door = createStaffDoor({
            urls,
            store,
            fetcher: offline,
            now: () => new Date('2026-11-02T09:00:00Z'),
        });

        await door.ready;

        expect(door.status().finished).toBe(true);
    });

    it('is not finished while scans are still waiting to be sent', async () => {
        const store = createMemoryStore();
        await store.set('pack', { ...(await packWith([])), ends_at: '2020-01-01T00:00:00Z' });
        await store.set('queue', [
            { client_scan_id: 'q1', registration_id: 'r1', scanned_at: '2019-12-31T23:00:00Z' },
        ]);
        const door = createStaffDoor({
            urls,
            store,
            fetcher: offline,
            now: () => new Date('2026-11-02T09:00:00Z'),
        });

        await door.ready;

        expect(door.status().finished).toBe(false);
    });
});
