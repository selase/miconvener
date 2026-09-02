<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Component;

new class extends Component
{
    public User $user;

    public $showQrCode = false;

    public $qrCodeUrl;

    public $secret;

    public $code;

    public $password;

    public function mount(User $user)
    {
        $this->user = $user;
    }

    public function enableTwoFactor()
    {
        $google2fa = app('pragmarx.google2fa');

        $this->secret = $google2fa->generateSecretKey();
        $this->qrCodeUrl = $google2fa->getQRCodeUrl(
            config('app.name'),
            $this->user->email,
            $this->secret
        );

        $this->showQrCode = true;
    }

    public function confirmTwoFactor()
    {
        $this->validate([
            'code' => 'required|digits:6',
        ]);

        $google2fa = app('pragmarx.google2fa');

        if ($google2fa->verifyKey($this->secret, $this->code)) {
            $this->user->update([
                'two_factor_secret' => $this->secret,
                'two_factor_confirmed_at' => now(),
            ]);

            $this->showQrCode = false;
            $this->reset(['secret', 'code', 'qrCodeUrl']);

            session()->flash('success', 'Two-factor authentication has been enabled.');
        } else {
            $this->addError('code', 'The provided verification code was invalid.');
        }
    }

    public function disableTwoFactor()
    {
        $this->validate([
            'password' => 'required',
        ]);

        if (! Hash::check($this->password, auth()->user()->password)) {
            $this->addError('password', 'The provided password does not match our records.');

            return;
        }

        $this->user->update([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ]);

        $this->reset('password');
        session()->flash('success', 'Two-factor authentication has been disabled.');
    }
};

?>

<div>
    @if (session()->has('success'))
        <div class="alert alert-success mb-5">
            {{ session('success') }}
        </div>
    @endif

    <div class="card pt-4 mb-6 mb-xl-9">
        <div class="card-header border-0">
            <div class="card-title flex-column">
                <h2 class="mb-1">Two-Factor Authentication</h2>
                <div class="fs-6 fw-bold text-muted">Extra security for your account using TOTP.</div>
            </div>
        </div>
        <div class="card-body pb-5">
            @if (!$user->two_factor_secret)
                @if (!$showQrCode)
                    <div class="d-flex flex-stack">
                        <div class="d-flex flex-column">
                            <span>Status</span>
                            <span class="text-danger fs-6 fw-bolder">Disabled</span>
                        </div>
                        <div class="d-flex justify-content-end">
                            <button wire:click="enableTwoFactor" class="btn btn-primary btn-sm">Enable 2FA</button>
                        </div>
                    </div>
                @else
                    <div class="text-center py-5">
                        <h3 class="mb-5">Configure Authenticator App</h3>
                        <div class="mb-5 d-flex justify-content-center">
                            {!! (new \PragmaRX\Google2FALaravel\Support\Authenticator(request()))->getQRCodeInline(
                                config('app.name'),
                                $user->email,
                                $secret
                            ) !!}
                        </div>
                        <p class="text-muted mb-5">
                            Scan the QR code with your authenticator app (like Google Authenticator or Authy) and enter the 6-digit code below.
                        </p>
                        <div class="mx-auto w-md-200px">
                            <input type="text" wire:model="code" class="form-control form-control-solid text-center mb-3" placeholder="000000">
                            @error('code') <span class="text-danger d-block mb-3">{{ $message }}</span> @enderror
                            <button wire:click="confirmTwoFactor" class="btn btn-success w-100">Confirm & Enable</button>
                            <button wire:click="$set('showQrCode', false)" class="btn btn-link btn-sm mt-2">Cancel</button>
                        </div>
                    </div>
                @endif
            @else
                <div class="d-flex flex-stack mb-5">
                    <div class="d-flex flex-column">
                        <span>Status</span>
                        <span class="text-success fs-6 fw-bolder">Enabled</span>
                        <span class="text-muted fs-7">Activated on {{ $user->two_factor_confirmed_at?->format('M d, Y H:i') }}</span>
                    </div>
                    <div class="d-flex justify-content-end">
                        <button type="button" class="btn btn-light-danger btn-sm" data-bs-toggle="collapse" data-bs-target="#kt_2fa_disable_form">Disable 2FA</button>
                    </div>
                </div>

                <div class="collapse mt-5" id="kt_2fa_disable_form" wire:ignore.self>
                    <div class="rounded border border-dashed border-danger p-5">
                        <h4 class="text-danger mb-3">Confirm Deactivation</h4>
                        <p class="text-muted fs-7 mb-5">By disabling two-factor authentication, your account will only be protected by your password.</p>
                        <div class="d-flex align-items-end gap-3">
                            <div class="flex-grow-1">
                                <label class="form-label fs-7 fw-bold">Enter Password to Confirm</label>
                                <input type="password" wire:model="password" class="form-control form-control-solid form-control-sm">
                                @error('password') <span class="text-danger fs-8 mt-1">{{ $message }}</span> @enderror
                            </div>
                            <button wire:click="disableTwoFactor" class="btn btn-danger btn-sm">Confirm Disable</button>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
