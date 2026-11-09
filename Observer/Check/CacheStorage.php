<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Observer\Check;

use Fruitcake\HealthCheck\Model\CheckResult;
use Fruitcake\HealthCheck\Model\Config;
use Fruitcake\HealthCheck\Model\RedisInfo;
use Fruitcake\HealthCheck\Observer\AbstractCheck;
use Magento\Framework\App\Cache\Frontend\Pool;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Cache\FrontendInterface;

/**
 * Writes, reads and removes a value in every cache frontend (default, page_cache, ...).
 * The meta lists the backend of each frontend, e.g. {"default": "Redis", "page_cache": "File"}.
 */
class CacheStorage extends AbstractCheck
{
    public function __construct(
        Config $config,
        private readonly Pool $cachePool,
        private readonly DeploymentConfig $deploymentConfig,
        private readonly RedisInfo $redisInfo
    ) {
        parent::__construct($config);
    }

    protected function measuresResponseTime(): bool
    {
        return true;
    }

    public function getCode(): string
    {
        return 'cache_storage';
    }

    protected function check(): CheckResult
    {
        $backends = [];
        $failed = [];
        foreach ($this->cachePool as $id => $frontend) {
            $backends[$id] = $this->getBackendName($frontend);
            $cacheId = 'fruitcake_healthcheck_' . bin2hex(random_bytes(4));
            $value = (string) time();
            try {
                $frontend->save($value, $cacheId, [], 60);
                $ok = $frontend->load($cacheId) === $value;
                $frontend->remove($cacheId);
            } catch (\Throwable $e) {
                $ok = false;
            }
            if (!$ok) {
                $failed[] = $id;
            }
        }

        if ($failed) {
            return CheckResult::failed(
                'Cache storage not writable: ' . implode(', ', $failed),
                ['backends' => $backends, 'failed' => $failed]
            );
        }

        return $this->addRedisMeasurements(CheckResult::ok('', ['backends' => $backends]), $backends);
    }

    /**
     * Memory use and evictions per Redis cache frontend, e.g. defaultMemoryUsed and pageCacheEvictedKeys.
     * Only measured: a cache that evicts with an LRU policy is doing its job.
     *
     * @param array<string, string> $backends
     */
    private function addRedisMeasurements(CheckResult $result, array $backends): CheckResult
    {
        foreach ($backends as $id => $backend) {
            if ($backend !== 'Redis') {
                continue;
            }
            $options = (array) $this->deploymentConfig->get('cache/frontend/' . $id . '/backend_options', []);
            $info = $this->redisInfo->get(
                (string) ($options['server'] ?? '127.0.0.1'),
                (int) ($options['port'] ?? 6379),
                (string) ($options['password'] ?? ''),
                (int) ($options['database'] ?? 0)
            );
            if ($info !== null) {
                $prefix = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', (string) $id))));
                $result = $this->redisInfo->addMeasurements($result, $info, $prefix);
            }
        }

        return $result;
    }

    /**
     * Short name of the backend class: Cm_Cache_Backend_Redis and Magento\Framework\Cache\Backend\Redis become Redis.
     */
    private function getBackendName(FrontendInterface $frontend): string
    {
        try {
            $class = get_class($frontend->getBackend());
        } catch (\Throwable $e) {
            return 'unknown';
        }

        return (string) preg_replace('/^.*[\\\\_]/', '', $class);
    }
}
