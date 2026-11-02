<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Store;

final class StoreDispatchRequest extends StoreCollectionActionRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['operation_key' => ['required', 'uuid'], 'tenant_id' => ['prohibited'], 'actor_id' => ['prohibited'], 'state' => ['prohibited'], 'payment_state' => ['prohibited'], 'amount' => ['prohibited']];
    }
}
