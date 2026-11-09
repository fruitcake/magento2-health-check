<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Model\SearchEngine;

use Magento\Framework\ObjectManagerInterface;

/**
 * Smile ElasticSuite (engine "elasticsuite") keeps its own connection settings and client.
 * ElasticSuite is optional, so its client is fetched only when installed: a constructor dependency
 * on it would break setup:di:compile in shops without ElasticSuite.
 */
class ElasticsuiteConnectionTester implements ConnectionTesterInterface
{
    private const CLIENT = 'Smile\ElasticsuiteCore\Api\Client\ClientInterface';

    public function __construct(
        private readonly ObjectManagerInterface $objectManager
    ) {
    }

    public function test(string $engine): ?bool
    {
        if ($engine !== 'elasticsuite' || !interface_exists(self::CLIENT)) {
            return null;
        }

        return (bool) $this->objectManager->get(self::CLIENT)->ping();
    }
}
