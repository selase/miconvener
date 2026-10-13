<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What a payout, a ledger account or a ledger transaction is for. Every payout
 * belongs to exactly one category so the books can show each stream of money
 * apart. A new money stream must be added here, with a decision about whether
 * it needs an event, before it can pay out.
 */
enum LedgerCategoryEnum: string
{
    /** Ticket sales and contributions of one event. */
    case Event = 'event';

    /** Membership dues. */
    case Dues = 'dues';

    /** Commission the platform owes an organisation back. */
    case CommissionCredit = 'commission_credit';

    /** A business's balance from Event Store bookings and quotes. */
    case Marketplace = 'marketplace';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Event => 'Event sales',
            self::Dues => 'Member dues',
            self::CommissionCredit => 'Commission credit',
            self::Marketplace => 'Marketplace',
        };
    }

    /**
     * @return 'required'|'forbidden'|'optional'
     */
    public function eventRule(): string
    {
        return match ($this) {
            self::Event => 'required',
            self::Dues, self::CommissionCredit => 'forbidden',
            self::Marketplace => 'optional',
        };
    }

    public function allowsEvent(bool $hasEvent): bool
    {
        return match ($this->eventRule()) {
            'required' => $hasEvent,
            'forbidden' => ! $hasEvent,
            default => true,
        };
    }
}
