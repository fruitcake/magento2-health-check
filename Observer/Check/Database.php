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

    protected function check(): CheckResult
    {
        $this->resource->getConnection()->fetchOne('SELECT 1');

        return CheckResult::ok();
    }
}
