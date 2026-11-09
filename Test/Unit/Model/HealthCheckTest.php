<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Test\Unit\Model;

use Fruitcake\HealthCheck\Model\CheckResult;
use Fruitcake\HealthCheck\Model\HealthCheck;
use Fruitcake\HealthCheck\Model\HealthReport;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Event\ConfigInterface as EventConfig;
use Magento\Framework\Event\InvokerInterface;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class HealthCheckTest extends TestCase
{
    public function testObserverThatThrowsDoesNotStopTheOthers(): void
    {
        $report = $this->runChecks([
            'database' => static fn (HealthReport $report) => $report->add('database', CheckResult::ok()),
            'vendor_broken' => static fn () => throw new \RuntimeException('broken observer'),
            'disk_space' => static fn (HealthReport $report) => $report->add('disk_space', CheckResult::failed('full')),
        ]);

        $this->assertSame(['database', 'vendor_broken', 'disk_space'], array_keys($report->getResults()));
        $this->assertSame('broken observer', $report->getResults()['vendor_broken']->message);
        $this->assertSame(CheckResult::FAILED, $report->getStatus());
    }

    public function testSameFailureIsLoggedOncePerInterval(): void
    {
        $cached = [];
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(static function ($id) use (&$cached) {
            return $cached[$id] ?? false;
        });
        $cache->method('save')->willReturnCallback(static function ($value, $id) use (&$cached) {
            $cached[$id] = $value;
            return true;
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with('Health check "cron" failed: late');

        $observers = ['cron' => static fn (HealthReport $report) => $report->add('cron', CheckResult::failed('late'))];
        $this->runChecks($observers, $cache, $logger);
        $this->runChecks($observers, $cache, $logger);
    }

    public function testLogsWhenCacheIsDown(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willThrowException(new \RuntimeException('Redis down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $this->runChecks(['cache_storage' => static fn (HealthReport $report) => $report->add(
            'cache_storage',
            CheckResult::failed('not writable')
        )], $cache, $logger);
    }

    /**
     * @param array<string, callable(HealthReport): void> $observers by observer name
     */
    private function runChecks(array $observers, ?CacheInterface $cache = null, ?LoggerInterface $logger = null): HealthReport
    {
        $eventConfig = $this->createMock(EventConfig::class);
        $eventConfig->method('getObservers')->with(HealthCheck::EVENT)->willReturn(
            array_map(static fn (string $name) => ['name' => $name], array_combine(array_keys($observers), array_keys($observers)))
        );

        $invoker = $this->createMock(InvokerInterface::class);
        $invoker->method('dispatch')->willReturnCallback(
            static fn (array $config, Observer $observer) => $observers[$config['name']]($observer->getData('report'))
        );

        return (new HealthCheck(
            $eventConfig,
            $invoker,
            $cache ?? $this->createMock(CacheInterface::class),
            $logger ?? $this->createMock(LoggerInterface::class)
        ))->run();
    }
}
