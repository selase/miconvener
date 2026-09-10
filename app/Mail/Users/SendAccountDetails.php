<?php

declare(strict_types=1);

namespace App\Mail\Users;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

final class SendAccountDetails extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct(
        public string $user,
        public string $email,
        public string $password,
        public ?string $loginUrl = null,
        public ?string $tenantName = null
    ) {
        //
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->markdown('emails.users.send-account-details', [
            'user' => $this->user,
            'email' => $this->email,
            'password' => $this->password,
            'loginUrl' => $this->loginUrl ?? route('login'),
            'tenantName' => $this->tenantName,
        ])
            ->subject('Congratulations!!!');
    }
}
