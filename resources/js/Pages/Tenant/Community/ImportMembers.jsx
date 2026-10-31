import { useState } from 'react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import PageHeader from '@/Components/Console/PageHeader';
import Button from '@/Components/Console/Button';
import csrfFetch, { csrfFetchFormData } from '@/lib/csrfFetch';
import { Table, Thead, Th, Td, TableEmpty } from '@/Components/Console/Table';

const STATUSES = {
    new: 'New',
    existing: 'Existing',
    duplicate: 'Duplicate',
    error: 'Error',
    review_required: 'Review required',
};
const PAGE_SIZE = 100;

export default function ImportMembers() {
    const [file, setFile] = useState(null);
    const [preview, setPreview] = useState(null);
    const [result, setResult] = useState(null);
    const [filter, setFilter] = useState('all');
    const [page, setPage] = useState(1);
    const [policy, setPolicy] = useState('skip');
    const [decisions, setDecisions] = useState({});
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    async function request(action, body) {
        const isUpload = body instanceof FormData;
        const url = route(`tenant.community.people.import.${action}`);
        const response = await (isUpload
            ? csrfFetchFormData(url, body)
            : csrfFetch(url, { method: 'POST', body: JSON.stringify(body) }));
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            if (action === 'confirm' && [404, 410, 422].includes(response.status)) {
                setPreview(null);
            }
            const message =
                Object.values(data.errors ?? {}).flat()[0] ??
                data.message ??
                'The import could not be completed. Please try again.';
            throw new Error(
                `${message}${action === 'confirm' && response.status !== 409 ? ' Preview the spreadsheet again to retry.' : ''}`
            );
        }
        return data;
    }

    async function upload(event) {
        event.preventDefault();
        setBusy(true);
        setError('');
        setPreview(null);
        setDecisions({});
        setResult(null);
        try {
            const body = new FormData();
            body.append('file', file);
            setPreview(await request('preview', body));
            setPage(1);
            setFilter('all');
        } catch (failure) {
            setError(failure.message);
        } finally {
            setBusy(false);
        }
    }

    async function confirm() {
        setBusy(true);
        setError('');
        try {
            setResult(
                await request('confirm', {
                    token: preview.token,
                    existing_policy: policy,
                    decisions,
                })
            );
            setPreview(null);
            setPage(1);
        } catch (failure) {
            setError(failure.message);
        } finally {
            setBusy(false);
        }
    }

    const rows = preview?.rows.filter((row) => filter === 'all' || row.status === filter) ?? [];
    const resultIssues =
        result?.rows?.filter((row) => ['skipped', 'error'].includes(row.status)) ?? [];
    const shown = rows.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE);
    const reviewRows = preview?.rows.filter((row) => row.status === 'review_required') ?? [];
    const unresolved = reviewRows.some((row) => !decisions[row.line]);
    const planned = { create_distinct: 0, update: 0, skip: 0 };
    for (const row of preview?.rows ?? []) {
        const action = ['error', 'duplicate'].includes(row.status)
            ? 'skip'
            : (decisions[row.line]?.action ??
              (row.status === 'new'
                  ? 'create_distinct'
                  : row.status === 'existing'
                    ? policy
                    : null));
        if (action) planned[action] += 1;
    }
    const importable = planned.create_distinct + planned.update + reviewRows.length;

    return (
        <ConsoleLayout>
            <PageHeader title="Import members" />
            <div className="space-y-6 px-4 py-6 sm:px-8">
                <Button href={route('tenant.community.people.index')}>Back to People</Button>
                <form
                    onSubmit={upload}
                    className="space-y-4 rounded-lg border border-border bg-surface p-5"
                >
                    <p className="text-sm text-ink-secondary">
                        Nothing is saved until you review the rows and confirm. Upload CSV or XLSX,
                        up to 5 MB and 10,000 rows. Dates use YYYY-MM-DD. XLSX uses the first
                        worksheet.
                    </p>
                    <a
                        href={route('tenant.community.people.import.template')}
                        className="inline-block text-sm font-medium text-accent underline"
                    >
                        Download CSV template
                    </a>
                    <div>
                        <label
                            htmlFor="member-spreadsheet"
                            className="mb-2 block text-sm font-medium text-ink"
                        >
                            Spreadsheet
                        </label>
                        <input
                            id="member-spreadsheet"
                            type="file"
                            accept=".csv,.xlsx"
                            disabled={busy}
                            onChange={(event) => {
                                setFile(event.target.files[0] ?? null);
                                setPreview(null);
                                setResult(null);
                            }}
                            className="block w-full text-sm text-ink"
                        />
                    </div>
                    <Button type="submit" variant="primary" disabled={!file || busy}>
                        {busy ? 'Working…' : 'Preview spreadsheet'}
                    </Button>
                </form>
                {error && (
                    <div
                        role="alert"
                        className="rounded-md border border-danger-fg/30 bg-danger-bg p-4 text-sm text-danger-fg"
                    >
                        {error}
                    </div>
                )}
                {result && (
                    <div
                        role="status"
                        className="space-y-3 rounded-md border border-border bg-surface-hover p-4 text-sm text-ink"
                    >
                        <p>
                            {result.created} added · {result.updated} updated · {result.skipped}{' '}
                            skipped · {result.errors} {result.errors === 1 ? 'error' : 'errors'}
                        </p>
                        <p>
                            Existing members keep their status, dues history, join date, custom
                            amount and pay link. A changed contact match requires a fresh preview.
                        </p>
                        <Button href={route('tenant.community.people.index')}>View members</Button>
                    </div>
                )}
                {result && resultIssues.length > 0 && (
                    <section aria-label="Import row results" className="space-y-3">
                        <h2 className="text-sm font-semibold text-ink">Skipped rows and errors</h2>
                        <div className="overflow-x-auto">
                            <Table>
                                <Thead>
                                    <Th>Line</Th>
                                    <Th>Result</Th>
                                    <Th>Reason</Th>
                                </Thead>
                                <tbody>
                                    {resultIssues
                                        .slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE)
                                        .map((row) => (
                                            <tr key={row.line}>
                                                <Td>{row.line}</Td>
                                                <Td>
                                                    {row.status === 'error' ? 'Error' : 'Skipped'}
                                                </Td>
                                                <Td>
                                                    {row.errors.length > 0
                                                        ? row.errors.map((reason) => (
                                                              <p key={reason}>{reason}</p>
                                                          ))
                                                        : 'Already present or duplicated in the spreadsheet.'}
                                                </Td>
                                            </tr>
                                        ))}
                                </tbody>
                            </Table>
                        </div>
                        <div className="flex flex-wrap items-center justify-end gap-3 text-sm text-ink-secondary">
                            <span>
                                {resultIssues.length.toLocaleString()}{' '}
                                {resultIssues.length === 1 ? 'row' : 'rows'} · Page {page} of{' '}
                                {Math.ceil(resultIssues.length / PAGE_SIZE)}
                            </span>
                            <Button disabled={page === 1} onClick={() => setPage(page - 1)}>
                                Previous
                            </Button>
                            <Button
                                disabled={page * PAGE_SIZE >= resultIssues.length}
                                onClick={() => setPage(page + 1)}
                            >
                                Next
                            </Button>
                        </div>
                    </section>
                )}
                {preview && (
                    <section aria-label="Import preview" className="space-y-4">
                        <div aria-live="polite" className="flex flex-wrap gap-4 text-sm text-ink">
                            {Object.entries(STATUSES).map(([status, label]) => (
                                <span key={status}>
                                    {label}: {(preview.summary[status] ?? 0).toLocaleString()}
                                </span>
                            ))}
                        </div>
                        <fieldset disabled={busy} className="space-y-2 text-sm text-ink">
                            <legend className="mb-2 font-medium">Existing members</legend>
                            <label className="flex items-center gap-2">
                                <input
                                    type="radio"
                                    name="existing-policy"
                                    checked={policy === 'skip'}
                                    onChange={() => setPolicy('skip')}
                                />
                                Skip matching members
                            </label>
                            <label className="flex items-center gap-2">
                                <input
                                    type="radio"
                                    name="existing-policy"
                                    checked={policy === 'update'}
                                    onChange={() => setPolicy('update')}
                                />
                                Update matching members
                            </label>
                            <p className="text-xs text-ink-secondary">
                                Update changes name, contacts and membership type. Status, join
                                date, dues history, custom amount and pay link are kept. Preview
                                expires in 30 minutes.
                            </p>
                        </fieldset>
                        <div className="flex flex-wrap items-center gap-3">
                            <label
                                htmlFor="import-row-filter"
                                className="text-sm font-medium text-ink"
                            >
                                Show rows
                            </label>
                            <select
                                id="import-row-filter"
                                value={filter}
                                onChange={(event) => {
                                    setFilter(event.target.value);
                                    setPage(1);
                                }}
                                className="rounded-md border border-border bg-surface px-3 py-2 text-sm text-ink"
                            >
                                <option value="all">All rows</option>
                                {Object.entries(STATUSES).map(([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ))}
                            </select>
                            <Button
                                onClick={confirm}
                                variant="primary"
                                disabled={busy || importable === 0 || unresolved}
                            >
                                {busy ? 'Importing…' : 'Confirm import'}
                            </Button>
                        </div>
                        <p aria-live="polite" className="text-sm font-medium text-ink">
                            {planned.create_distinct} add · {planned.update} update · {planned.skip}{' '}
                            skip{unresolved ? ' · Choose a decision for every review row.' : ''}
                        </p>
                        <p className="text-xs text-ink-secondary">
                            Errors and exact duplicates are skipped. Correct them in your
                            spreadsheet and preview again. The whole import is refused if new
                            members exceed your plan limit.
                        </p>
                        <div className="overflow-x-auto">
                            <Table>
                                <Thead>
                                    <Th>Line</Th>
                                    <Th>Name</Th>
                                    <Th>Contact</Th>
                                    <Th>Membership type</Th>
                                    <Th>Joined on</Th>
                                    <Th>Status / reason</Th>
                                </Thead>
                                <tbody>
                                    {shown.map((row) => (
                                        <tr key={row.line}>
                                            <Td>{row.line}</Td>
                                            <Td>{row.name || '—'}</Td>
                                            <Td>
                                                {row.phone && (
                                                    <p>
                                                        Phone: <span>{row.phone}</span>
                                                    </p>
                                                )}
                                                {row.email && (
                                                    <p>
                                                        Email: <span>{row.email}</span>
                                                    </p>
                                                )}
                                                {!row.phone && !row.email && '—'}
                                            </Td>
                                            <Td>{row.membership_type || '—'}</Td>
                                            <Td>{row.joined_on || 'Today'}</Td>
                                            <Td>
                                                <span className="font-medium">
                                                    {STATUSES[row.status]}
                                                </span>
                                                {row.status === 'review_required' && (
                                                    <div className="mt-3 min-w-64 space-y-2">
                                                        <p className="text-xs text-ink-secondary">
                                                            A shared contact is a possible match,
                                                            not proof of identity.
                                                        </p>
                                                        <label
                                                            htmlFor={`decision-${row.line}`}
                                                            className="block text-xs font-medium text-ink"
                                                        >
                                                            Decision for line {row.line}
                                                        </label>
                                                        <select
                                                            id={`decision-${row.line}`}
                                                            aria-label={`Decision for line ${row.line}`}
                                                            className="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm text-ink"
                                                            value={
                                                                decisions[row.line]?.action ===
                                                                'update'
                                                                    ? `update:${decisions[row.line].member_id}`
                                                                    : (decisions[row.line]
                                                                          ?.action ?? '')
                                                            }
                                                            onChange={(event) => {
                                                                const value = event.target.value;
                                                                setDecisions((current) => ({
                                                                    ...current,
                                                                    [row.line]: value.startsWith(
                                                                        'update:'
                                                                    )
                                                                        ? {
                                                                              action: 'update',
                                                                              member_id:
                                                                                  value.slice(7),
                                                                          }
                                                                        : value
                                                                          ? { action: value }
                                                                          : undefined,
                                                                }));
                                                            }}
                                                        >
                                                            <option value="">
                                                                Choose a decision
                                                            </option>
                                                            <option value="skip">
                                                                Skip this row
                                                            </option>
                                                            {(row.candidates ?? [])
                                                                .filter(
                                                                    (candidate) =>
                                                                        !candidate.source_line
                                                                )
                                                                .map((candidate) => (
                                                                    <option
                                                                        key={candidate.id}
                                                                        value={`update:${candidate.id}`}
                                                                    >
                                                                        Update {candidate.name} ·{' '}
                                                                        {candidate.email ??
                                                                            candidate.phone}
                                                                    </option>
                                                                ))}
                                                            {!(row.candidates ?? []).some(
                                                                (candidate) =>
                                                                    candidate.reasons.includes(
                                                                        'email'
                                                                    )
                                                            ) && (
                                                                <option value="create_distinct">
                                                                    Create a distinct new person
                                                                </option>
                                                            )}
                                                        </select>
                                                        {(row.candidates ?? [])
                                                            .filter(
                                                                (candidate) => candidate.source_line
                                                            )
                                                            .map((candidate) => (
                                                                <p
                                                                    key={candidate.id}
                                                                    className="text-xs text-ink-secondary"
                                                                >
                                                                    Also shares a contact with
                                                                    spreadsheet line{' '}
                                                                    {candidate.source_line}.
                                                                </p>
                                                            ))}
                                                    </div>
                                                )}
                                                {row.errors.map((reason) => (
                                                    <p key={reason} className="text-danger-fg">
                                                        {reason}
                                                    </p>
                                                ))}
                                            </Td>
                                        </tr>
                                    ))}
                                    {shown.length === 0 && (
                                        <tr>
                                            <td colSpan={6}>
                                                <TableEmpty
                                                    title="No rows in this filter"
                                                    description="Choose another filter, or correct the spreadsheet and preview again."
                                                />
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </Table>
                        </div>
                        <div className="flex flex-wrap items-center justify-end gap-3 text-sm text-ink-secondary">
                            <span>
                                {rows.length.toLocaleString()} {rows.length === 1 ? 'row' : 'rows'}
                                {' · Page '}
                                {page} of {Math.max(1, Math.ceil(rows.length / PAGE_SIZE))}
                            </span>
                            <Button disabled={page === 1} onClick={() => setPage(page - 1)}>
                                Previous
                            </Button>
                            <Button
                                disabled={page * PAGE_SIZE >= rows.length}
                                onClick={() => setPage(page + 1)}
                            >
                                Next
                            </Button>
                        </div>
                    </section>
                )}
            </div>
        </ConsoleLayout>
    );
}
