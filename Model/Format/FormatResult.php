<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Model\Format;

class FormatResult
{
    /**
     * @param array<string, string> $headers extra headers, e.g. a Content-Security-Policy
     */
    public function __construct(
        public readonly string $body,
        public readonly string $contentType,
        public readonly int $httpCode,
        public readonly array $headers = []
    ) {
    }
}
