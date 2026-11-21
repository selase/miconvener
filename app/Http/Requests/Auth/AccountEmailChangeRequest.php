<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

abstract class AccountEmailChangeRequest extends FormRequest
{
    final public function authorize(): bool
    {
        $user = $this->user()?->fresh();

        return $user instanceof User && $user->email_verified_at
            && in_array($user->status, [null, User::STATUS_ACTIVE], true)
            && ! $this->session()->has('original_user');
    }

    /** @return array<string, string> */
    final public function messages(): array
    {
        return [
            'email.email' => 'Enter a valid new email address.',
            'password.required' => 'Confirm your current password.',
            'code.digits' => 'Enter the six-digit code from your authenticator app.',
            'token.required' => 'Open the full verification link from your new mailbox.',
            'token.size' => 'Open the full verification link from your new mailbox.',
        ];
    }

    protected function failedValidation(Validator $validator): never
    {
        $response = $this->expectsJson()
            ? response()->json(['message' => 'Review the highlighted fields.', 'errors' => $validator->errors()], 422)
            : redirect()->back()->withErrors($validator);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        throw new HttpResponseException($response);
    }
}
