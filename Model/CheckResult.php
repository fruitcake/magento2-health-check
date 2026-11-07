<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Model;

class CheckResult
{
    public const OK = 'ok';
    public const WARNING = 'warning';
    public const FAILED = 'failed';

    private function __construct(
        public readonly string $status,
        public readonly string $message = '',
        public readonly array $meta = [],
        public readonly ?float $durationMs = null
    ) {
    }

    public static function ok(string $message = '', array $meta = []): self
    {
        return new self(self::OK, $message, $meta);
    }

    public static function warning(string $message, array $meta = []): self
    {
        return new self(self::WARNING, $message, $meta);
    }

    public static function failed(string $message, array $meta = []): self
    {
        return new self(self::FAILED, $message, $meta);
    }

    public function withStatus(string $status): self
    {
        return new self($status, $this->message, $this->meta, $this->durationMs);
    }

    public function withDuration(float $durationMs): self
    {
        return new self($this->status, $this->message, $this->meta, $durationMs);
    }

    public function toArray(): array
    {
        return array_filter([
            'status' => $this->status,
            'message' => $this->message,
            'meta' => $this->meta,
            'duration_ms' => $this->durationMs !== null ? round($this->durationMs, 1) : null,
        ], static fn ($value) => $value !== '' && $value !== [] && $value !== null);
    }
}
