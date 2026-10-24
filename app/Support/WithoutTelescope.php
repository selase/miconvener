<?php

declare(strict_types=1);

namespace App\Support;

use Laravel\Telescope\Telescope;

final class WithoutTelescope
{
    /**
     * Telescope is a development dependency, so production runs the operation directly.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(callable $callback): mixed
    {
        return class_exists(Telescope::class) ? Telescope::withoutRecording($callback) : $callback();
    }
}
