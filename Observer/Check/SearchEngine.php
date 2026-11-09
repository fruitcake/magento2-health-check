<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Observer\Check;

use Fruitcake\HealthCheck\Model\CheckResult;
use Fruitcake\HealthCheck\Model\Config;
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
                ? CheckResult::ok('', $meta)
                : CheckResult::failed(sprintf('Search engine "%s" is not reachable', $engine), $meta);
        }

        return CheckResult::warning(sprintf('No connection test available for search engine "%s"', $engine), $meta);
    }
}
