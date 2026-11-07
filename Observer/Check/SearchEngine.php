<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Observer\Check;

use Fruitcake\HealthCheck\Model\CheckResult;
use Fruitcake\HealthCheck\Model\Config;
use Fruitcake\HealthCheck\Observer\AbstractCheck;
use Magento\AdvancedSearch\Model\Client\ClientResolver;

class SearchEngine extends AbstractCheck
{
    public function __construct(
        Config $config,
        private readonly ClientResolver $clientResolver
    ) {
        parent::__construct($config);
    }

    public function getCode(): string
    {
        return 'search_engine';
    }

    protected function check(): CheckResult
    {
        $engine = (string) $this->clientResolver->getCurrentEngine();
        $meta = ['engine' => $engine];

        if (!$this->clientResolver->create()->testConnection()) {
            return CheckResult::failed(sprintf('Search engine "%s" is not reachable', $engine), $meta);
        }

        return CheckResult::ok('', $meta);
    }
}
