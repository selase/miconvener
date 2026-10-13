<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Dues;

/**
 * Edits a dues class, including deactivating it. Classes are never deleted
 * because members and past charges refer to them.
 */
final class UpdateDuesClassRequest extends DuesClassRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->classRules((string) $this->route('class')),
            'is_active' => ['required', 'boolean'],
        ];
    }
}
