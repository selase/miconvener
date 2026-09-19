<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\Operations\OperationalCommands;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class RunCommandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('access-superadmin-dashboard');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Only a key from the registry. The Artisan string is never
            // submitted, so there is nothing here to inject into.
            'command' => ['required', 'string', Rule::in(OperationalCommands::keys())],
            'options' => ['nullable', 'array'],
            'options.*' => [
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $definition = OperationalCommands::find((string) $this->input('command'));

                    if ($definition === null || ! array_key_exists((string) $value, $definition['options'])) {
                        $fail('That option is not available for this command.');
                    }
                },
            ],
            // Commands that take money or write to inboxes ask for the name back.
            'confirmation' => ['nullable', 'string'],
        ];
    }
}
