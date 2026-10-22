<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\StoreStockMovement;
use App\Models\StoreVariant;
use App\Models\User;
use App\Services\Marketplace\MerchandiseInventory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->variant = StoreVariant::factory()->create();
    $tenantId = $this->variant->shop->tenant_id;
    $this->actor = User::factory()->create(['tenant_id' => $tenantId]);
    $this->actor->tenants()->attach($tenantId);
    setPermissionsTeamId($tenantId);
    Permission::query()->firstOrCreate(['name' => 'manage store catalog', 'guard_name' => 'web'], ['category' => 'store']);
    $this->actor->givePermissionTo('manage store catalog');
});

it('records an auditable stock correction and consumes a correction once', function (): void {
    $key = (string) Str::uuid();
    $service = app(MerchandiseInventory::class);
    $movement = $service->adjust($this->variant, 6, 'Opening stock', $key, $this->actor);
    $retry = $service->adjust($this->variant, 6, 'Opening stock', $key, $this->actor);
    expect($movement->id)->toBe($retry->id)
        ->and($this->variant->fresh()->on_hand)->toBe(6)
        ->and($movement->actor_id)->toBe($this->actor->id)
        ->and($movement->balance_after)->toBe(6)
        ->and(StoreStockMovement::query()->count())->toBe(1);
    $service->adjust($this->variant, -2, 'Damaged items', (string) Str::uuid(), $this->actor);
    expect($this->variant->fresh()->on_hand)->toBe(4);
});

it('rejects a different correction reusing a token and leaves stock unchanged', function (): void {
    $key = (string) Str::uuid();
    $service = app(MerchandiseInventory::class);
    $service->adjust($this->variant, 3, 'Receipt', $key, $this->actor);
    try {
        $service->adjust($this->variant, 5, 'Receipt', $key, $this->actor);
        $this->fail('A mismatched retry must not mutate inventory.');
    } catch (ValidationException) {
        expect($this->variant->fresh()->on_hand)->toBe(3)
            ->and(StoreStockMovement::query()->count())->toBe(1);
    }
});

it('rejects an underflow and rolls back its stock movement', function (): void {
    try {
        app(MerchandiseInventory::class)->adjust($this->variant, -1, 'Correction', (string) Str::uuid(), $this->actor);
        $this->fail('Stock must not become negative.');
    } catch (ValidationException) {
        expect($this->variant->fresh()->on_hand)->toBe(0)
            ->and(StoreStockMovement::query()->count())->toBe(0);
    }
});

it('does not allow ordinary variant editing to change stock', function (): void {
    expect(fn () => $this->variant->update(['on_hand' => 7]))->toThrow(Illuminate\Database\Eloquent\MassAssignmentException::class);
    expect($this->variant->fresh()->on_hand)->toBe(0);
});

it('keeps recorded movements immutable', function (string $action): void {
    $movement = app(MerchandiseInventory::class)->adjust($this->variant, 2, 'Receipt', (string) Str::uuid(), $this->actor);
    $action === 'delete' ? $movement->delete() : $movement->update(['delta' => 99]);
})->with(['update', 'delete'])->throws(LogicException::class);

it('does not allow a different organisation actor to correct stock', function (): void {
    $other = User::factory()->create();
    app(MerchandiseInventory::class)->adjust($this->variant, 1, 'Receipt', (string) Str::uuid(), $other);
})->throws(AuthorizationException::class);

it('excludes a second inventory writer on another database connection', function (): void {
    $other = app(Illuminate\Database\DatabaseManager::class)->build(config('database.connections.landlord'));
    try {
        app(MerchandiseInventory::class)->adjust($this->variant, 2, 'Receipt', (string) Str::uuid(), $this->actor);
        $lock = $other->selectOne('select pg_try_advisory_xact_lock(hashtextextended(?, 0)) as acquired', ['commerce.inventory:'.$this->variant->id]);
        expect($lock->acquired)->toBeFalse()
            ->and($this->variant->fresh()->on_hand)->toBe(2);
    } finally {
        $other->disconnect();
    }
});

it('also prevents bulk writes from changing stock history', function (string $action): void {
    $movement = app(MerchandiseInventory::class)->adjust($this->variant, 2, 'Receipt', (string) Str::uuid(), $this->actor);
    $query = StoreStockMovement::query()->whereKey($movement->id);
    $action === 'delete' ? $query->delete() : $query->update(['delta' => 99]);
})->with(['update', 'delete'])->throws(Illuminate\Database\QueryException::class);
