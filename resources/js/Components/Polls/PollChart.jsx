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
    return (
        <div className="h-full w-full" style={{ containerType: 'size' }}>
            <Chart poll={poll} />
        </div>
    );
}

function Chart({ poll }) {
    const closed = poll.status === 'closed';

    // Before anything else: a type that describes individuals in a small room
    // arrives with its detail already removed, and says why rather than
    // drawing empty bars that look like nobody answered.
    if (poll.suppressed) {
        return <NotEnough total={poll.total_responses} needed={poll.suppress_below ?? 5} />;
    }

    switch (poll.type) {
        case 'open':
            return <OpenWall responses={poll.open_responses} />;
        case 'yes_no':
            return <YesNo options={poll.options} />;
        case 'rating':
            return <Rating options={poll.options} summary={poll.summary} />;
        case 'scale':
            return <Scale settings={poll.settings} summary={poll.summary} />;
        case 'number':
            return <NumberStats settings={poll.settings} summary={poll.summary} />;
        case 'word_cloud':
            return <WordCloud words={poll.summary?.words ?? []} />;
        case 'ranking':
            return <Ranking options={poll.options} respondents={poll.summary?.respondents ?? 0} />;
        case 'multi_select':
            return (
                <div className="flex h-full flex-col">
                    <p className="shrink-0 text-white/50" style={{ fontSize: 'min(1.6cqw, 3.6cqh)' }}>
                        Share of the {poll.summary?.respondents ?? 0} people who answered. Each could pick more
                        than one.
                    </p>
                    <div className="min-h-0 flex-1">
                        <Bars options={poll.options} closed={closed} />
                    </div>
                </div>
            );
        default:
            return <Bars options={poll.options} closed={closed} />;
    }
}

function NotEnough({ total, needed }) {
    return (
        <div className="flex h-full flex-col items-start justify-center">
            <p className="text-white/80" style={{ fontSize: 'min(3cqw, 9cqh)' }}>
                Not enough responses yet
            </p>
            <p className="mt-[2cqh] text-white/45" style={{ fontSize: 'min(1.8cqw, 5cqh)' }}>
                Results appear once {needed} people have answered, so no one's answer can be picked out.{' '}
                {total} so far.
            </p>
        </div>
    );
}

/**
 * One bar split in two, because a yes/no question is a single proportion and
 * two separate bars would ask the room to compare lengths to find it.
 */
function YesNo({ options }) {
    const [yes, no] = options;
    const total = (yes?.count ?? 0) + (no?.count ?? 0);
    const yesShare = total > 0 ? yes.percentage : 50;

    return (
        <div className="flex h-full flex-col justify-center gap-[4cqh]">
            <div className="flex items-end justify-between" style={{ fontSize: 'min(5cqw, 14cqh)' }}>
                <span className="font-light text-emerald-300">
                    {yes?.percentage ?? 0}%<span className="ml-[1cqw] text-[0.4em] text-white/60">Yes</span>
                </span>
                <span className="font-light text-rose-300">
                    <span className="mr-[1cqw] text-[0.4em] text-white/60">No</span>
                    {no?.percentage ?? 0}%
                </span>
            </div>
            <div className="flex w-full overflow-hidden rounded-full bg-white/10" style={{ height: 'min(9cqh, 6cqw)' }}>
                <div className="h-full bg-emerald-400 transition-[width] duration-700" style={{ width: `${total > 0 ? yesShare : 0}%` }} />
                <div className="h-full flex-1 bg-rose-400/80" style={{ opacity: total > 0 ? 1 : 0 }} />
            </div>
            <p className="text-white/45" style={{ fontSize: 'min(1.6cqw, 4cqh)' }}>
                {yes?.count ?? 0} yes · {no?.count ?? 0} no
            </p>
        </div>
    );
}

/**
 * The average large enough to read from the back, with the spread beside it:
 * an average of 3 can be a room of 3s or a room split between 1 and 5.
 */
