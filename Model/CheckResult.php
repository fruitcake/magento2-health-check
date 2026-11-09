<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Model;

class CheckResult
{
    // Status values of draft-inadarei-api-health-check
    public const OK = 'pass';
    public const WARNING = 'warn';
    public const FAILED = 'fail';

    /**
     * @param array<string, array{value: int|float, unit: ?string}> $measurements by measurement name
     */
    private function __construct(
        public readonly string $status,
        public readonly string $message = '',
        public readonly array $meta = [],
        public readonly ?float $durationMs = null,
        public readonly array $measurements = []
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
        return new self($status, $this->message, $this->meta, $this->durationMs, $this->measurements);
    }

    public function withDuration(float $durationMs): self
    {
        return new self($this->status, $this->message, $this->meta, $durationMs, $this->measurements);
    }

    /**
     * A measured value, reported as "<code>:<name>" with observedValue and observedUnit, as
     * draft-inadarei-api-health-check prescribes, e.g. withMeasurement('hitRate', 99.5, 'percent').
     *
     * @param string $name camelCase, e.g. hitRate
     * @param string|null $unit e.g. percent, ms, s, min, MiB
     */
    public function withMeasurement(string $name, int|float $value, ?string $unit = null): self
    {
        $measurements = $this->measurements;
        $measurements[$name] = ['value' => $value, 'unit' => $unit];

        return new self($this->status, $this->message, $this->meta, $this->durationMs, $measurements);
    }

    /**
     * A check object of draft-inadarei-api-health-check. The draft only wants "output" for warn and fail;
     * "meta" and "durationMs" are additions of this module.
     */
    public function toArray(): array
    {
        return array_filter([
            'status' => $this->status,
            'output' => $this->status !== self::OK ? $this->message : '',
            'durationMs' => $this->durationMs !== null ? round($this->durationMs, 1) : null,
            'meta' => $this->meta,
        ], static fn ($value) => $value !== '' && $value !== [] && $value !== null);
    }

    /**
     * The check objects of the measurements, by measurement name. They share the status of the check.
     *
     * @return array<string, array<string, mixed>>
     */
    public function measurementsToArray(): array
    {
        return array_map(fn (array $measurement) => array_filter([
            'observedValue' => $measurement['value'],
            'observedUnit' => $measurement['unit'],
            'status' => $this->status,
        ], static fn ($value) => $value !== null), $this->measurements);
    }
}
