<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Observer\Check;

use Fruitcake\HealthCheck\Model\CheckResult;
use Fruitcake\HealthCheck\Observer\AbstractCheck;

/**
 * OPcache of the PHP-FPM pool that answers the request, so the result is per web node.
 * Warns when OPcache is off or about to run out of memory or keys: PHP then compiles every
 * file on every request, which makes Magento several times slower.
 */
class Opcache extends AbstractCheck
{
    /** Usage of memory or keys, in percent, from which the check warns */
    private const MAX_USAGE = 90;

    /** Hit rate, in percent, below which the check warns */
    private const MIN_HIT_RATE = 90;

    /** Hits plus misses before the hit rate counts: it starts low after every restart or deploy */
    private const MIN_REQUESTS = 10000;

    public function getCode(): string
    {
        return 'opcache';
    }

    protected function check(): CheckResult
    {
        if (!$this->isEnabled()) {
            return CheckResult::warning('OPcache is disabled');
        }

        $status = $this->getStatus();
        if (!is_array($status)) {
            return CheckResult::warning('OPcache status is not available, e.g. because of opcache.restrict_api');
        }

        $memory = $status['memory_usage'] ?? [];
        $statistics = $status['opcache_statistics'] ?? [];

        $memoryTotal = ($memory['used_memory'] ?? 0) + ($memory['free_memory'] ?? 0) + ($memory['wasted_memory'] ?? 0);
        $memoryUsed = $memoryTotal > 0
            ? round((($memory['used_memory'] ?? 0) + ($memory['wasted_memory'] ?? 0)) / $memoryTotal * 100, 1)
            : 0.0;
        $maxKeys = (int) ($statistics['max_cached_keys'] ?? 0);
        $keysUsed = $maxKeys > 0 ? round(($statistics['num_cached_keys'] ?? 0) / $maxKeys * 100, 1) : 0.0;
        $hitRate = round((float) ($statistics['opcache_hit_rate'] ?? 0), 1);
        $requests = (int) ($statistics['hits'] ?? 0) + (int) ($statistics['misses'] ?? 0);

        $meta = [
            'hit_rate' => $hitRate,
            'memory_used_percent' => $memoryUsed,
            'keys_used_percent' => $keysUsed,
            'cached_scripts' => (int) ($statistics['num_cached_scripts'] ?? 0),
            'oom_restarts' => (int) ($statistics['oom_restarts'] ?? 0),
        ];

        $problems = [];
        if (!empty($status['cache_full'])) {
            $problems[] = 'cache is full';
        } elseif ($memoryUsed >= self::MAX_USAGE) {
            $problems[] = sprintf('%s%% of memory used', $memoryUsed);
        }
        if ($keysUsed >= self::MAX_USAGE) {
            $problems[] = sprintf('%s%% of keys used (opcache.max_accelerated_files)', $keysUsed);
        }
        if ($requests >= self::MIN_REQUESTS && $hitRate < self::MIN_HIT_RATE) {
            $problems[] = sprintf('hit rate %s%%', $hitRate);
        }

        return $problems
            ? CheckResult::warning('OPcache ' . implode(', ', $problems), $meta)
            : CheckResult::ok('', $meta);
    }

    protected function isEnabled(): bool
    {
        return function_exists('opcache_get_status') && filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return array<string, mixed>|false
     */
    protected function getStatus(): array|false
    {
        // Without scripts: the list is large and not needed. Warns and returns false when restrict_api blocks it.
        return @opcache_get_status(false);
    }
}
