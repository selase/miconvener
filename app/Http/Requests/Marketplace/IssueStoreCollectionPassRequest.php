<?php

declare(strict_types=1);

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;

final class IssueStoreCollectionPassRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return ['operation_key' => ['required', 'uuid'], 'buyer_email' => ['prohibited'], 'tenant_id' => ['prohibited'], 'secret' => ['prohibited'], 'manual_code' => ['prohibited'], 'method' => ['prohibited']];
    }

    /** @return array<string,string> */
    public function messages(): array
    {
        return ['operation_key.uuid' => 'Refresh the order before requesting a pass.'];
    }
}
