<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Model;

/**
 * Reads INFO from a Redis server with the Credis client Magento ships, for the cache and session checks.
 */
class RedisInfo
{
    private const TIMEOUT = 1.0;

    /**
     * @return array<string, mixed>|null null when Redis cannot be reached; the checks themselves report that
     */
    public function get(string $host, int $port, ?string $password = null, int $database = 0): ?array
    {
        if (!class_exists(\Credis_Client::class)) {
            return null;
        }

        try {
            $client = new \Credis_Client($host, $port, self::TIMEOUT, '', $database, $password ?: null);
            $info = $client->info();
            $client->close();
        } catch (\Throwable) {
            return null;
        }

        return is_array($info) ? $info : null;
    }

    /**
     * Adds memory use against maxmemory (when a limit is set) and the number of evicted keys as measurements.
     */
    public function addMeasurements(CheckResult $result, array $info, string $prefix = ''): CheckResult
    {
        $used = (float) ($info['used_memory'] ?? 0);
        $max = (float) ($info['maxmemory'] ?? 0);
        if ($max > 0) {
            $result = $result->withMeasurement($this->name($prefix, 'memoryUsed'), round($used / $max * 100, 1), 'percent');
        }

        return $result->withMeasurement($this->name($prefix, 'evictedKeys'), (int) ($info['evicted_keys'] ?? 0));
    }

    private function name(string $prefix, string $name): string
    {
        return $prefix === '' ? $name : $prefix . ucfirst($name);
    }
}
