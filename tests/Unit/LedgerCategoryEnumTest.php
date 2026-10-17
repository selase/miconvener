<?php

declare(strict_types=1);

use App\Enum\LedgerCategoryEnum;

test('the categories and their stored values', function (): void {
    expect(LedgerCategoryEnum::values())->toBe(['event', 'dues', 'giving', 'commission_credit', 'marketplace']);
});

test('each category says whether it needs an event', function (): void {
    expect(LedgerCategoryEnum::Event->eventRule())->toBe('required')
        ->and(LedgerCategoryEnum::Dues->eventRule())->toBe('forbidden')
        ->and(LedgerCategoryEnum::Giving->eventRule())->toBe('forbidden')
        ->and(LedgerCategoryEnum::CommissionCredit->eventRule())->toBe('forbidden')
        ->and(LedgerCategoryEnum::Marketplace->eventRule())->toBe('optional');
});

test('a category accepts an event only when its rule allows', function (): void {
    expect(LedgerCategoryEnum::Event->allowsEvent(true))->toBeTrue()
        ->and(LedgerCategoryEnum::Event->allowsEvent(false))->toBeFalse()
        ->and(LedgerCategoryEnum::Dues->allowsEvent(true))->toBeFalse()
        ->and(LedgerCategoryEnum::Dues->allowsEvent(false))->toBeTrue()
        ->and(LedgerCategoryEnum::Giving->allowsEvent(true))->toBeFalse()
        ->and(LedgerCategoryEnum::Giving->allowsEvent(false))->toBeTrue()
        ->and(LedgerCategoryEnum::CommissionCredit->allowsEvent(true))->toBeFalse()
        ->and(LedgerCategoryEnum::Marketplace->allowsEvent(true))->toBeTrue()
        ->and(LedgerCategoryEnum::Marketplace->allowsEvent(false))->toBeTrue();
});

test('each category has a human label', function (): void {
    expect(LedgerCategoryEnum::Event->label())->toBe('Event sales')
        ->and(LedgerCategoryEnum::Dues->label())->toBe('Member dues')
        ->and(LedgerCategoryEnum::Giving->label())->toBe('Giving')
        ->and(LedgerCategoryEnum::CommissionCredit->label())->toBe('Commission credit')
        ->and(LedgerCategoryEnum::Marketplace->label())->toBe('Marketplace');
});
