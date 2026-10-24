<?php

declare(strict_types=1);

it('grants fulfilment to organisation administrators and tenant-safe custom roles without event staff inheritance', function () {
    $defaults = App\Libraries\RolePermissions::defaults();
    expect($defaults['Org Superadmin'])->toContain('manage store collection')
        ->and($defaults['Org Admin'])->toContain('manage store collection')
        ->and($defaults['Event Staff'])->not->toContain('manage store collection')
        ->and(App\Models\Permission::TENANT_SAFE)->toContain('manage store collection');
    $this->seed(Database\Seeders\PermissionsSeeder::class);
    expect(App\Models\Permission::query()->where('name', 'manage store collection')->exists())->toBeTrue();
});
