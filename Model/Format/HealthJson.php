<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Model\Format;

use Fruitcake\HealthCheck\Model\HealthReport;
use Fruitcake\HealthCheck\Model\ServiceInfo;
use Magento\Framework\App\Request\Http as HttpRequest;

/**
 * draft-inadarei-api-health-check (application/health+json), the default format.
 * HTTP 200 for pass and warn, 503 for fail.
 */
class HealthJson implements FormatInterface
{
    public const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    public function __construct(
        private readonly ServiceInfo $serviceInfo
    ) {
    }

    public function getLabel(): string
    {
        return 'JSON';
    }

    /**
     * The default: chosen when no other format matches.
     */
    public function matches(HttpRequest $request): bool
    {
        return false;
    }

    public function render(HealthReport $report, bool $details): FormatResult
    {
        return new FormatResult(
            json_encode($this->toArray($report, $details), self::JSON_FLAGS),
            'application/health+json',
            $this->getHttpCode($report)
        );
    }

    public function getHttpCode(HealthReport $report): int
    {
        return $report->isFailed() ? 503 : 200;
    }

    public function toArray(HealthReport $report, bool $details): array
    {
        $data = ['status' => $report->getStatus()];
        if (!$details) {
            return $data;
        }

        $output = $report->getOutput();
        if ($output !== '') {
            $data['output'] = $output;
        }
        // Which node answered and which deploy it runs; both say something about the infrastructure
        $data += array_filter([
            'version' => $this->serviceInfo->getVersion(),
            'serviceId' => $this->serviceInfo->getServiceId(),
            'releaseId' => $this->serviceInfo->getReleaseId(),
        ], static fn (string $value) => $value !== '');
        // An object, also without checks
        $data['checks'] = $report->toArray() ?: new \stdClass();

        return $data;
    }
}
