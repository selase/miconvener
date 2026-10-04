import csrfFetch from '@/lib/csrfFetch';
import { sha256Hex } from './hash';
import { createMemoryStore } from './store';

class Unreachable extends Error {}

/** The server accepts at most 500 scans per sync; stay well under it. */
const SYNC_BATCH = 200;

/** A scan must answer fast or the guest waits; a download or upload may take longer. */
const SLOW_TIMEOUT_MS = 30000;

/**
 * The door as a staff link's phone sees it: the server first, the stored
 * guest list when the server cannot be reached within the timeout. Offline
 * admissions are queued, kept across reloads, and sent when the connection
 * returns. Nothing is thrown away until the server has received it: a
 * switched-off link or a finished event drops the guest list, never the queue.
 */
export function createStaffDoor({
    urls,
    store: preferredStore,
    fetcher = csrfFetch,
    timeoutMs = 3000,
    now = () => new Date(),
    newId = () => crypto.randomUUID(),
}) {
    let store = preferredStore;
    let pack = null;
    let queue = [];
    let local = {}; // registration id -> { day, at } this phone admitted them
    let attention = []; // { id, message } the usher should look at
    let lastSync = null;
    let online = true;
    let closed = false;
    let locked = false;
    let expired = false;
    let syncError = null;
    let syncing = null;
    const listeners = new Set();

    const status = () => ({
        online,
        closed,
        locked,
        // Nothing more for this phone to do: the page can forget itself.
        finished: (closed || expired) && queue.length === 0,
        syncError,
        queued: queue.length,
        guests: pack?.guests?.length ?? 0,
        packUpdatedAt: pack?.version ?? null,
        attention,
    });
    const emit = () => listeners.forEach((listener) => listener(status()));

    // A storage failure must never stop the door: fall back to memory.
    const safely = async (action) => {
        try {
            return await action(store);
        } catch {
            store = createMemoryStore();
            return action(store);
        }
    };

    const persist = () =>
        safely((s) =>
            Promise.all([
                s.set('queue', queue),
                s.set('local', local),
                s.set('attention', attention),
                s.set('lastSync', lastSync),
            ])
        );

    const dropGuestList = async () => {
        pack = null;
        await safely((s) => s.set('pack', undefined));
    };

    const ready = (async () => {
        await safely(async (s) => {
            pack = (await s.get('pack')) ?? null;
            queue = (await s.get('queue')) ?? [];
            local = (await s.get('local')) ?? {};
            attention = (await s.get('attention')) ?? [];
            lastSync = (await s.get('lastSync')) ?? null;
        });
        // The guest list does not outlive the link; unsent scans wait to be sent.
        if (pack && new Date(pack.ends_at) < now()) {
            expired = true;
            await dropGuestList();
        }
        emit();
    })();

    const request = async (url, options = {}, limit = timeoutMs) => {
        let timer;
        const timeout = new Promise((_, reject) => {
            timer = setTimeout(() => reject(new Unreachable()), limit);
        });
        try {
            const response = await Promise.race([fetcher(url, options), timeout]);
            online = true;
            if (response.status === 410) {
                closed = true;
                await dropGuestList();
                emit();
                throw new Unreachable();
            }
            if (response.status === 403 || response.status === 419) {
                // The PIN unlock or the session has lapsed: carry on offline until re-unlocked.
                locked = true;
                emit();
                throw new Unreachable();
            }
            locked = false;
            return response;
        } catch (error) {
            if (!closed && !locked) {
                online = false;
                emit();
            }
            throw error instanceof Unreachable ? error : new Unreachable();
        } finally {
            clearTimeout(timer);
        }
    };

    const dayOf = (date) =>
        pack && pack.multi_day === false
            ? pack.day
            : new Intl.DateTimeFormat('en-CA', {
                  timeZone: pack?.timezone ?? 'Africa/Accra',
              }).format(date);

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

    const admitOffline = async (guest, scanId, qrHash = null) => {
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
            client_scan_id: scanId,
            registration_id: guest.id,
            scanned_at: at.toISOString(),
            ...(qrHash ? { qr_hash: qrHash } : {}),
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
    const switchedOff = { message: 'This staff link has been switched off.', refused: true };

    const sendQueue = async () => {
        while (queue.length > 0) {
            const batch = queue.slice(0, SYNC_BATCH);
            const response = await request(
                urls.sync,
                {
                    method: 'POST',
                    body: JSON.stringify({
                        scans: batch,
                        since: lastSync,
                        sent_at: now().toISOString(),
                    }),
                },
                SLOW_TIMEOUT_MS
            );
            if (!response.ok) {
                syncError =
                    'Scans could not be sent. They are kept on this phone; tell the organizer.';
                emit();
                return false;
            }
            const body = await response.json();
            const received = new Set(body.results.map((result) => result.client_scan_id));
            queue = queue.filter((scan) => !received.has(scan.client_scan_id));
            attention = [
                ...attention,
                ...body.results
                    .filter((result) => result.outcome === 'refused')
                    .map((result) => ({ id: result.client_scan_id, message: result.message })),
            ];
            lastSync = body.server_time;
            await persist();
            emit();
            if (received.size === 0) break;
        }
        return true;
    };

    // The connection is back: hand over anything queued without waiting for the timer.
    const sendWaitingSoon = () => {
        if (queue.length > 0) door.sync();
    };

    const door = {
        ready,
        status,

        /** What is waiting to be sent; for display and tests. */
        queueSnapshot: () => queue.map((scan) => ({ ...scan })),

        subscribe(listener) {
            listeners.add(listener);
            return () => listeners.delete(listener);
        },

        async dismiss(id) {
            attention = attention.filter((note) => note.id !== id);
            await persist();
            emit();
        },

        async scan(token) {
            await ready;
            // One id for this scan, live or queued, so the server counts it once.
            const scanId = newId();
            try {
                const response = await request(urls.scan, {
                    method: 'POST',
                    body: JSON.stringify({ token, client_scan_id: scanId }),
                });
                sendWaitingSoon();
                return await response.json();
            } catch {
                if (closed) return switchedOff;
                if (!pack) return noList;
                const hash = await sha256Hex(token);
                const guest = pack.guests.find((candidate) => candidate.qr_hash === hash);
                return guest ? admitOffline(guest, scanId, hash) : notOnList;
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
            const scanId = newId();
            try {
                const response = await request(urls.checkIn(id), {
                    method: 'POST',
                    body: JSON.stringify({ client_scan_id: scanId }),
                });
                sendWaitingSoon();
                return await response.json();
            } catch {
                if (closed) return switchedOff;
                const guest = pack?.guests.find((candidate) => candidate.id === id);
                return guest ? admitOffline(guest, scanId) : notOnList;
            }
        },

        async refreshPack() {
            await ready;
            if (closed) return;
            try {
                const response = await request(urls.pack, {}, SLOW_TIMEOUT_MS);
                if (response.ok) {
                    pack = await response.json();
                    await safely((s) => s.set('pack', pack));
                    emit();
                }
            } catch {
                // Offline or closed: keep what is stored.
            }
        },

        /** Send waiting scans, then refresh the guest list. One at a time. */
        sync() {
            if (syncing) return syncing;
            syncing = (async () => {
                await ready;
                try {
                    if (await sendQueue()) {
                        syncError = null;
                        emit();
                        await door.refreshPack();
                    }
                } catch {
                    // Still offline, or locked: the queue waits for the next attempt.
                } finally {
                    syncing = null;
                }
            })();
            return syncing;
        },
    };

    return door;
}
