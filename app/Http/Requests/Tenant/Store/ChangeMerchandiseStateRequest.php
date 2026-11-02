<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Store;

final class ChangeMerchandiseStateRequest extends CatalogRequest
{
    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [];
    }
}
