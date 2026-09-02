<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class QuotaExceededException extends RuntimeException
{
    public function __construct(
        string $message = 'Quota exceeded.',
        public readonly ?string $resource = null,
        public readonly int|float $currentUsage = 0,
        public readonly int|float $limit = 0,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function forLlmTokens(int $requested, int $remaining): self
    {
        return new self(
            message: "LLM token quota exceeded. Requested: {$requested}, remaining: {$remaining}.",
            resource: 'llm_tokens',
            currentUsage: $requested,
            limit: $remaining,
        );
    }

    public static function forFeature(string $featureSlug, int $used, int $limit): self
    {
        return new self(
            message: "Feature '{$featureSlug}' usage limit reached ({$used}/{$limit}).",
            resource: $featureSlug,
            currentUsage: $used,
            limit: $limit,
        );
    }
}
