<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Test\Unit\Observer\Check;

use Fruitcake\HealthCheck\Model\CheckResult;
use Fruitcake\HealthCheck\Model\HealthReport;
use Fruitcake\HealthCheck\Model\RedisInfo;
use Fruitcake\HealthCheck\Observer\Check\CacheStorage;
use Fruitcake\HealthCheck\Test\Unit\ConfigStub;
use Magento\Framework\App\Cache\Frontend\Pool;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Cache\FrontendInterface;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;

class CacheStorageTest extends TestCase
{
    use ConfigStub;

    public function testListsBackendPerFrontend(): void
    {
        $result = $this->check([
            'default' => $this->frontend($this->backend(\Magento\Framework\Cache\Backend\Database::class)),
            'page_cache' => $this->frontend($this->backend(\Zend_Cache_Backend_File::class)),
        ]);

        $this->assertSame(CheckResult::OK, $result->status);
        $this->assertSame(['backends' => ['default' => 'Database', 'page_cache' => 'File']], $result->meta);
    }

    public function testRedisFrontendsAreMeasured(): void
    {
        $result = $this->check([
            'default' => $this->frontend(new Fixture\Redis()),
            'page_cache' => $this->frontend($this->backend(\Zend_Cache_Backend_File::class)),
        ], ['used_memory' => 512, 'maxmemory' => 1024, 'evicted_keys' => 7]);

        $this->assertSame(CheckResult::OK, $result->status);
        $this->assertSame(['value' => 50.0, 'unit' => 'percent'], $result->measurements['defaultMemoryUsed']);
        $this->assertSame(['value' => 7, 'unit' => null], $result->measurements['defaultEvictedKeys']);
        $this->assertArrayNotHasKey('pageCacheMemoryUsed', $result->measurements);
    }

    public function testFrontendThatDoesNotReadBackFails(): void
    {
        $result = $this->check([
            'default' => $this->frontend($this->backend(\Zend_Cache_Backend_File::class)),
            'page_cache' => $this->frontend($this->backend(\Zend_Cache_Backend_File::class), works: false),
        ]);

        $this->assertSame(CheckResult::FAILED, $result->status);
        $this->assertSame('Cache storage not writable: page_cache', $result->message);
        $this->assertSame(['page_cache'], $result->meta['failed']);
    }

    /**
     * A real class instead of a mock, whose generated class name would not end in the backend name.
     */
    private function backend(string $class): object
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    private function frontend(object $backend, bool $works = true): FrontendInterface
    {
        $stored = [];
        $frontend = $this->createMock(FrontendInterface::class);
        $frontend->method('getBackend')->willReturn($backend);
        $frontend->method('save')->willReturnCallback(
            static function ($value, $id) use (&$stored, $works) {
                if ($works) {
                    $stored[$id] = $value;
                }
                return $works;
            }
        );
        $frontend->method('load')->willReturnCallback(
            static function ($id) use (&$stored) {
                return $stored[$id] ?? false;
            }
        );

        return $frontend;
    }

    /**
     * @param array<string, FrontendInterface> $frontends
     */
    private function check(array $frontends, ?array $redisInfo = null): CheckResult
    {
        $iterator = new \ArrayIterator($frontends);
        $pool = $this->createMock(Pool::class);
        $pool->method('rewind')->willReturnCallback(static fn () => $iterator->rewind());
        $pool->method('valid')->willReturnCallback(static fn () => $iterator->valid());
        $pool->method('current')->willReturnCallback(static fn () => $iterator->current());
        $pool->method('key')->willReturnCallback(static fn () => $iterator->key());
        $pool->method('next')->willReturnCallback(static fn () => $iterator->next());

        $report = new HealthReport();
        $deploymentConfig = $this->createMock(DeploymentConfig::class);
        $deploymentConfig->method('get')->willReturn(['server' => 'redis', 'port' => '6379']);
        $redis = $this->createPartialMock(RedisInfo::class, ['get']);
        $redis->method('get')->willReturn($redisInfo);

        $check = new CacheStorage(
            $this->createConfig(['fruitcake_healthcheck/checks/cache_storage' => '1']),
            $pool,
            $deploymentConfig,
            $redis
        );
        $check->execute(new Observer(['report' => $report]));

        return $report->getResults()['cache_storage'];
    }
}
