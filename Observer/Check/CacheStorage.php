<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Observer\Check;

use Fruitcake\HealthCheck\Model\CheckResult;
use Fruitcake\HealthCheck\Model\Config;
use Fruitcake\HealthCheck\Observer\AbstractCheck;
use Magento\Framework\App\Cache\Frontend\Pool;
use Magento\Framework\Cache\FrontendInterface;

/**
 * Writes, reads and removes a value in every cache frontend (default, page_cache, ...).
 * The meta lists the backend of each frontend, e.g. {"default": "Redis", "page_cache": "File"}.
 */
class CacheStorage extends AbstractCheck
{
    public function __construct(
        Config $config,
        private readonly Pool $cachePool
    ) {
        parent::__construct($config);
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

        return CheckResult::ok('', ['backends' => $backends]);
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
