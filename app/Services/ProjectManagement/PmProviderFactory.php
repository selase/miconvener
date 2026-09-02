<?php

declare(strict_types=1);

namespace App\Services\ProjectManagement;

use App\Contracts\ProjectManagement\PmProviderContract;
use App\Enum\PmProvider;

final class PmProviderFactory
{
    public function make(PmProvider|string $provider): PmProviderContract
    {
        $providerEnum = $provider instanceof PmProvider
            ? $provider
            : PmProvider::from($provider);

        return match ($providerEnum) {
            PmProvider::Jira => app(JiraPmProvider::class),
            PmProvider::Asana => app(AsanaPmProvider::class),
            PmProvider::Linear => app(LinearPmProvider::class),
            PmProvider::Monday => app(MondayPmProvider::class),
        };
    }
}
