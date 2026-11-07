<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Observer\Check;

use Fruitcake\HealthCheck\Model\CheckResult;
use Fruitcake\HealthCheck\Model\Config;
use Fruitcake\HealthCheck\Observer\AbstractCheck;
use Magento\Framework\App\Cache\Frontend\Pool;

/**
 * Writes, reads and removes a value in every cache frontend (default, page_cache, ...).
 */
class Cache extends AbstractCheck
{
    public function __construct(
        Config $config,
        private readonly Pool $cachePool
    ) {
        parent::__construct($config);
    }

    public function getCode(): string
    {
        return 'cache';
    }

    protected function check(): CheckResult
    {
        $failed = [];
        foreach ($this->cachePool as $id => $frontend) {
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
            return CheckResult::failed('Cache storage not writable: ' . implode(', ', $failed), ['frontends' => $failed]);
        }

        return CheckResult::ok();
    }
}
