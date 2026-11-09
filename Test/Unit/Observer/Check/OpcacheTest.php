<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Test\Unit\Observer\Check;

use Fruitcake\HealthCheck\Model\CheckResult;
use Fruitcake\HealthCheck\Model\HealthReport;
use Fruitcake\HealthCheck\Observer\Check\Opcache;
use Fruitcake\HealthCheck\Test\Unit\ConfigStub;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;

class OpcacheTest extends TestCase
{
    use ConfigStub;

    public function testHealthyOpcachePasses(): void
    {
        $result = $this->check($this->healthyStatus());

        $this->assertSame(CheckResult::OK, $result->status);
        $this->assertSame([
            'hitRate' => ['value' => 99.5, 'unit' => 'percent'],
            'memoryUsed' => ['value' => 50.0, 'unit' => 'percent'],
            'keysUsed' => ['value' => 25.0, 'unit' => 'percent'],
            'cachedScripts' => ['value' => 4000, 'unit' => null],
            'oomRestarts' => ['value' => 0, 'unit' => null],
        ], $result->measurements);
    }

    public function testDisabledWarns(): void
    {
        $result = $this->check(false, enabled: false);

        $this->assertSame(CheckResult::WARNING, $result->status);
        $this->assertSame('OPcache is disabled', $result->message);
    }

    public function testRestrictedApiWarns(): void
    {
        $result = $this->check(false);

        $this->assertSame(CheckResult::WARNING, $result->status);
        $this->assertStringContainsString('restrict_api', $result->message);
    }

    public function testFullCacheAndKeysWarn(): void
    {
        $status = $this->healthyStatus();
        $status['cache_full'] = true;
        $status['opcache_statistics']['num_cached_keys'] = 15000;

        $result = $this->check($status);

        $this->assertSame(CheckResult::WARNING, $result->status);
        $this->assertSame('OPcache cache is full, 93.8% of keys used (opcache.max_accelerated_files)', $result->message);
    }

    public function testMemoryAlmostFullWarns(): void
    {
        $status = $this->healthyStatus();
        $status['memory_usage'] = ['used_memory' => 90, 'free_memory' => 5, 'wasted_memory' => 5];

        $this->assertSame('OPcache 95% of memory used', $this->check($status)->message);
    }

    public function testLowHitRateOnlyWarnsAfterEnoughRequests(): void
    {
        $status = $this->healthyStatus();
        $status['opcache_statistics']['opcache_hit_rate'] = 50.0;
        $status['opcache_statistics']['hits'] = 500;
        $status['opcache_statistics']['misses'] = 500;
        $afterRestart = $this->check($status);

        $status['opcache_statistics']['hits'] = 50000;
        $status['opcache_statistics']['misses'] = 50000;
        $later = $this->check($status);

        $this->assertSame(CheckResult::OK, $afterRestart->status);
        $this->assertSame('OPcache hit rate 50%', $later->message);
    }

    /**
     * @return array<string, mixed> the shape of opcache_get_status(false)
     */
    private function healthyStatus(): array
    {
        return [
            'opcache_enabled' => true,
            'cache_full' => false,
            'memory_usage' => ['used_memory' => 100, 'free_memory' => 100, 'wasted_memory' => 0],
            'opcache_statistics' => [
                'num_cached_scripts' => 4000,
                'num_cached_keys' => 4000,
                'max_cached_keys' => 16000,
                'hits' => 199000,
                'misses' => 1000,
                'oom_restarts' => 0,
                'opcache_hit_rate' => 99.5,
            ],
        ];
    }

    /**
     * @param array<string, mixed>|false $status
     */
    private function check(array|false $status, bool $enabled = true): CheckResult
    {
        $check = new class ($this->createConfig(['fruitcake_healthcheck/checks/opcache' => '1']), $status, $enabled) extends Opcache {
            public function __construct($config, private readonly array|false $status, private readonly bool $enabled)
            {
                parent::__construct($config);
            }

            protected function isEnabled(): bool
            {
                return $this->enabled;
            }

            protected function getStatus(): array|false
            {
                return $this->status;
            }
        };

        $report = new HealthReport();
        $check->execute(new Observer(['report' => $report]));

        return $report->getResults()['opcache'];
    }
}
