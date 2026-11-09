<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Observer\Check;

use Fruitcake\HealthCheck\Model\CheckResult;
use Fruitcake\HealthCheck\Model\Config;
use Fruitcake\HealthCheck\Observer\AbstractCheck;
use Magento\Framework\App\ResourceConnection;

class Database extends AbstractCheck
{
    public function __construct(
        Config $config,
        private readonly ResourceConnection $resource
    ) {
        parent::__construct($config);
    }

    protected function measuresResponseTime(): bool
    {
        return true;
    }

    public function getCode(): string
    {
        return 'database';
    }

    /** Share of max_connections in use from which the check warns */
    private const MAX_CONNECTIONS_USED = 80;

    protected function check(): CheckResult
    {
        $connection = $this->resource->getConnection();
        $connection->fetchOne('SELECT 1');

        // Connections against the server limit: a leak or overload shows before MySQL refuses connections
        try {
            $connected = (int) $connection->fetchRow("SHOW GLOBAL STATUS LIKE 'Threads_connected'")['Value'];
            $max = (int) $connection->fetchRow("SHOW GLOBAL VARIABLES LIKE 'max_connections'")['Value'];
        } catch (\Throwable) {
            return CheckResult::ok();
        }
        if ($max <= 0) {
            return CheckResult::ok();
        }

        $used = round($connected / $max * 100, 1);
        $result = $used >= self::MAX_CONNECTIONS_USED
            ? CheckResult::warning(sprintf('%d of %d database connections in use (%s%%)', $connected, $max, $used))
            : CheckResult::ok();

        return $result
            ->withMeasurement('connections', $connected)
            ->withMeasurement('connectionsUsed', $used, 'percent');
    }
}
