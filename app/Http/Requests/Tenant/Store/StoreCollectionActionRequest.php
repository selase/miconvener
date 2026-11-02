<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Store;

use Illuminate\Foundation\Http\FormRequest;

abstract class StoreCollectionActionRequest extends FormRequest
{
    final public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string,string> */
    final public function messages(): array
    {
        return ['operation_key.required' => 'Refresh this order before recording the action.',
            'operation_key.uuid' => 'Refresh this order before recording the action.',
            'credential.required' => 'Scan the buyer’s store QR code or enter their handover code.',
            'all_items_handed_over.accepted' => 'Confirm that all items are being handed over.',
            'all_items_returned_to_seller.accepted' => 'Confirm that all items are physically back with the seller.',
            'reason.required' => 'Enter a reason for this change.',
            'reason.max' => 'Keep the reason within 500 characters.'];
    }
}
