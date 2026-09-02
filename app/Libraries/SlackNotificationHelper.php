<?php

declare(strict_types=1);

namespace App\Libraries;

use Illuminate\Support\Facades\Log;
use Stringable;

final class SlackNotificationHelper
{
    public static function sendLog(string|Stringable $message, $log_type = 'info'): mixed
    {
        $alert = '';

        match ($log_type) {
            'info' => $alert .= Log::channel('slack')->info($message),

            'error' => $alert .= Log::channel('slack')->error($message),

            'emergency' => $alert .= Log::channel('slack')->emergency($message),

            'critical' => $alert .= Log::channel('slack')->critical($message),

            'alert' => $alert .= Log::channel('slack')->alert($message),

            'notice' => $alert .= Log::channel('slack')->notice($message),

            'debug' => $alert .= Log::channel('slack')->debug($message),

            'warning' => $alert .= Log::channel('slack')->warning($message),

            default => $alert .= Log::channel('slack')->info($message),
        };

        return $alert;
    }
}
