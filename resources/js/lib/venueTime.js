const PARTS = ['year', 'month', 'day', 'hour', 'minute'];

function zoneParts(instant, timeZone) {
    const formatter = new Intl.DateTimeFormat('en-GB', {
        timeZone,
        hourCycle: 'h23',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
    const parts = Object.fromEntries(
        formatter.formatToParts(instant).map((part) => [part.type, part.value])
    );

    return PARTS.map((name) => Number(parts[name]));
}

/** Minutes the zone is ahead of UTC at this instant. */
function offsetMinutes(instant, timeZone) {
    const [year, month, day, hour, minute] = zoneParts(instant, timeZone);

    return Math.round((Date.UTC(year, month - 1, day, hour, minute) - instant.getTime()) / 60000);
}

function formatOffset(minutes) {
    const sign = minutes < 0 ? '-' : '+';
    const absolute = Math.abs(minutes);

    return `${sign}${String(Math.floor(absolute / 60)).padStart(2, '0')}:${String(absolute % 60).padStart(2, '0')}`;
}

/**
 * Turn a wall-clock "YYYY-MM-DDTHH:mm" in an IANA zone into an ISO 8601
 * timestamp carrying that zone's UTC offset. A time that does not exist or
 * happens twice around a clock change is refused, never guessed.
 *
 * @returns {{ok: true, iso: string} | {ok: false, reason: 'invalid'|'missing'|'ambiguous'}}
 */
export function localToOffsetIso(local, timeZone) {
    const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/.exec(local ?? '');
    if (!match) {
        return { ok: false, reason: 'invalid' };
    }
    const wanted = match.slice(1).map(Number);
    const wallAsUtc = Date.UTC(wanted[0], wanted[1] - 1, wanted[2], wanted[3], wanted[4]);

    try {
        const offsets = new Set();
        for (const probeHours of [-26, -2, 0, 2, 26]) {
            offsets.add(offsetMinutes(new Date(wallAsUtc + probeHours * 3600000), timeZone));
        }

        const instants = [...offsets]
            .map((offset) => wallAsUtc - offset * 60000)
            .filter((utc) => {
                const shown = zoneParts(new Date(utc), timeZone);

                return shown.every((value, index) => value === wanted[index]);
            });
        const distinct = [...new Set(instants)];

        if (distinct.length === 0) {
            return { ok: false, reason: 'missing' };
        }
        if (distinct.length > 1) {
            return { ok: false, reason: 'ambiguous' };
        }

        const offset = offsetMinutes(new Date(distinct[0]), timeZone);

        return { ok: true, iso: `${local}:00${formatOffset(offset)}` };
    } catch {
        return { ok: false, reason: 'invalid' };
    }
}

/** An instant as venue-local wall-clock text, naming the zone. */
export function formatInZone(iso, timeZone) {
    const text = new Intl.DateTimeFormat('en-GB', {
        timeZone,
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
    }).format(new Date(iso));

    return `${text} (${timeZone})`;
}
