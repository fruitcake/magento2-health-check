<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Model\Format;

use Fruitcake\HealthCheck\Model\CheckResult;
use Fruitcake\HealthCheck\Model\HealthReport;
use Magento\Framework\App\Request\Http as HttpRequest;

/**
 * The format of Oh Dear's Application Health monitoring:
 * https://ohdear.app/docs/features/application-health-monitoring
 *
 * Chosen for requests with the oh-dear-health-check-secret header Oh Dear sends. Always HTTP 200:
 * the status of every check is in the body.
 */
class OhDear implements FormatInterface
{
    public const SECRET_HEADER = 'oh-dear-health-check-secret';

    private const STATUSES = [
        CheckResult::OK => 'ok',
        CheckResult::WARNING => 'warning',
        CheckResult::FAILED => 'failed',
    ];

    /** Oh Dear accepts at most 50 check results and 20 meta items per check */
    private const MAX_CHECKS = 50;
    private const MAX_META = 20;

    public function getLabel(): string
    {
        return 'Oh Dear';
    }

    public function matches(HttpRequest $request): bool
    {
        return $request->getHeader(self::SECRET_HEADER) !== false;
    }

    public function render(HealthReport $report, bool $details): FormatResult
    {
        return new FormatResult(
            json_encode($this->toArray($report, $details), HealthJson::JSON_FLAGS),
            'application/json',
            200
        );
    }

    /**
     * @param bool $details false gives one result with the overall status, as the health+json format
     *                      gives only "status" without details
     */
    public function toArray(HealthReport $report, bool $details): array
    {
        if (!$details) {
            $status = $report->getStatus();
            $results = [[
                'name' => 'health',
                'label' => 'Health',
                'status' => self::STATUSES[$status] ?? 'failed',
                'notificationMessage' => '',
                'shortSummary' => $this->summarize($status),
                'meta' => new \stdClass(),
            ]];
        } else {
            $results = [];
            foreach ($report->getResults() as $code => $result) {
                $results[] = $this->toResult((string) $code, $result);
            }
        }

        return [
            'finishedAt' => time(),
            'checkResults' => array_slice($results, 0, self::MAX_CHECKS),
        ];
    }

    private function toResult(string $code, CheckResult $result): array
    {
        $meta = $result->meta;
        if ($result->durationMs !== null) {
            $meta['duration_ms'] = round($result->durationMs, 1);
        }

        return [
            'name' => $code,
            'label' => ucfirst(str_replace('_', ' ', $code)),
            'status' => self::STATUSES[$result->status] ?? 'failed',
            'notificationMessage' => $result->message,
            'shortSummary' => $this->summarize($result->status),
            'meta' => $this->flatten($meta) ?: new \stdClass(),
        ];
    }

    private function summarize(string $status): string
    {
        return match ($status) {
            CheckResult::OK => 'OK',
            CheckResult::WARNING => 'Warning',
            default => 'Failed',
        };
    }

    /**
     * Oh Dear shows meta as a list of keys and values, so nested values become one string:
     * a list is joined with commas, anything else is JSON.
     */
    private function flatten(array $meta): array
    {
        $flat = [];
        foreach (array_slice($meta, 0, self::MAX_META, true) as $key => $value) {
            if (is_array($value)) {
                $value = array_is_list($value)
                    ? implode(', ', array_map('strval', $value))
                    : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            }
            $flat[(string) $key] = $value;
        }

        return $flat;
    }
}
