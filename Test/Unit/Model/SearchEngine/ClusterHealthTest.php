<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Test\Unit\Model\SearchEngine;

use Fruitcake\HealthCheck\Model\SearchEngine\ClusterHealth;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

class ClusterHealthTest extends TestCase
{
    public function testCoreEngineUsesCatalogSearchSettings(): void
    {
        $health = $this->clusterHealth([
            'catalog/search/opensearch_server_hostname' => 'search.internal',
            'catalog/search/opensearch_server_port' => '9200',
            'catalog/search/opensearch_enable_auth' => '1',
            'catalog/search/opensearch_username' => 'magento',
            'catalog/search/opensearch_password' => 'secret',
        ]);

        $this->assertSame(['status' => 'green'], $health->get('opensearch'));
        $this->assertSame([['http://search.internal:9200/_cluster/health', 'magento', 'secret']], $health->requests);
    }

    public function testElasticsuiteTriesEveryServer(): void
    {
        $health = $this->clusterHealth([
            'smile_elasticsuite_core_base_settings/es_client/servers' => 'es1:9200, es2:9200',
            'smile_elasticsuite_core_base_settings/es_client/enable_https_mode' => '1',
        ], failFirst: true);

        $this->assertSame(['status' => 'green'], $health->get('elasticsuite'));
        $this->assertSame('https://es2:9200/_cluster/health', $health->requests[1][0]);
    }

    public function testUnknownEngineGivesNull(): void
    {
        $this->assertNull($this->clusterHealth([])->get('mysql'));
    }

    private function clusterHealth(array $config, bool $failFirst = false): ClusterHealth
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(static fn (string $path) => $config[$path] ?? null);
        $scopeConfig->method('isSetFlag')->willReturnCallback(static fn (string $path) => (bool) ($config[$path] ?? false));

        return new class ($scopeConfig, $failFirst) extends ClusterHealth {
            public array $requests = [];

            public function __construct(ScopeConfigInterface $scopeConfig, private readonly bool $failFirst)
            {
                parent::__construct($scopeConfig);
            }

            protected function request(string $url, ?string $user, ?string $password): ?array
            {
                $this->requests[] = [$url, $user, $password];

                return $this->failFirst && count($this->requests) === 1 ? null : ['status' => 'green'];
            }
        };
    }
}
