<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Models\OrganisationInvitation;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;

abstract class OrganisationInvitationTokenRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $key = 'organisation-invitations:public-ip:'.$this->ip();
        abort_if(RateLimiter::tooManyAttempts($key, 60), 429);
        RateLimiter::hit($key, 3600);
    }

    /** @return array<string, list<string>> */
    protected function tokenRules(): array
    {
        return ['token' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/D']];
    }

    protected function failedValidation(Validator $validator): void
    {
        $token = $this->input('token');
        $invitation = $this->route('invitation');
        if (is_string($token) && $invitation instanceof OrganisationInvitation && $invitation->status === 'pending' && $invitation->expires_at->isFuture()
            && hash_equals((string) $invitation->token_hash, hash('sha256', $token))) {
            $this->session()->put('organisation_invitation', ['id' => $invitation->id, 'token' => Crypt::encryptString($token)]);
        }
        foreach (['token', 'code', 'secret'] as $sensitiveInput) {
            $this->request->remove($sensitiveInput);
            request()->request->remove($sensitiveInput);
        }
        parent::failedValidation($validator);
    }
}
