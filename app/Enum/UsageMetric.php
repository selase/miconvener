<?php

declare(strict_types=1);

namespace App\Enum;

enum UsageMetric: string
{
    // HTTP Requests
    case REQUEST_COUNT = 'requests.count';
    case REQUEST_DURATION_MS = 'requests.duration_ms';
    case REQUEST_SIZE_BYTES = 'requests.size_bytes';
    case RESPONSE_SIZE_BYTES = 'responses.size_bytes';

    // Queue Jobs
    case JOB_COUNT = 'jobs.count';
    case JOB_RUNTIME_MS = 'jobs.runtime_ms';
    case JOB_FAILED_COUNT = 'jobs.failed_count';

    // Storage
    case STORAGE_BYTES = 'storage.bytes';
    case STORAGE_UPLOAD_COUNT = 'storage.upload_count';

    // Database
    case DB_BYTES = 'db.bytes';
    case DB_ROW_COUNT = 'db.row_count';

    // Users
    case USER_ACTIVE_DAILY = 'users.active_daily';
    case USER_ACTIVE_MONTHLY = 'users.active_monthly';

    // Outbound Operations
    case EMAIL_COUNT = 'emails.count';
    case NOTIFICATION_COUNT = 'notifications.count';
    case WEBHOOK_COUNT = 'webhooks.count';

    /**
     * Get the unit for the metric.
     */
    public function unit(): string
    {
        return match ($this) {
            self::REQUEST_COUNT, self::JOB_COUNT, self::JOB_FAILED_COUNT,
            self::STORAGE_UPLOAD_COUNT, self::USER_ACTIVE_DAILY, self::USER_ACTIVE_MONTHLY,
            self::EMAIL_COUNT, self::NOTIFICATION_COUNT, self::WEBHOOK_COUNT,
            self::DB_ROW_COUNT => 'count',

            self::REQUEST_DURATION_MS, self::JOB_RUNTIME_MS => 'ms',

            self::REQUEST_SIZE_BYTES, self::RESPONSE_SIZE_BYTES,
            self::STORAGE_BYTES, self::DB_BYTES => 'bytes',
        };
    }
}
