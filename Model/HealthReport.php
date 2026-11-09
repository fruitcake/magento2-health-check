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
     * The "output" of draft-inadarei-api-health-check: every check that did not pass, failures first,
     * e.g. "search_engine: Search engine is not reachable; cron: Last cron job finished 42 minutes ago".
     */
    public function getOutput(): string
    {
        $lines = [];
        foreach ([CheckResult::FAILED, CheckResult::WARNING] as $status) {
            foreach ($this->results as $code => $result) {
                if ($result->status === $status) {
                    $lines[] = $result->message !== '' ? $code . ': ' . $result->message : $code;
                }
            }
        }

        return implode('; ', $lines);
    }

    /**
     * The "checks" object of draft-inadarei-api-health-check: every check holds a list of check objects,
     * followed by its measurements as "<code>:<measurement>", e.g. "opcache:hitRate".
     */
    public function toArray(): array
    {
        $checks = [];
        foreach ($this->results as $code => $result) {
            $checks[$code] = [$result->toArray()];
            foreach ($result->measurementsToArray() as $name => $measurement) {
                $checks[$code . ':' . $name] = [$measurement];
            }
        }

        return $checks;
    }
}