function Rating({ options, summary }) {
    const average = summary?.average ?? 0;
    const outOf = summary?.out_of ?? 5;
    const highest = Math.max(1, ...options.map((o) => o.count ?? 0));

    return (
        <div className="grid h-full grid-cols-[auto_1fr] items-center gap-[5cqw]">
            <div className="text-center">
                <p className="font-light leading-none tabular-nums" style={{ fontSize: 'min(13cqw, 38cqh)' }}>
                    {average.toFixed(1)}
                </p>
                <p className="mt-[2cqh] text-amber-300" style={{ fontSize: 'min(3.2cqw, 9cqh)' }}>
                    {'★'.repeat(Math.round(average))}
                    <span className="text-white/20">{'★'.repeat(Math.max(0, outOf - Math.round(average)))}</span>
                </p>
                <p className="text-white/50" style={{ fontSize: 'min(1.5cqw, 4cqh)' }}>
                    out of {outOf}
                </p>
            </div>
            <ol className="flex h-full flex-col-reverse justify-evenly">
                {options.map((option) => (
                    <li key={option.id} className="grid grid-cols-[auto_1fr_auto] items-center gap-[1.5cqw]" style={{ fontSize: 'min(1.8cqw, 5cqh)' }}>
                        <span className="text-white/70">{option.label}★</span>
                        <div className="overflow-hidden rounded-full bg-white/10" style={{ height: 'min(4cqh, 2.4cqw)' }}>
                            <div className="h-full rounded-full bg-amber-300 transition-[width] duration-700" style={{ width: `${((option.count ?? 0) / highest) * 100}%` }} />
                        </div>
                        <span className="tabular-nums text-white/55">{option.count ?? 0}</span>
                    </li>
                ))}
            </ol>
        </div>
    );
}

/**
 * The whole line, with how many chose each point stacked above it and the
 * average marked beneath, so where the room sits and how widely it spreads are
 * both visible at once.
 */
function Scale({ settings, summary }) {
    const min = settings?.min ?? 1;
    const max = settings?.max ?? 10;
    const distribution = summary?.distribution ?? [];
    const highest = Math.max(1, ...distribution.map((d) => d.count));
    const average = summary?.average ?? min;
    const at = max > min ? ((average - min) / (max - min)) * 100 : 50;

    return (
        <div className="flex h-full flex-col justify-center">
            <div className="flex items-end gap-[0.8cqw]" style={{ height: '48cqh' }}>
                {distribution.map((point) => (
                    <div key={point.value} className="flex h-full flex-1 flex-col items-center justify-end">
                        <span className="mb-[1cqh] tabular-nums text-white/55" style={{ fontSize: 'min(1.4cqw, 3.6cqh)' }}>
                            {point.count > 0 ? point.count : ''}
                        </span>
                        <div className="w-full rounded-t bg-sky-300/80 transition-[height] duration-700" style={{ height: `${(point.count / highest) * 100}%` }} />
                    </div>
                ))}
            </div>
            <div className="relative mt-[1cqh] h-[2px] w-full bg-white/40">
                <div className="absolute -translate-x-1/2 text-amber-300" style={{ left: `${at}%`, top: '0.4cqh', fontSize: 'min(2.4cqw, 6cqh)' }}>
                    ▲
                </div>
            </div>
            <div className="mt-[1cqh] flex gap-[0.8cqw]" style={{ fontSize: 'min(1.4cqw, 3.6cqh)' }}>
                {distribution.map((point) => (
                    <span key={point.value} className="flex-1 text-center tabular-nums text-white/50">
                        {point.value}
                    </span>
                ))}
            </div>
            <div className="mt-[4cqh] flex items-baseline justify-between" style={{ fontSize: 'min(1.8cqw, 4.6cqh)' }}>
                <span className="text-white/60">{settings?.label_min}</span>
                <span className="text-amber-300">
                    Average <span className="tabular-nums">{average.toFixed(1)}</span>
                </span>
                <span className="text-white/60">{settings?.label_max}</span>
            </div>
        </div>
    );
}

/**
 * Three numbers a room can read, then the shape behind them. A median beside
 * the average shows when a few large answers are pulling it up.
 */
