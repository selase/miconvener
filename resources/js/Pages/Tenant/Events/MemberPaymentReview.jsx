import { Head } from '@inertiajs/react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import PageHeader from '@/Components/Console/PageHeader';
import MemberPaymentReviewPanel from './MemberPaymentReviewPanel';
export default function MemberPaymentReview({ event, can_manage }) {
    return (
        <ConsoleLayout>
            <Head title={`Member payments · ${event.name}`} />
            <PageHeader title={`Member payments · ${event.name}`} />
            {event.archived && (
                <p className="mb-4 text-sm text-ink-secondary">
                    This event is archived. Original payments and refunds remain available for
                    review.
                </p>
            )}
            <MemberPaymentReviewPanel event={event} canManage={can_manage} />
        </ConsoleLayout>
    );
}
