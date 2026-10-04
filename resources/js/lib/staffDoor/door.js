import csrfFetch from '@/lib/csrfFetch';
import { sha256Hex } from './hash';

class Unreachable extends Error {}

/**
 * The door as a staff link's phone sees it: the server first, the stored
 * guest list when the server cannot be reached within the timeout. Offline
 * admissions are queued, kept across reloads, and sent when the connection
 * returns. A 410 from the server (link switched off or event over) wipes
 * everything stored.
 */
export function createStaffDoor({
    urls,
    store,
    fetcher = csrfFetch,
    timeoutMs = 3000,
    now = () => new Date(),
    newId = () => crypto.randomUUID(),
}) {
    let pack = null;
    let queue = [];
    let local = {}; // registration id -> { day, at } this phone admitted them
    let attention = [];
    let lastSync = null;
    let online = true;
    let closed = false;
    const listeners = new Set();

    const status = () => ({
        online,
        closed,
        queued: queue.length,
        guests: pack?.guests?.length ?? 0,
        packUpdatedAt: pack?.version ?? null,
        attention,
    });
    const emit = () => listeners.forEach((listener) => listener(status()));

    const persist = () =>
        Promise.all([
            store.set('queue', queue),
            store.set('local', local),
            store.set('attention', attention),
            store.set('lastSync', lastSync),
        ]);

    const wipe = async () => {
        pack = null;
        queue = [];
        local = {};
        attention = [];
        await store.clear();
    };

    const ready = (async () => {
        pack = (await store.get('pack')) ?? null;
        queue = (await store.get('queue')) ?? [];
        local = (await store.get('local')) ?? {};
        attention = (await store.get('attention')) ?? [];
        lastSync = (await store.get('lastSync')) ?? null;
        // Nothing about the guests outlives the link itself.
        if (pack && new Date(pack.ends_at) < now()) {
            await wipe();
        }
        emit();
    })();

    const request = async (url, options = {}) => {
        let timer;
        const timeout = new Promise((_, reject) => {
            timer = setTimeout(() => reject(new Unreachable()), timeoutMs);
        });
        try {
            const response = await Promise.race([fetcher(url, options), timeout]);
            online = true;
            if (response.status === 410) {
                closed = true;
                await wipe();
                emit();
                throw new Unreachable();
            }
            return response;
        } catch (error) {
            if (!closed) {
                online = false;
                emit();
            }
            throw error instanceof Unreachable ? error : new Unreachable();
        } finally {
            clearTimeout(timer);
        }
    };

    const dayOf = (date) =>
        new Intl.DateTimeFormat('en-CA', { timeZone: pack?.timezone ?? 'Africa/Accra' }).format(
            date
        );

    const inToday = (guest) => {
        const today = dayOf(now());
        if (local[guest.id]?.day === today) return local[guest.id].at;
        if (guest.in_today_at && dayOf(new Date(guest.in_today_at)) === today)
            return guest.in_today_at;
        return null;
    };

    const asRegistration = (guest) => ({
        id: guest.id,
        full_name: guest.name,
        ticket_code: guest.ticket_code,
        seat_label: guest.seat,
        room_name: guest.room,
    });

    const admitOffline = async (guest) => {
        const earlier = inToday(guest);
        if (earlier) {
            const time = new Date(earlier).toLocaleTimeString([], {
                hour: '2-digit',
                minute: '2-digit',
            });
            return {
                message: `${guest.name} is already in today (${time}).`,
                already_checked_in: true,
                offline: true,
                registration: asRegistration(guest),
            };
        }

        const at = now();
        queue.push({
            client_scan_id: newId(),
            registration_id: guest.id,
            scanned_at: at.toISOString(),
        });
        local[guest.id] = { day: dayOf(at), at: at.toISOString() };
        await persist();
        emit();

        return {
            message: `${guest.name} checked in (offline).`,
            offline: true,
            registration: asRegistration(guest),
        };
    };

    const notOnList = { message: 'Ticket not recognised.', refused: true, offline: true };
    const noList = {
        message:
            'No connection, and this phone has no guest list yet. Connect once to download it.',
        refused: true,
        offline: true,
    };

    const door = {
        ready,
        status,

        subscribe(listener) {
            listeners.add(listener);
            return () => listeners.delete(listener);
        },

        async scan(token) {
            await ready;
            try {
                const response = await request(urls.scan, {
                    method: 'POST',
                    body: JSON.stringify({ token }),
                });
                return await response.json();
            } catch {
                if (closed)
                    return { message: 'This staff link has been switched off.', refused: true };
                if (!pack) return noList;
                const hash = await sha256Hex(token);
                const guest = pack.guests.find((candidate) => candidate.qr_hash === hash);
                return guest ? admitOffline(guest) : notOnList;
            }
        },

        async search(query) {
            await ready;
            try {
                const response = await request(`${urls.search}?q=${encodeURIComponent(query)}`);
                return await response.json();
            } catch {
                const needle = query.toLowerCase();
                return (pack?.guests ?? [])
                    .filter(
                        (guest) =>
                            guest.name.toLowerCase().includes(needle) ||
                            (guest.ticket_code ?? '').toLowerCase().includes(needle)
                    )
                    .slice(0, 10)
                    .map((guest) => ({
                        id: guest.id,
                        full_name: guest.name,
                        email: '',
                        ticket_code: guest.ticket_code,
                    }));
            }
        },

        async checkIn(id) {
            await ready;
            try {
                const response = await request(urls.checkIn(id), { method: 'POST' });
                return await response.json();
            } catch {
                if (closed)
                    return { message: 'This staff link has been switched off.', refused: true };
                const guest = pack?.guests.find((candidate) => candidate.id === id);
                return guest ? admitOffline(guest) : notOnList;
            }
        },

        async refreshPack() {
            await ready;
            try {
                const response = await request(urls.pack);
                if (response.ok) {
                    pack = await response.json();
                    await store.set('pack', pack);
                    emit();
                }
            } catch {
                // Offline: keep the list already stored.
            }
        },

        async sync() {
            await ready;
            try {
                const response = await request(urls.sync, {
                    method: 'POST',
                    body: JSON.stringify({ scans: [...queue], since: lastSync }),
                });
                if (!response.ok) return;
                const body = await response.json();
                const received = new Set(body.results.map((result) => result.client_scan_id));
                queue = queue.filter((scan) => !received.has(scan.client_scan_id));
                attention = [
                    ...attention,
                    ...body.results
                        .filter((result) => result.outcome === 'refused')
                        .map((result) => result.message),
                ];
                lastSync = body.server_time;
                await persist();
                emit();
                await door.refreshPack();
            } catch {
                // Still offline: the queue waits for the next attempt.
            }
        },
    };

    return door;
}
