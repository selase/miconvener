<?php

declare(strict_types=1);

namespace App\Services\AI;

use Illuminate\Support\Facades\View;

final class PromptRenderer
{
    /**
     * Render a prompt template with the given data.
     */
    public function render(string $template, array $data = []): string
    {
        return View::make("prompts.{$template}", $data)->render();
    }
}
