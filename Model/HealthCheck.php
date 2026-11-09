<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\ConfigInterface as EventConfig;
use Magento\Framework\Event\InvokerInterface;
use Magento\Framework\Event\Observer;
use Psr\Log\LoggerInterface;

class HealthCheck
{
    public const EVENT = 'fruitcake_healthcheck_diagnose';

    /** Seconds before the same failure is logged again */
    private const LOG_INTERVAL = 300;

    public function __construct(
        private readonly EventConfig $eventConfig,
        private readonly InvokerInterface $invoker,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger
    ) {
    }

    public function run(): HealthReport
    {
        $report = new HealthReport();

        // Calls the observers one by one, as the event manager does, so one that throws does not stop the rest
        foreach ($this->eventConfig->getObservers(self::EVENT) as $observerConfig) {
            $event = new Event(['report' => $report]);
            $event->setName(self::EVENT);
            $wrapper = new Observer(['event' => $event, 'report' => $report]);

            try {
                $this->invoker->dispatch($observerConfig, $wrapper);
            } catch (\Throwable $e) {
                // An observer that throws instead of adding a result still marks the application as down
                $report->add((string) ($observerConfig['name'] ?? 'diagnose'), CheckResult::failed($e->getMessage()));
            }
        }

        foreach ($report->getResults() as $code => $result) {
            if ($result->status === CheckResult::FAILED) {
                $this->logFailure((string) $code, $result->message);
            }
        }

        return $report;
    }

    /**
     * Logs a failure at most once per LOG_INTERVAL, so monitors probing every few seconds do not flood the log.
     */
    private function logFailure(string $code, string $message): void
    {
        $cacheId = 'fruitcake_healthcheck_logged_' . hash('sha256', $code . "\n" . $message);
        try {
            if ($this->cache->load($cacheId)) {
                return;
            }
            $this->cache->save('1', $cacheId, [], self::LOG_INTERVAL);
        } catch (\Throwable) {
            // The cache may be what is down: log every time rather than never
        }

        $this->logger->error(sprintf('Health check "%s" failed: %s', $code, $message));
    }
}
