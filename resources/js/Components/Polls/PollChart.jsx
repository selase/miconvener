/**
 * The results of one question, drawn to fit whatever box it is given.
 *
 * Shared by the wall behind the speaker and the presenter's own screen, so the
 * two can never disagree about what the room said. It sizes itself with
 * container query units rather than the viewport, which is what lets the same
 * chart fill a projector and sit in the corner of a laptop, and what keeps an
 * eleven-option question on screen without scrolling: nobody at the back of a
 * hall can scroll the wall.
 */
export default function PollChart({ poll }) {
    const closed = poll.status === 'closed';

    return (
        <div className="h-full w-full" style={{ containerType: 'size' }}>
            {poll.type === 'open' ? (
                <OpenWall responses={poll.open_responses} />
            ) : (
                <Bars options={poll.options} closed={closed} />
            )}
        </div>
    );
}

/**
 * How tightly to pack a question's rows. Few options get room to breathe and a
 * label above each bar; many options drop to one line per option, label beside
 * the bar, because stacked rows are what pushed a scale past the fold.
 *
 * @return {{ compact: boolean, label: string, bar: string, gap: string }}
 */
export function densityFor(count) {
    const rows = Math.max(count, 1);
    // Each row's share of the chart's height, in container units.
    const share = 100 / rows;

    if (rows >= 8) {
        return {
            compact: true,
            label: `min(2.2cqw, ${(share * 0.42).toFixed(2)}cqh, 4.5cqh)`,
            bar: `min(${(share * 0.34).toFixed(2)}cqh, 3cqh)`,
            gap: '0',
        };
    }

    return {
        compact: false,
        label: `min(3.2cqw, ${(share * 0.3).toFixed(2)}cqh, 7cqh)`,
        bar: `min(${(share * 0.2).toFixed(2)}cqh, 5.5cqh)`,
        gap: `min(${(share * 0.06).toFixed(2)}cqh, 1.4cqh)`,
    };
}

function Bars({ options, closed }) {
    const density = densityFor(options.length);

    return (
        <ol className="flex h-full flex-col justify-evenly">
            {options.map((option) => (
                <Bar key={option.id} option={option} closed={closed} density={density} />
            ))}
        </ol>
    );
}

function Bar({ option, closed, density }) {
    // Green only once voting is closed: colouring the right answer while people
    // are still choosing would give it away from the back of the room.
    const correct = closed && option.is_correct;
    const width = `${Math.max(option.percentage, option.count > 0 ? 2 : 0)}%`;

    const label = (
        <span className={`truncate ${correct ? 'text-emerald-300' : 'text-white'}`}>
            {option.label}
            {correct && <span className="ml-[1cqw] text-[0.7em]">correct</span>}
        </span>
    );

    const figures = (
        <span className="shrink-0 tabular-nums text-white/60">
            {option.percentage}% <span className="text-[0.65em]">({option.count})</span>
        </span>
    );

    const track = (
        <div
            className="w-full overflow-hidden rounded-full bg-white/10"
            style={{ height: density.bar }}
        >
            <div
                className={`h-full rounded-full transition-[width] duration-700 ease-out ${
                    correct ? 'bg-emerald-400' : 'bg-white'
                }`}
                style={{ width }}
            />
        </div>
    );

    if (density.compact) {
        return (
            <li
                className="grid grid-cols-[minmax(0,30%)_1fr_auto] items-center gap-[1.5cqw]"
                style={{ fontSize: density.label }}
            >
                {label}
                {track}
                {figures}
            </li>
        );
    }

    return (
        <li style={{ fontSize: density.label }}>
            <div className="flex items-baseline justify-between gap-[2cqw]">
                {label}
                {figures}
            </div>
            <div style={{ marginTop: density.gap }}>{track}</div>
        </li>
    );
}

/**
 * Newest first, and cut off at the bottom rather than scrolled: the wall shows
 * as many of the latest answers as fit, and an older one falls away as a new
 * one arrives.
 */
function OpenWall({ responses }) {
    if (!responses || responses.length === 0) {
        return (
            <p className="text-white/40" style={{ fontSize: 'min(2.4cqw, 6cqh)' }}>
                Answers will appear here as they arrive.
            </p>
        );
    }

    return (
        <div className="relative h-full overflow-hidden">
            <ul className="grid grid-cols-3 gap-[1.2cqw]">
                {responses.map((response) => (
                    <li
                        key={response.id}
                        className="rounded-2xl bg-white/5 p-[1.4cqw] leading-snug"
                        style={{ fontSize: 'min(1.9cqw, 4.2cqh)' }}
                    >
                        {response.text}
                        {response.name && (
                            <span className="mt-[0.6cqh] block text-[0.72em] text-white/40">
                                {response.name}
                            </span>
                        )}
                    </li>
                ))}
            </ul>
            <div className="pointer-events-none absolute inset-x-0 bottom-0 h-[12cqh] bg-gradient-to-t from-neutral-950 to-transparent" />
        </div>
    );
}
