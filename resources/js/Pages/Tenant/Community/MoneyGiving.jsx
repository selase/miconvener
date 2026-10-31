import { useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import PageHeader from '@/Components/Console/PageHeader';
import Button from '@/Components/Console/Button';
import Input from '@/Components/Console/Input';
import Select from '@/Components/Console/Select';
import Modal from '@/Components/Console/Modal';
import { formatDuesMoney, sendJson, toMinorUnits, toMajorUnits } from '@/lib/dues';

function MemberAttribution({ value, onChange }) {
    const [query, setQuery] = useState(''),
        [members, setMembers] = useState([]),
        [busy, setBusy] = useState(false),
        [message, setMessage] = useState('');
    const generation = useRef(0);
    const search = async () => {
        const request = ++generation.current;
        setBusy(true);
        setMessage('');
        setMembers([]);
        const result = await sendJson(
            'GET',
            `${route('tenant.community.giving.member-options')}?${new URLSearchParams({ q: query })}`
        );
        if (request !== generation.current) return;
        setBusy(false);
        if (result.ok) {
            setMembers(result.body.members);
            if (!result.body.members.length)
                setMessage(
                    'No matching member. You can still record this contribution without membership.'
                );
        } else setMessage(result.message ?? 'Could not search members.');
    };
    return (
        <fieldset className="space-y-3 border border-border p-4">
            <legend className="px-1 text-sm font-medium text-ink">
                Attribute to a member (optional)
            </legend>
            {value ? (
                <>
                    <p className="text-sm text-ink">
                        {value.name} · {value.id.slice(-6)}
                    </p>
                    <Button type="button" variant="default" onClick={() => onChange(null)}>
                        Remove member attribution
                    </Button>
                </>
            ) : (
                <>
                    <Input
                        label="Find member by name"
                        value={query}
                        onChange={(e) => {
                            generation.current++;
                            setQuery(e.target.value);
                            setMembers([]);
                            setBusy(false);
                        }}
                    />
                    <Button
                        type="button"
                        variant="default"
                        disabled={busy || query.trim().length < 2}
                        onClick={search}
                    >
                        {busy ? 'Searching…' : 'Search members'}
                    </Button>
                    <ul className="space-y-2">
                        {members.map((member) => (
                            <li key={member.id}>
                                <Button
                                    type="button"
                                    variant="default"
                                    onClick={() => {
                                        generation.current++;
                                        onChange(member);
                                        setMembers([]);
                                    }}
                                >
                                    {member.name} · {member.id.slice(-6)}
                                </Button>
                            </li>
                        ))}
                    </ul>
                </>
            )}
            <p className="text-xs text-ink-secondary">
                Attribution does not identify the payer, create membership or create dues.
            </p>
            {message && (
                <p role="status" className="text-sm text-ink">
                    {message}
                </p>
            )}
        </fieldset>
    );
}
function GivingForm({ mode, funds, fund, onClose, canAttributeMember, knownGivers, timezone }) {
    const creating = mode === 'create',
        editing = mode === 'edit',
        batch = mode === 'batch',
        cash = mode === 'cash' || batch;
    const [values, setValues] = useState({
        name: fund?.name ?? '',
        slug: fund?.slug ?? '',
        description: fund?.description ?? '',
        currency: fund?.currency ?? 'GHS',
        kind: fund?.kind ?? 'open',
        target: toMajorUnits(fund?.target),
        state: ['open', 'draft'].includes(fund?.state) ? fund.state : 'draft',
        visibility: fund?.visibility ?? 'private',
        progress: fund?.progress ?? 'hidden',
        close_on_target: fund?.close_on_target ?? false,
        ends_on: fund?.ends_on?.slice(0, 10) ?? '',
        button_label: fund?.button_label ?? 'Contribute',
        minimum_amount: toMajorUnits(fund?.minimum_amount ?? 100),
        fund_id: funds.find((f) => f.state === 'open')?.id ?? '',
        amount: '',
        collected_at: '',
        provider_refund_reference: fund?.provider_refund_reference ?? '',
        payer_name: '',
        payer_email: '',
        member_id: '',
        payer_is_member: false,
        identity_confirmed: false,
        reuse_giver_key: '',
        is_anonymous: false,
        reason: '',
        principal: '',
        operation_key: crypto.randomUUID(),
        submission_key: crypto.randomUUID(),
    });
    const [member, setMember] = useState(null);
    const [errors, setErrors] = useState({});
    const [message, setMessage] = useState('');
    const [preview, setPreview] = useState(null);
    const [busy, setBusy] = useState(false);
    const field = (key, label, props = {}) => (
        <Input
            label={label}
            value={values[key]}
            onChange={(e) => {
                setValues({ ...values, [key]: e.target.value });
                setPreview(null);
            }}
            error={errors[key]}
            {...props}
        />
    );
    const select = (key, label, options) => (
        <Select
            label={label}
            value={values[key]}
            onChange={(e) => {
                setValues({ ...values, [key]: e.target.value });
                setPreview(null);
            }}
            error={errors[key]}
        >
            {options.map(([value, text]) => (
                <option key={value} value={value}>
                    {text}
                </option>
            ))}
        </Select>
    );
    const submit = async (e) => {
        e.preventDefault();
        setBusy(true);
        if (mode === 'refund' && preview === null) {
            const query = new URLSearchParams({ principal: toMinorUnits(values.principal) });
            try {
                const result = await sendJson(
                    'GET',
                    `${route('tenant.community.giving.refunds.preview', { type: fund.source_type ?? 'gift', source: fund.id })}?${query}`
                );
                if (result.ok) {
                    setPreview(result.body);
                    setMessage('');
                } else {
                    setErrors(result.errors ?? {});
                    setMessage(result.message ?? 'Check the refund amount.');
                }
            } catch {
                setMessage('Could not load the refund preview. Please try again.');
            } finally {
                setBusy(false);
            }
            return;
        }
        let url,
            payload,
            method = 'POST';
        if (creating || editing) {
            url = route(
                editing
                    ? 'tenant.community.giving.funds.update'
                    : 'tenant.community.giving.funds.store',
                editing ? { fund: fund.id } : {}
            );
            method = editing ? 'PUT' : 'POST';
            payload = {
                ...values,
                target: values.kind === 'campaign' ? toMinorUnits(values.target) : null,
                minimum_amount: toMinorUnits(values.minimum_amount),
                ends_on: values.ends_on || null,
            };
        } else if (cash) {
            const selected = funds.find((f) => f.id === values.fund_id);
            url = route('tenant.community.giving.gifts.store', { fund: values.fund_id });
            payload = {
                submission_key: values.submission_key,
                amount: toMinorUnits(values.amount),
                currency: selected?.currency,
                source: batch ? 'bulk_cash' : 'recorded_cash',
                ...(values.collected_at
                    ? { collected_at: values.collected_at.replace('T', ' ') }
                    : {}),
                ...(batch
                    ? {}
                    : {
                          payer_name: values.payer_name || null,
                          payer_email: values.payer_email || null,
                          member_id: canAttributeMember ? values.member_id || null : null,
                          payer_is_member: canAttributeMember && values.payer_is_member,
                          identity_confirmed:
                              values.identity_confirmed ||
                              (canAttributeMember && values.payer_is_member),
                          reuse_giver_key: values.reuse_giver_key || null,
                          is_anonymous: values.is_anonymous,
                      }),
            };
        } else if (mode === 'reconcile') {
            url = route('tenant.community.giving.refunds.reconcile', { adjustment: fund.id });
            payload = { provider_refund_reference: values.provider_refund_reference || null };
        } else if (mode === 'correct') {
            url = route('tenant.community.giving.gifts.correct', { gift: fund.id });
            payload = {
                principal: toMinorUnits(values.principal),
                reason: values.reason,
                operation_key: values.operation_key,
            };
        } else if (mode === 'refund') {
            url = route('tenant.community.giving.refunds.store', {
                type: fund.source_type ?? 'gift',
                source: fund.id,
            });
            payload = {
                principal: toMinorUnits(values.principal),
                reason: values.reason,
                operation_key: values.operation_key,
            };
        } else {
            url = route(`tenant.community.giving.funds.${mode}`, { fund: fund.id });
            payload = { reason: values.reason };
        }
        try {
            const result = await sendJson(method, url, payload);
            if (!result.ok) {
                setErrors(result.errors ?? {});
                setMessage(result.message ?? 'Check the highlighted fields.');
            } else {
                onClose();
                router.reload({ preserveScroll: true });
            }
        } catch {
            setMessage(
                'The outcome could not be confirmed. Retry with the same details; do not record it twice.'
            );
        } finally {
            setBusy(false);
        }
    };
    const titles = {
        create: 'Create fund',
        edit: 'Edit fund',
        batch: 'Record offering/cash batch',
        cash: 'Record contribution',
        close: 'Close fund',
        reopen: 'Reopen fund',
        archive: 'Archive fund',
        reconcile: 'Check refund status',
        correct: 'Correct recorded cash',
        refund: 'Refund contribution',
    };
    return (
        <Modal open title={titles[mode]} onClose={() => !busy && onClose()}>
            <form onSubmit={submit} className="space-y-4">
                {creating || editing ? (
                    <>
                        {field('name', 'Fund name', { required: true })}
                        {field('slug', 'Give link address', {
                            required: true,
                            hint: 'Use letters, numbers and dashes.',
                        })}
                        {field('description', 'Description')}
                        {field('button_label', 'Contribution button label')}
                        {select(
                            'currency',
                            'Currency',
                            ['GHS', 'USD', 'EUR', 'GBP'].map((c) => [c, c])
                        )}
                        {select('kind', 'Fund type', [
                            ['open', 'Open-ended fund'],
                            ['campaign', 'Campaign with a target'],
                        ])}
                        {values.kind === 'campaign' &&
                            field('target', 'Target amount', {
                                inputMode: 'decimal',
                                required: true,
                            })}
                        {field('minimum_amount', 'Minimum contribution', {
                            inputMode: 'decimal',
                            required: true,
                        })}
                        {field('ends_on', 'Last contribution date (optional)', { type: 'date' })}
                        <label className="flex gap-3 text-sm text-ink">
                            <input
                                type="checkbox"
                                checked={values.close_on_target}
                                onChange={(e) =>
                                    setValues({ ...values, close_on_target: e.target.checked })
                                }
                            />
                            Close automatically when the target is reached
                        </label>
                        {select('state', 'State', [
                            ['draft', 'Draft — not accepting contributions'],
                            ['open', 'Open — accept contributions'],
                        ])}
                        {select('visibility', 'Visibility', [
                            ['private', 'Private — staff only'],
                            ['unlisted', 'Unlisted — anyone with the link'],
                            ['public', 'Public — listed on our give page'],
                        ])}
                        {select('progress', 'Public progress', [
                            ['hidden', 'Hidden'],
                            ['percentage', 'Percentage only'],
                            ['amounts', 'Amounts and target'],
                        ])}
                        <p className="text-xs text-ink-secondary">
                            Opening and sharing are explicit choices. Donor details are never
                            public.
                        </p>
                    </>
                ) : cash ? (
                    <>
                        {select(
                            'fund_id',
                            'Fund',
                            funds
                                .filter((f) => f.state === 'open')
                                .map((f) => [f.id, `${f.name} · ${f.currency}`])
                        )}
                        {field('amount', 'Amount collected', {
                            inputMode: 'decimal',
                            required: true,
                        })}
                        {field('collected_at', 'Collected at (optional)', {
                            type: 'datetime-local',
                            hint: `Time in ${timezone}. Leave blank for now.`,
                        })}
                        {!batch && (
                            <>
                                {field('payer_name', 'Giver name (optional)')}
                                {field('payer_email', 'Giver email (optional)', { type: 'email' })}
                                <label className="flex gap-3 text-sm text-ink">
                                    <input
                                        type="checkbox"
                                        checked={values.identity_confirmed}
                                        onChange={(e) =>
                                            setValues({
                                                ...values,
                                                identity_confirmed: e.target.checked,
                                            })
                                        }
                                    />
                                    I have independently confirmed this giver’s email
                                </label>
                                {knownGivers.length > 0 &&
                                    select(
                                        'reuse_giver_key',
                                        'Same person as a previously recorded giver (optional)',
                                        [
                                            ['', 'Treat as a separate giver'],
                                            ...knownGivers.map((g) => [
                                                g.key,
                                                `${g.name} · ${g.key.slice(-6)}`,
                                            ]),
                                        ]
                                    )}
                                {canAttributeMember && (
                                    <MemberAttribution
                                        value={member}
                                        onChange={(selection) => {
                                            setMember(selection);
                                            setValues({
                                                ...values,
                                                member_id: selection?.id ?? '',
                                                payer_is_member: false,
                                            });
                                        }}
                                    />
                                )}
                                {member && (
                                    <label className="flex gap-3 text-sm text-ink">
                                        <input
                                            type="checkbox"
                                            checked={values.payer_is_member}
                                            onChange={(e) =>
                                                setValues({
                                                    ...values,
                                                    payer_is_member: e.target.checked,
                                                })
                                            }
                                        />
                                        I have confirmed this member is the person who gave
                                    </label>
                                )}
                                <label className="flex gap-3 text-sm text-ink">
                                    <input
                                        type="checkbox"
                                        checked={values.is_anonymous}
                                        onChange={(e) =>
                                            setValues({ ...values, is_anonymous: e.target.checked })
                                        }
                                    />
                                    Anonymous contribution
                                </label>
                            </>
                        )}
                        <p className="text-sm text-ink-secondary">
                            Record money already collected. This updates contribution totals; it
                            does not charge anyone, create dues or add money to your platform payout
                            balance.
                        </p>
                    </>
                ) : mode === 'reconcile' ? (
                    <>
                        {field('provider_refund_reference', 'Provider refund ID (if missing)')}
                        <p className="text-sm text-ink-secondary">
                            This checks the original account and payment. It does not send another
                            refund. Use the refund API ID, not a bank reference.
                        </p>
                    </>
                ) : (
                    <>
                        {['correct', 'refund'].includes(mode) &&
                            field(
                                'principal',
                                `${mode === 'correct' ? 'Reduce recorded amount by' : 'Amount'} (${fund.currency})`,
                                {
                                    inputMode: 'decimal',
                                    required: true,
                                    hint:
                                        mode === 'correct'
                                            ? 'Creates a separate reduction. The original contribution and receipt stay unchanged.'
                                            : undefined,
                                }
                            )}
                        {field('reason', 'Reason', { required: true })}
                        {mode === 'refund' && (
                            <p className="text-sm text-ink-secondary">
                                A refund uses the original payment account and recorded fee terms.
                                Unknown outcomes are held for reconciliation before another refund.
                            </p>
                        )}
                    </>
                )}
                {preview && (
                    <div className="space-y-2 border border-border p-4 text-sm text-ink">
                        <p>
                            Commission reversed:{' '}
                            {formatDuesMoney(preview.commission_reversal, preview.currency)}
                        </p>
                        <p>
                            Original processing fee:{' '}
                            {formatDuesMoney(preview.original_gateway_fee, preview.currency)}. A
                            processor fee is returned only if the provider confirms it.
                        </p>
                        <p>
                            Giving balance reduction:{' '}
                            {formatDuesMoney(preview.payable_impact, preview.currency)}
                        </p>
                        {preview.amount_owed > 0 && (
                            <p role="alert">
                                This refund will leave{' '}
                                {formatDuesMoney(preview.amount_owed, preview.currency)} owed.
                                Further payouts will pause.
                            </p>
                        )}
                    </div>
                )}
                {message && (
                    <p role="alert" className="text-sm text-ink">
                        {message}
                    </p>
                )}
                {Object.entries(errors)
                    .filter(([key]) => !Object.hasOwn(values, key))
                    .map(([key, error]) => (
                        <p key={key} role="alert" className="text-sm text-ink">
                            {error}
                        </p>
                    ))}
                <Button
                    type="submit"
                    variant="primary"
                    disabled={busy || (cash && !values.fund_id)}
                >
                    {busy
                        ? 'Saving…'
                        : mode === 'refund'
                          ? preview
                              ? 'Confirm refund'
                              : 'Preview refund'
                          : titles[mode]}
                </Button>
            </form>
        </Modal>
    );
}
export default function MoneyGiving({
    funds = [],
    report = null,
    timezone = 'the organisation’s timezone',
    delivery = null,
    gifts = [],
    adjustments = [],
    giftPages = null,
    adjustmentPages = null,
    filters = {},
    canAttributeMember = false,
    knownGivers = [],
    canManageGiving = false,
    canReadFinance = false,
    canRefund = false,
    canManageDues = false,
    enabled = false,
}) {
    const [dialog, setDialog] = useState(null);
    const [search, setSearch] = useState(filters.q ?? '');
    const [refundStatus, setRefundStatus] = useState(filters.refund_status ?? 'all');
    const visit = (changes) =>
        router.get(
            route('tenant.community.money.giving.index'),
            { ...filters, ...changes },
            { preserveState: true, preserveScroll: true }
        );
    const pages = (meta, kind, label) =>
        meta &&
        meta.last_page > 1 && (
            <nav
                aria-label={`${label} pages`}
                className="mt-4 flex flex-wrap items-center gap-3 text-sm"
            >
                <Button
                    variant="default"
                    disabled={meta.current_page <= 1}
                    onClick={() => visit({ [kind]: meta.current_page - 1 })}
                >
                    Previous {label} page
                </Button>
                <span className="text-ink-secondary">
                    Page {meta.current_page} of {meta.last_page} · {meta.total} records
                </span>
                <Button
                    variant="default"
                    disabled={meta.current_page >= meta.last_page}
                    onClick={() => visit({ [kind]: meta.current_page + 1 })}
                >
                    Next {label} page
                </Button>
            </nav>
        );
    return (
        <ConsoleLayout>
            <PageHeader
                title="Money"
                tabs={[
                    {
                        key: 'giving',
                        label: 'Giving',
                        href: route('tenant.community.money.giving.index'),
                    },
                    ...(canManageDues
                        ? [
                              {
                                  key: 'dues',
                                  label: 'Dues',
                                  href: route('tenant.community.money.dues.index'),
                              },
                          ]
                        : []),
                ]}
                activeTab="giving"
                actions={
                    canManageGiving &&
                    enabled && (
                        <Button onClick={() => setDialog({ mode: 'create' })}>Create fund</Button>
                    )
                }
            />
            <main className="space-y-8 px-4 py-6 sm:px-8">
                {canReadFinance && delivery && (
                    <section
                        className="border border-border bg-surface p-5"
                        aria-label="Giving email delivery"
                    >
                        <h2 className="font-semibold text-ink">Giving email delivery</h2>
                        <p className="mt-2 text-sm text-ink-secondary">
                            Receipts are service acknowledgements. Optional giving reminders use
                            email credits; no SMS or automatic payments.
                        </p>
                        <div className="mt-4 grid gap-4 sm:grid-cols-2">
                            {[
                                ['Receipts', delivery.receipts],
                                ['Reminders', delivery.reminders],
                            ].map(([label, states]) => (
                                <div key={label}>
                                    <h3 className="text-sm font-medium text-ink">{label}</h3>
                                    <p className="mt-2 text-sm text-ink-secondary">
                                        {Object.entries(states ?? {})
                                            .map(
                                                ([state, count]) =>
                                                    `${state.replaceAll('_', ' ')}: ${count}`
                                            )
                                            .join(' · ') || 'No deliveries yet'}
                                    </p>
                                </div>
                            ))}
                        </div>
                        <p className="mt-3 text-xs text-ink-secondary">
                            No credit: add email credits for future reminders. Outcome unknown: do
                            not resend blindly. Retryable receipts are retried by the queue; a
                            sending receipt interrupted by a worker needs operator review.
                        </p>
                    </section>
                )}

                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-widest text-accent">
                            Voluntary contributions
                        </p>
                        <h2 className="mt-2 text-2xl font-semibold text-ink">
                            Support the work you share
                        </h2>
                        <p className="mt-3 max-w-2xl text-sm text-ink-secondary">
                            Offerings, projects and community funds, with or without membership or
                            dues. Recorded cash and online collections stay distinct.
                        </p>
                    </div>
                    {canReadFinance && (
                        <a
                            className="text-sm text-accent underline"
                            href={route('tenant.community.money.giving.report')}
                        >
                            View giving report
                        </a>
                    )}
                </div>
                {!enabled && (
                    <p
                        role="status"
                        className="border border-border p-4 text-sm text-ink-secondary"
                    >
                        New Giving collections are paused. Existing history, refunds and payouts
                        remain available.
                    </p>
                )}
                {canReadFinance &&
                    enabled &&
                    canManageGiving &&
                    funds.some((f) => f.state === 'open') && (
                        <div className="flex flex-wrap gap-3">
                            <Button onClick={() => setDialog({ mode: 'cash' })}>
                                Record contribution
                            </Button>
                            <Button onClick={() => setDialog({ mode: 'batch' })}>
                                Record offering/cash batch
                            </Button>
                        </div>
                    )}
                {report &&
                    Object.entries(report).map(([currency, row]) => (
                        <section key={currency} className="border border-border bg-surface p-5">
                            <h3 className="font-semibold text-ink">This month · {currency}</h3>
                            {row.undated_legacy_refunds > 0 && (
                                <p role="alert" className="mt-4 text-sm text-ink">
                                    Legacy refunds of{' '}
                                    {formatDuesMoney(row.undated_legacy_refunds, currency)} have no
                                    recorded refund date. They are excluded from dated net totals
                                    and need reconciliation.
                                </p>
                            )}
                            <div className="mt-4 grid gap-4 sm:grid-cols-3">
                                {[
                                    ['collected_online', 'Collected online'],
                                    ['recorded_cash', 'Recorded cash'],
                                    ['refunds', 'Refunds'],
                                    ['corrections', 'Cash corrections'],
                                    ['net_online', 'Net online'],
                                    ['available', 'Available for payout now'],
                                ].map(([key, label]) => (
                                    <div key={key}>
                                        <p className="text-xs text-ink-secondary">{label}</p>
                                        <p className="mt-1 font-semibold text-ink">
                                            {formatDuesMoney(row[key], currency)}
                                        </p>
                                    </div>
                                ))}
                            </div>
                            <p className="mt-4 text-xs text-ink-secondary">
                                Available is the pooled Giving balance now, across all funds. It
                                excludes recorded cash and money settled directly to your gateway.
                            </p>
                            <a
                                className="mt-3 inline-block text-sm text-accent underline"
                                href={route('tenant.finance.payouts.index', { category: 'giving' })}
                            >
                                Giving payouts
                            </a>
                        </section>
                    ))}
                {funds.length === 0 ? (
                    <section className="border border-dashed border-border p-8">
                        <h3 className="text-lg font-semibold text-ink">No funds yet</h3>
                        <p className="mt-3 text-sm text-ink-secondary">
                            Create an Offering, Building Fund or Volunteer Project. You can start
                            with a private draft and share it when you are ready.
                        </p>
                    </section>
                ) : (
                    <div className="grid gap-5 lg:grid-cols-2">
                        {funds.map((fund) => (
                            <section key={fund.id} className="border border-border bg-surface p-5">
                                <p className="text-xs uppercase tracking-wider text-ink-secondary">
                                    {fund.state} · {fund.visibility} · {fund.currency}
                                </p>
                                <h3 className="mt-3 text-xl font-semibold text-ink">{fund.name}</h3>
                                <p className="mt-3 line-clamp-3 text-sm text-ink-secondary">
                                    {fund.description}
                                </p>
                                {fund.totals && (
                                    <p className="mt-4 text-lg font-semibold text-ink">
                                        {formatDuesMoney(fund.totals.raised, fund.currency)}{' '}
                                        <span className="text-xs font-normal text-ink-secondary">
                                            contributed after refunds and corrections
                                        </span>
                                    </p>
                                )}
                                <div className="mt-5 flex flex-wrap gap-3">
                                    {fund.visibility !== 'private' &&
                                        ['open', 'closed'].includes(fund.state) && (
                                            <a
                                                className="self-center text-sm text-accent underline"
                                                href={fund.url}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                            >
                                                Open give link
                                            </a>
                                        )}
                                    {canManageGiving && enabled && (
                                        <>
                                            <Button
                                                onClick={() => setDialog({ mode: 'edit', fund })}
                                            >
                                                Edit
                                            </Button>
                                            {fund.state === 'open' && (
                                                <Button
                                                    onClick={() =>
                                                        setDialog({ mode: 'close', fund })
                                                    }
                                                >
                                                    Close
                                                </Button>
                                            )}
                                            {fund.state === 'closed' && (
                                                <Button
                                                    onClick={() =>
                                                        setDialog({ mode: 'reopen', fund })
                                                    }
                                                >
                                                    Reopen
                                                </Button>
                                            )}
                                            {fund.state !== 'archived' && (
                                                <Button
                                                    onClick={() =>
                                                        setDialog({ mode: 'archive', fund })
                                                    }
                                                >
                                                    Archive
                                                </Button>
                                            )}
                                        </>
                                    )}
                                </div>
                            </section>
                        ))}
                    </div>
                )}
                {canReadFinance && (
                    <form
                        aria-label="Search Giving history"
                        onSubmit={(e) => {
                            e.preventDefault();
                            visit({
                                q: search,
                                refund_status: refundStatus,
                                gifts_page: 1,
                                adjustments_page: 1,
                            });
                        }}
                        className="grid gap-4 border border-border p-5 sm:grid-cols-3"
                    >
                        <Input
                            label="Find contribution"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            hint="Search by fund or record ID. Donor contact details stay private."
                        />
                        <Select
                            label="Refund status"
                            value={refundStatus}
                            onChange={(e) => setRefundStatus(e.target.value)}
                        >
                            <option value="all">All refunds and corrections</option>
                            <option value="unresolved">Unresolved refunds</option>
                        </Select>
                        <Button type="submit" className="self-end">
                            Search history
                        </Button>
                    </form>
                )}
                {canReadFinance && (
                    <section className="border border-border p-5">
                        <h3 className="text-lg font-semibold text-ink">Contribution history</h3>
                        <p className="mt-2 text-xs text-ink-secondary">
                            Standalone and event contributions, with their original source
                            preserved. Use search or the page controls to find older records.
                        </p>
                        <ul className="mt-4 divide-y divide-border">
                            {gifts.map((gift) => (
                                <li
                                    key={`${gift.source_type ?? 'gift'}:${gift.id}`}
                                    className="flex flex-wrap items-center justify-between gap-3 py-4"
                                >
                                    <div>
                                        <p className="font-medium text-ink">
                                            {gift.fund_name} ·{' '}
                                            {formatDuesMoney(gift.amount, gift.currency)}
                                        </p>
                                        <p className="mt-1 text-xs text-ink-secondary">
                                            {gift.is_anonymous
                                                ? ['Anonymous publicly', gift.payer_name]
                                                      .filter(Boolean)
                                                      .join(' · ')
                                                : gift.payer_name || 'Unnamed giver'}{' '}
                                            ·{' '}
                                            {gift.source === 'online'
                                                ? 'Online'
                                                : gift.source === 'bulk_cash'
                                                  ? 'Offering batch'
                                                  : 'Recorded cash'}{' '}
                                            · {gift.status}
                                        </p>
                                    </div>
                                    {canManageGiving &&
                                        gift.source !== 'online' &&
                                        gift.status === 'completed' && (
                                            <Button
                                                onClick={() =>
                                                    setDialog({ mode: 'correct', fund: gift })
                                                }
                                            >
                                                Correct
                                            </Button>
                                        )}
                                    {canRefund &&
                                        gift.can_refund !== false &&
                                        gift.source === 'online' &&
                                        gift.status === 'completed' && (
                                            <Button
                                                onClick={() =>
                                                    setDialog({ mode: 'refund', fund: gift })
                                                }
                                            >
                                                Refund
                                            </Button>
                                        )}
                                </li>
                            ))}
                        </ul>
                        {gifts.length === 0 && (
                            <p className="mt-4 text-sm text-ink-secondary">
                                No matching contributions.
                            </p>
                        )}
                        {pages(giftPages, 'gifts_page', 'contribution')}
                    </section>
                )}
                {canReadFinance && (
                    <section className="border border-border p-5">
                        <h3 className="text-lg font-semibold text-ink">Refunds and corrections</h3>
                        <ul className="mt-4 divide-y divide-border">
                            {adjustments.map((row) => (
                                <li
                                    key={row.id}
                                    className="flex flex-wrap items-center justify-between gap-3 py-4"
                                >
                                    <div>
                                        <p className="font-medium text-ink">
                                            {row.fund_name} ·{' '}
                                            {formatDuesMoney(row.principal, row.currency)}
                                        </p>
                                        <p className="mt-1 text-xs text-ink-secondary">
                                            {row.kind === 'refund' ? 'Refund' : 'Cash correction'} ·{' '}
                                            {row.status}
                                        </p>
                                        {['requested', 'processing', 'unknown'].includes(
                                            row.status
                                        ) && (
                                            <p className="mt-1 text-xs text-ink-secondary">
                                                Outcome pending. Reserved money remains unavailable
                                                for payout.
                                            </p>
                                        )}
                                    </div>
                                    {canRefund &&
                                        row.kind === 'refund' &&
                                        ['requested', 'processing', 'unknown'].includes(
                                            row.status
                                        ) && (
                                            <Button
                                                onClick={() =>
                                                    setDialog({ mode: 'reconcile', fund: row })
                                                }
                                            >
                                                Check refund status
                                            </Button>
                                        )}
                                </li>
                            ))}
                        </ul>
                        {adjustments.length === 0 && (
                            <p className="mt-4 text-sm text-ink-secondary">
                                No matching refunds or corrections.
                            </p>
                        )}
                        {pages(adjustmentPages, 'adjustments_page', 'adjustment')}
                    </section>
                )}
                {dialog && (
                    <GivingForm
                        {...dialog}
                        funds={funds}
                        canAttributeMember={canAttributeMember}
                        knownGivers={knownGivers}
                        timezone={timezone}
                        onClose={() => setDialog(null)}
                    />
                )}
            </main>
        </ConsoleLayout>
    );
}
