<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Models\TenantSendingDomain;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StoreSendingDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('access-superadmin-dashboard');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = $this->route('tenant')?->id;

        return [
            'domain' => [
                'required',
                'string',
                'max:253',
                'regex:/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/',
                Rule::unique((new TenantSendingDomain)->getConnectionName().'.tenant_sending_domains', 'domain')->ignore($tenantId, 'tenant_id'),
            ],
            'from_address' => ['required', 'email', 'max:255'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $domain = (string) $this->input('domain');

                if ($domain !== '' && ! Str::endsWith((string) $this->input('from_address'), '@'.$domain)) {
                    $validator->errors()->add('from_address', "The from address must be on {$domain}.");
                }

                $platformDomain = mb_strtolower(Str::after((string) config('mail.from.address'), '@'));

                if ($platformDomain !== '' && ($domain === $platformDomain || Str::endsWith($domain, '.'.$platformDomain))) {
                    $validator->errors()->add('domain', 'This is MiConvener\'s own sending domain, not an organiser\'s.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'domain.regex' => 'Enter a domain like events.example.com, without http:// or a path.',
            'domain.unique' => 'Another organisation already sends from this domain.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'domain' => mb_strtolower(mb_trim((string) $this->input('domain'))),
            'from_address' => mb_strtolower(mb_trim((string) $this->input('from_address'))),
        ]);
    }
}