function NumberStats({ settings, summary }) {
    const unit = settings?.unit ? ` ${settings.unit}` : '';
    const histogram = summary?.histogram ?? [];
    const highest = Math.max(1, ...histogram.map((b) => b.count));
    const fmt = (n) => Number(n ?? 0).toLocaleString(undefined, { maximumFractionDigits: 2 });

    const stats = [
        ['Average', fmt(summary?.average)],
        ['Median', fmt(summary?.median)],
        ['Range', `${fmt(summary?.minimum)} – ${fmt(summary?.maximum)}`],
    ];

    return (
        <div className="flex h-full flex-col">
            <div className="grid shrink-0 grid-cols-3 gap-[3cqw]">
                {stats.map(([label, value]) => (
                    <div key={label}>
                        <p className="uppercase tracking-[0.18em] text-white/45" style={{ fontSize: 'min(1.3cqw, 3.4cqh)' }}>
                            {label}
                        </p>
                        <p className="font-light tabular-nums" style={{ fontSize: 'min(4.2cqw, 12cqh)' }}>
                            {value}
                            <span className="text-[0.35em] text-white/50">{unit}</span>
                        </p>
                    </div>
                ))}
            </div>
            <div className="mt-[5cqh] flex min-h-0 flex-1 items-end gap-[1cqw]">
                {histogram.map((bucket) => (
                    <div key={`${bucket.from}-${bucket.to}`} className="flex h-full flex-1 flex-col justify-end">
                        <span className="mb-[1cqh] text-center tabular-nums text-white/55" style={{ fontSize: 'min(1.4cqw, 3.4cqh)' }}>
                            {bucket.count}
                        </span>
                        <div className="w-full rounded-t bg-violet-300/80 transition-[height] duration-700" style={{ height: `${(bucket.count / highest) * 100}%` }} />
                        <span className="mt-[1cqh] truncate text-center tabular-nums text-white/40" style={{ fontSize: 'min(1.1cqw, 3cqh)' }}>
                            {fmt(bucket.from)}–{fmt(bucket.to)}
                        </span>
                    </div>
                ))}
            </div>
        </div>
    );
}

/**
 * Size follows how many people used a word. Most-used words come first in the
 * list, so when space runs out it is the rarest that fall off, not the
 * commonest.
 */
function WordCloud({ words }) {
    if (words.length === 0) {
        return (
            <p className="text-white/40" style={{ fontSize: 'min(2.4cqw, 6cqh)' }}>
                Words will appear here as people send them.
            </p>
        );
    }

    const highest = Math.max(...words.map((w) => w.count));
    const shades = ['text-white', 'text-sky-200', 'text-amber-200', 'text-emerald-200', 'text-rose-200'];

    return (
        <ul className="flex h-full flex-wrap content-center items-center justify-center gap-x-[2.4cqw] gap-y-[1cqh] overflow-hidden">
            {words.map((word, i) => {
                const weight = word.count / highest;
                return (
                    <li
                        key={word.text}
                        className={`leading-none ${shades[i % shades.length]}`}
                        style={{
                            fontSize: `min(${(1.6 + weight * 5.4).toFixed(2)}cqw, ${(4 + weight * 14).toFixed(2)}cqh)`,
                            fontWeight: weight > 0.6 ? 500 : 300,
                            opacity: 0.55 + weight * 0.45,
                        }}
                        title={`${word.count}`}
                    >
                        {word.text}
                    </li>
                );
            })}
        </ul>
    );
}

/**
 * The room's order, with the average position each option earned, and how
 * many people that average stands on.
 */
function Ranking({ options, respondents }) {
    const n = options.length;

    return (
        <div className="flex h-full flex-col">
            <p className="shrink-0 text-white/50" style={{ fontSize: 'min(1.6cqw, 3.8cqh)' }}>
                Ranked by {respondents} {respondents === 1 ? 'person' : 'people'}. Shorter average = nearer the top.
            </p>
            <ol className="mt-[2cqh] flex min-h-0 flex-1 flex-col justify-evenly">
                {options.map((option, i) => {
                    const avg = option.average_position;
                    const strength = avg ? ((n - avg + 1) / n) * 100 : 0;
                    return (
                        <li
                            key={option.id}
                            className="grid grid-cols-[auto_minmax(0,30%)_1fr_auto] items-center gap-[1.6cqw]"
                            style={{ fontSize: `min(2.2cqw, ${(60 / Math.max(n, 1)).toFixed(2)}cqh)` }}
                        >
                            <span className="tabular-nums text-amber-300">{i + 1}</span>
                            <span className="truncate">{option.label}</span>
                            <div className="overflow-hidden rounded-full bg-white/10" style={{ height: 'min(3cqh, 2cqw)' }}>
                                <div className="h-full rounded-full bg-white transition-[width] duration-700" style={{ width: `${strength}%` }} />
                            </div>
                            <span className="tabular-nums text-white/55">{avg ? `avg ${avg.toFixed(1)}` : '—'}</span>
                        </li>
                    );
                })}
            </ol>
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
