<?php

declare(strict_types=1);

/** Exercise production's Composer --no-dev boundary without changing installed packages. */
$loader = require dirname(__DIR__, 2).'/vendor/autoload.php';
spl_autoload_unregister([$loader, 'loadClass']);
spl_autoload_register(static function (string $class) use ($loader): void {
    if (! str_starts_with($class, 'Laravel\\Telescope\\')) {
        $loader->loadClass($class);
    }
});

if (class_exists(Laravel\Telescope\Telescope::class)) {
    throw new RuntimeException('Telescope was loaded before the production dependency regression bootstrap.');
}
