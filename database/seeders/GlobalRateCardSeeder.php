<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enum\UsageMetric;
use App\Models\UsagePrice;
use Illuminate\Database\Seeder;

final class GlobalRateCardSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Seeding with rough AWS-inspired baseline prices.
     */
    public function run(): void
    {
        $prices = [
            // HTTP Requests - AWS API Gateway style ($1.00 per 1M requests)
            UsageMetric::REQUEST_COUNT->value => [
                'unit_price' => 1.00,
                'unit_quantity' => 1000000,
            ],
            // Request Duration - AWS Lambda style (approx $0.0000166667 per GB-second)
            // Simplified: $0.02 per 1,000,000ms (1000 seconds)
            UsageMetric::REQUEST_DURATION_MS->value => [
                'unit_price' => 0.02,
                'unit_quantity' => 1000000,
            ],
            // Request/Response Size - Data Transfer style ($0.09 per GB)
            UsageMetric::REQUEST_SIZE_BYTES->value => [
                'unit_price' => 0.09,
                'unit_quantity' => 1073741824, // 1 GB
            ],
            UsageMetric::RESPONSE_SIZE_BYTES->value => [
                'unit_price' => 0.09,
                'unit_quantity' => 1073741824, // 1 GB
            ],

            // Queue Jobs - SQS style ($0.40 per 1M requests)
            UsageMetric::JOB_COUNT->value => [
                'unit_price' => 0.40,
                'unit_quantity' => 1000000,
            ],
            UsageMetric::JOB_RUNTIME_MS->value => [
                'unit_price' => 0.02,
                'unit_quantity' => 1000000,
            ],

            // Storage - S3 Standard style ($0.023 per GB)
            UsageMetric::STORAGE_BYTES->value => [
                'unit_price' => 0.023,
                'unit_quantity' => 1073741824, // 1 GB
            ],
            UsageMetric::STORAGE_UPLOAD_COUNT->value => [
                'unit_price' => 0.005,
                'unit_quantity' => 1000, // $0.005 per 1,000 PUT/POST
            ],

            // Database - RDS/DynamoDB Storage style ($0.10 - $0.25 per GB)
            UsageMetric::DB_BYTES->value => [
                'unit_price' => 0.25,
                'unit_quantity' => 1073741824, // 1 GB
            ],
            UsageMetric::DB_ROW_COUNT->value => [
                'unit_price' => 0.01,
                'unit_quantity' => 1000, // Nominal fee per 1k rows
            ],

            // Users - Cognito MAU style ($0.0055 per MAU)
            UsageMetric::USER_ACTIVE_MONTHLY->value => [
                'unit_price' => 0.0055,
                'unit_quantity' => 1,
            ],
            UsageMetric::USER_ACTIVE_DAILY->value => [
                'unit_price' => 0.0002,
                'unit_quantity' => 1,
            ],

            // Outbound Operations - SES style ($0.10 per 1,000 emails)
            UsageMetric::EMAIL_COUNT->value => [
                'unit_price' => 0.10,
                'unit_quantity' => 1000,
            ],
            UsageMetric::NOTIFICATION_COUNT->value => [
                'unit_price' => 0.06,
                'unit_quantity' => 1000, // Push notifications
            ],
            UsageMetric::WEBHOOK_COUNT->value => [
                'unit_price' => 0.05,
                'unit_quantity' => 1000,
            ],
        ];

        foreach ($prices as $metric => $data) {
            UsagePrice::updateOrCreate(
                [
                    'target_type' => null,
                    'target_id' => null,
                    'metric' => $metric,
                ],
                [
                    'unit_price' => $data['unit_price'],
                    'unit_quantity' => $data['unit_quantity'],
                    'currency' => 'USD',
                ]
            );
        }
    }
}
