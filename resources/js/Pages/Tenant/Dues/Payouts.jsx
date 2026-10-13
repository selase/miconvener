import ConsoleLayout from '@/Layouts/ConsoleLayout';
import PageHeader from '@/Components/Console/PageHeader';

/**
 * Placeholder for the dues payouts console. The server already sends
 * balances, accounts and payouts; the full page replaces this in a later
 * milestone task.
 */
export default function Payouts() {
    return (
        <ConsoleLayout>
            <PageHeader title="Dues payouts" />
        </ConsoleLayout>
    );
}
