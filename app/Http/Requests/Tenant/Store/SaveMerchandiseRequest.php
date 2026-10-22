<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Store;

use Closure;
use Illuminate\Validation\Rule;

final class SaveMerchandiseRequest extends CatalogRequest
{
    /** @return array<string,mixed> */
    public static function catalogRules(): array
    {
        return ['title' => ['required', 'string', 'max:255'], 'slug' => ['required', 'string', 'max:180', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            'description' => ['nullable', 'string', 'max:20000'], 'visibility' => ['required', Rule::in(['public', 'members'])],
            'currency' => ['required', Rule::in(['GHS'])], 'collection_instructions' => ['nullable', 'string', 'max:2000'],
            'listing_kind' => ['prohibited'], 'shop_id' => ['prohibited'], 'tenant_id' => ['prohibited'], 'status' => ['prohibited'], 'photos' => ['prohibited'],
            'variants' => ['required', 'array', 'min:1', 'max:100'],
            'variants.*' => ['required', 'array:id,sku,options,price_pesewas,is_active,initial_stock,idempotency_key'],
            'variants.*.id' => ['sometimes', 'uuid', 'distinct'], 'variants.*.sku' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9_\- ]+$/D'],
            'variants.*.options' => ['present', 'array', 'max:8', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_array($value)) {
                    return;
                }
                foreach (array_keys($value) as $name) {
                    if (! is_string($name) || mb_trim($name) === '' || mb_strlen($name) > 50) {
                        $fail('Use option names of 1 to 50 characters.');

                        return;
                    }
                }
            }], 'variants.*.options.*' => ['required', 'string', 'max:100'],
            'variants.*.price_pesewas' => ['required', 'integer', 'min:0', 'max:9007199254740991'], 'variants.*.is_active' => ['required', 'boolean'],
            'variants.*.initial_stock' => ['sometimes', 'integer', 'min:0', 'max:2147483647'], 'variants.*.idempotency_key' => ['sometimes', 'nullable', 'uuid']];
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return self::catalogRules();
    }

    /** @return array<string,string> */
    public function messages(): array
    {
        return ['variants.*.price_pesewas.integer' => 'Enter a whole number of pesewas, without decimals.',
            'photos.prohibited' => 'Upload photos using the photo uploader; remote image URLs are not accepted.',
            'slug.regex' => 'Use lowercase letters, numbers and single hyphens for the item address.'];
    }
}
