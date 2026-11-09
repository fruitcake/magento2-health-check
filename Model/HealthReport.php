<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Model;

/**
 * Passed as "report" with the fruitcake_healthcheck_diagnose event. Observers add one result per check.
 */
class HealthReport
{
    /** @var CheckResult[] */
    private array $results = [];

    public function add(string $code, CheckResult $result): void
    {
        $this->results[$code] = $result;
    }

    /**
     * @return CheckResult[]
     */
    public function getResults(): array
    {
        return $this->results;
    }

    public function getStatus(): string
    {
        $statuses = array_map(static fn (CheckResult $result) => $result->status, $this->results);

        if (in_array(CheckResult::FAILED, $statuses, true)) {
            return CheckResult::FAILED;
        }

        return in_array(CheckResult::WARNING, $statuses, true) ? CheckResult::WARNING : CheckResult::OK;
    }

    public function isFailed(): bool
    {
        return $this->getStatus() === CheckResult::FAILED;
    }

    /**
     * The "checks" object of draft-inadarei-api-health-check: every check holds a list of check objects.
     */
    public function toArray(): array
    {
        return array_map(static fn (CheckResult $result) => [$result->toArray()], $this->results);
    }
}
