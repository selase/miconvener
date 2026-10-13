<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Dues;

use App\Models\Member;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A member choosing which of their dues charges to pay, from the public pay
 * page. There is no sign-in: the pay token in the URL is the credential, so
 * the member is resolved before validation and an unknown, rotated or other
 * organisation's token is a 404 whatever the request body holds.
 */
final class StartDuesCheckoutRequest extends FormRequest
{
    private ?Member $resolvedMember = null;

    /**
     * Resolves a pay token on the current organisation's domain, aborting with
     * the same 404 for every miss so a link reveals nothing about whether it
     * ever existed.
     */
    public static function memberForToken(string $token): Member
    {
        $tenant = app(TenantContext::class)->getTenant();

        if ($tenant === null || mb_strlen($token) !== 40) {
            abort(404);
        }

        $member = Member::query()
            ->with('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('pay_token', $token)
            ->first();

        if ($member === null) {
            abort(404);
        }

        return $member;
    }

    public function authorize(): bool
    {
        $this->member();

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'charge_ids' => ['required', 'array', 'min:1', 'max:60'],
            'charge_ids.*' => ['required', 'string', 'uuid', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'charge_ids.required' => 'Choose at least one charge to pay.',
            'charge_ids.array' => 'Choose at least one charge to pay.',
            'charge_ids.min' => 'Choose at least one charge to pay.',
            'charge_ids.max' => 'Pay at most 60 charges at a time.',
            'charge_ids.*.required' => 'One of the chosen charges is not valid.',
            'charge_ids.*.string' => 'One of the chosen charges is not valid.',
            'charge_ids.*.uuid' => 'One of the chosen charges is not valid.',
            'charge_ids.*.distinct' => 'A charge was chosen twice.',
        ];
    }

    /**
     * The member whose pay token is in the URL, within the organisation whose
     * domain this is.
     */
    public function member(): Member
    {
        return $this->resolvedMember ??= self::memberForToken((string) $this->route('token'));
    }
}
