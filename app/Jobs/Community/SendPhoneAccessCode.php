<?php

declare(strict_types=1);

namespace App\Jobs\Community;

use App\Services\Community\PhoneVerification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class SendPhoneAccessCode implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public string $challengeId) {}

    public function handle(PhoneVerification $verification): void
    {
        $verification->dispatchCode($this->challengeId);
    }
}
