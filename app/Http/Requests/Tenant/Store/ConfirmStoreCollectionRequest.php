<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Store;

final class ConfirmStoreCollectionRequest extends StoreCollectionActionRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['operation_key' => ['required', 'uuid'], 'tenant_id' => ['prohibited'], 'actor_id' => ['prohibited'], 'state' => ['prohibited'], 'payment_state' => ['prohibited'], 'amount' => ['prohibited'], ...(new ValidateStoreCollectionRequest)->rules(), 'all_items_handed_over' => ['required', 'accepted']];
    }
}
