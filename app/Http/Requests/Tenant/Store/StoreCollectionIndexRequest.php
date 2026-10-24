<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Store;

final class StoreCollectionIndexRequest extends StoreCollectionActionRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['method' => ['nullable', 'in:all,collection,delivery'], 'state' => ['nullable', 'in:all,preparing,ready,dispatched,completed'], 'page' => ['nullable', 'integer', 'min:1']];
    }
}
