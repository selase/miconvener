<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\EventBlastRecipient;
use Illuminate\Http\Response;

final class BlastOpenController extends Controller
{
    private const string PIXEL_BASE64 = 'R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';

    public function __invoke(string $subdomain, string $recipient): Response
    {
        $recipientModel = EventBlastRecipient::find($recipient);

        if ($recipientModel && ! $recipientModel->opened_at) {
            $recipientModel->update(['opened_at' => now()]);
        }

        return response(base64_decode(self::PIXEL_BASE64), 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }
}
