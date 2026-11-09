<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Observer\Check;

use Fruitcake\HealthCheck\Model\CheckResult;
use Fruitcake\HealthCheck\Model\Config;
use Fruitcake\HealthCheck\Model\SearchEngine\ClusterHealth;
use Fruitcake\HealthCheck\Model\SearchEngine\ConnectionTesterInterface;
use Fruitcake\HealthCheck\Observer\AbstractCheck;
use Magento\Framework\Search\EngineResolverInterface;

class SearchEngine extends AbstractCheck
{
    /**
     * @param ConnectionTesterInterface[] $testers asked in order; the first that knows the engine decides
     */
    public function __construct(
        Config $config,
        private readonly EngineResolverInterface $engineResolver,
        private readonly ClusterHealth $clusterHealth,
        private readonly array $testers = []
    ) {
        parent::__construct($config);
    }

    protected function measuresResponseTime(): bool
    {
        return true;
    }

    public function getCode(): string
    {
        return 'search_engine';
    }

    protected function check(): CheckResult
    {
        $engine = (string) $this->engineResolver->getCurrentSearchEngine();
        $meta = ['engine' => $engine];

        foreach ($this->testers as $tester) {
            $reachable = $tester->test($engine);
            if ($reachable === null) {
                continue;
            }

            return $reachable
                ? $this->checkClusterHealth($engine, $meta)
                : CheckResult::failed(sprintf('Search engine "%s" is not reachable', $engine), $meta);
        }

        return CheckResult::warning(sprintf('No connection test available for search engine "%s"', $engine), $meta);
    }

    /**
     * Red: some primary shards are missing, so searches miss products. Yellow: replicas are missing, which is
     * normal on a single node. Passes when the health cannot be read: the ping already said the engine is up.
     */
    private function checkClusterHealth(string $engine, array $meta): CheckResult
    {
        $health = $this->clusterHealth->get($engine);
        if ($health === null) {
            return CheckResult::ok('', $meta);
        }

        $status = (string) $health['status'];
        $nodes = (int) ($health['number_of_data_nodes'] ?? 0);
        $meta['cluster_status'] = $status;

        $result = match (true) {
            $status === 'red' => CheckResult::failed('Search cluster health is red: primary shards are missing', $meta),
            $status === 'yellow' && $nodes > 1 => CheckResult::warning('Search cluster health is yellow: replica shards are missing', $meta),
            default => CheckResult::ok('', $meta),
        };

        $result = $result->withMeasurement('dataNodes', $nodes)
            ->withMeasurement('unassignedShards', (int) ($health['unassigned_shards'] ?? 0));
        if (isset($health['active_shards_percent_as_number'])) {
            $result = $result->withMeasurement(
                'activeShards',
                round((float) $health['active_shards_percent_as_number'], 1),
                'percent'
            );
        }

        return $result;
    }
}
