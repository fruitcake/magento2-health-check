<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Model;

use Magento\Framework\Event\ManagerInterface;
use Psr\Log\LoggerInterface;

class HealthCheck
{
    public const EVENT = 'fruitcake_healthcheck_diagnose';

    public function __construct(
        private readonly ManagerInterface $eventManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function run(): HealthReport
    {
        $report = new HealthReport();

        try {
            $this->eventManager->dispatch(self::EVENT, ['report' => $report]);
        } catch (\Throwable $e) {
            // An observer that throws instead of adding a result still marks the application as down
            $report->add('diagnose', CheckResult::failed($e->getMessage()));
        }

        foreach ($report->getResults() as $code => $result) {
            if ($result->status === CheckResult::FAILED) {
                $this->logger->error(sprintf('Health check "%s" failed: %s', $code, $result->message));
            }
        }

        return $report;
    }
}
