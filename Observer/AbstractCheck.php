<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Observer;

use Fruitcake\HealthCheck\Model\CheckResult;
use Fruitcake\HealthCheck\Model\Config;
use Fruitcake\HealthCheck\Model\HealthReport;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Base class for checks. Extend it from another module, register the observer on the
 * fruitcake_healthcheck_diagnose event and add a fruitcake_healthcheck/checks/<code> flag
 * (config.xml, optionally system.xml with the CheckMode source model) to make the check switchable and strict.
 */
abstract class AbstractCheck implements ObserverInterface
{
    public function __construct(
        protected readonly Config $config
    ) {
    }

    abstract public function getCode(): string;

    abstract protected function check(): CheckResult;

    public function execute(Observer $observer): void
    {
        $report = $observer->getData('report');
        if (!$report instanceof HealthReport || !$this->config->isCheckEnabled($this->getCode())) {
            return;
        }

        $start = hrtime(true);
        try {
            $result = $this->check();
        } catch (\Throwable $e) {
            $result = CheckResult::failed($e->getMessage());
        }

        if ($result->status === CheckResult::WARNING && $this->config->isCheckStrict($this->getCode())) {
            $result = $result->withStatus(CheckResult::FAILED);
        }

        $report->add($this->getCode(), $result->withDuration((hrtime(true) - $start) / 1e6));
    }
}
