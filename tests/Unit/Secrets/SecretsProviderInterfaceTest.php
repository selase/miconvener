<?php

declare(strict_types=1);

namespace Tests\Unit\Secrets;

use App\Contracts\Secrets\SecretsProvider;
use ReflectionMethod;

test('SecretsProvider interface exists and has getSecret method', function () {
    expect(interface_exists(SecretsProvider::class))->toBeTrue();

    $method = new ReflectionMethod(SecretsProvider::class, 'getSecret');
    expect($method->getNumberOfParameters())->toBe(1)
        ->and($method->getParameters()[0]->getName())->toBe('ref')
        ->and($method->getReturnType()->getName())->toBe('array');
});
