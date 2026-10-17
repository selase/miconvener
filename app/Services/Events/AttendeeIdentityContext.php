<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Services\Community\PhoneVerification;
use Illuminate\Contracts\Session\Session;

final class AttendeeIdentityContext
{
    /** @return array{state:string, owner_key?:string, proof_expires_at?:int} */
    public function for(Session $session): array
    {
        $email = app(PlatformAttendeeVerification::class)->verifiedEmail($session);
        $phone = app(PhoneVerification::class)->verifiedPhone($session);
        if ($email === null && $phone === null) {
            return ['state' => 'signed_out'];
        }
        $proofs = [];
        $expires = [];
        foreach ([PlatformAttendeeVerification::SESSION_KEY => $email, PhoneVerification::SESSION_KEY => $phone] as $key => $address) {
            if ($address !== null) {
                $proof = $session->get($key);
                $proofs[$key] = $proof;
                $expires[] = (int) $proof['expires_at'];
            }
        }
        $signature = json_encode([$session->getId(), $proofs], JSON_THROW_ON_ERROR);

        return ['state' => 'verified', 'owner_key' => hash_hmac('sha256', 'attendee-offline:v3:'.$signature, (string) config('app.key')), 'proof_expires_at' => min($expires) * 1000];
    }
}
