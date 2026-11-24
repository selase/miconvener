import { useEffect, useState } from 'react';
import Modal from '@/Components/Console/Modal';
import Button from '@/Components/Console/Button';
import csrfFetch from '@/lib/csrfFetch';

/**
 * One applicant's answers, laid out in the form's own sections, with the
 * decision buttons beside them so a reviewer never has to cross-reference
 * a spreadsheet to approve someone.
 */
export default function ApplicationReviewModal({ event, registration, onClose, onDecided }) {
    const [application, setApplication] = useState(null);
    const [loadError, setLoadError] = useState(null);
    const [declining, setDeclining] = useState(false);
    const [note, setNote] = useState('');
    const [working, setWorking] = useState(false);
    const [decisionError, setDecisionError] = useState(null);

    useEffect(() => {
        let cancelled = false;
        setApplication(null);
        setLoadError(null);
        csrfFetch(
            route('tenant.events.registrations.application', {
                event: event.id,
                registration: registration.id,
            })
        )
            .then(async (response) => {
                if (!response.ok) throw new Error('load');
                const data = await response.json();
                if (!cancelled) setApplication(data);
            })
            .catch(() => {
                if (!cancelled) setLoadError('This application could not be loaded. Try again.');
            });
        return () => {
            cancelled = true;
        };
    }, [event.id, registration.id]);

    const decide = async (action, body) => {
        setWorking(true);
        setDecisionError(null);
        try {
            const response = await csrfFetch(
                route(`tenant.events.registrations.${action}`, {
                    event: event.id,
                    registration: registration.id,
                }),
                { method: 'POST', body: body ? JSON.stringify(body) : undefined }
            );
            if (!response.ok) {
                const data = await response.json().catch(() => ({}));
                setDecisionError(data.message || 'That did not work. Try again.');
                return;
            }
            onDecided?.();
            onClose();
        } finally {
            setWorking(false);
        }
    };

    const applicant = application?.applicant;
    const canDecide = (applicant?.status ?? registration.status) === 'pending_approval';

    const footer = canDecide ? (
        <div className="space-y-3">
            {decisionError && (
                <p role="alert" className="text-sm text-danger-fg">
                    {decisionError}
                </p>
            )}
            {declining ? (
                <div className="space-y-2">
                    <label htmlFor="decline-note" className="block text-sm font-medium text-ink">
                        Reason for declining (optional)
                    </label>
                    <textarea
                        id="decline-note"
                        rows={2}
                        maxLength={255}
                        value={note}
                        onChange={(e) => setNote(e.target.value)}
                        className="w-full border border-border px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none"
                    />
                    <p className="text-xs text-ink-secondary">
                        The applicant receives this reason in their email.
                    </p>
                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            onClick={() => setDeclining(false)}
                            disabled={working}
                        >
                            Back
                        </Button>
                        <Button
                            type="button"
                            onClick={() => decide('reject', { note: note.trim() || null })}
                            disabled={working}
                            className="border-danger-fg/40 text-danger-fg hover:bg-danger-bg"
                        >
                            Decline application
                        </Button>
                    </div>
                </div>
            ) : (
                <div className="flex justify-end gap-2">
                    <Button
                        type="button"
                        onClick={() => setDeclining(true)}
                        disabled={working || !application}
                    >
                        Decline
                    </Button>
                    <Button
                        type="button"
                        variant="primary"
                        onClick={() => decide('approve')}
                        disabled={working || !application}
                    >
                        Approve
                    </Button>
                </div>
            )}
        </div>
    ) : null;

    return (
        <Modal
            open
            onClose={onClose}
            title={registration.full_name || 'Application'}
            className="max-w-2xl"
            fullScreenOnMobile
            footer={footer}
        >
            {loadError && (
                <p role="alert" className="text-sm text-danger-fg">
                    {loadError}
                </p>
            )}
            {!application && !loadError && (
                <p className="text-sm text-ink-secondary">Loading application…</p>
            )}
            {application && (
                <div className="space-y-6">
                    <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                        <Detail label="Email" value={applicant.email} />
                        <Detail label="Phone" value={applicant.phone} />
                        <Detail label="Option chosen" value={applicant.ticket_type_name} />
                        <Detail
                            label="Applied"
                            value={
                                applicant.submitted_at
                                    ? new Date(applicant.submitted_at).toLocaleString()
                                    : null
                            }
                        />
                        <Detail label="Status" value={applicant.status.replaceAll('_', ' ')} />
                        {applicant.approval_note && (
                            <Detail label="Decision note" value={applicant.approval_note} />
                        )}
                        {applicant.dietary_requirements && (
                            <Detail label="Dietary" value={applicant.dietary_requirements} />
                        )}
                        {applicant.accessibility_needs && (
                            <Detail label="Accessibility" value={applicant.accessibility_needs} />
                        )}
                    </dl>

                    {application.sections.length === 0 && (
                        <p className="text-sm text-ink-secondary">
                            This registration has no answers to custom questions.
                        </p>
                    )}

                    {application.sections.map((section, index) => (
                        <section key={section.title ?? `section-${index}`}>
                            {section.title && (
                                <h3 className="border-b border-border pb-1.5 text-[13px] font-semibold uppercase tracking-wide text-ink-secondary">
                                    {section.title}
                                </h3>
                            )}
                            <dl className="mt-3 space-y-3">
                                {section.answers.map((answer) => (
                                    <div key={answer.label}>
                                        <dt className="text-xs text-ink-secondary">
                                            {answer.label}
                                        </dt>
                                        <dd
                                            className={`mt-0.5 whitespace-pre-line text-sm ${
                                                answer.value === null
                                                    ? 'italic text-ink-tertiary'
                                                    : 'text-ink'
                                            }`}
                                        >
                                            {answer.value ?? 'Not answered'}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        </section>
                    ))}
                </div>
            )}
        </Modal>
    );
}

function Detail({ label, value }) {
    return (
        <div>
            <dt className="text-xs text-ink-secondary">{label}</dt>
            <dd className="mt-0.5 break-words text-ink">{value || '—'}</dd>
        </div>
    );
}
