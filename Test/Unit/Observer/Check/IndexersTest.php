<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Test\Unit\Observer\Check;

use Fruitcake\HealthCheck\Model\CheckResult;
use Fruitcake\HealthCheck\Model\HealthReport;
use Fruitcake\HealthCheck\Observer\Check\Indexers;
use Fruitcake\HealthCheck\Test\Unit\ConfigStub;
use Magento\Framework\Event\Observer;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Mview\View\ChangelogInterface;
use Magento\Framework\Mview\View\StateInterface;
use Magento\Framework\Mview\ViewInterface;
use Magento\Indexer\Model\Indexer\Collection;
use Magento\Indexer\Model\Indexer\CollectionFactory;
use PHPUnit\Framework\TestCase;

class IndexersTest extends TestCase
{
    use ConfigStub;

    public function testValidIndexersWithSmallBacklogAreOk(): void
    {
        $result = $this->check([
            $this->indexer('catalog_product_price', scheduled: true, changelogVersion: 1500, stateVersion: 1000),
            $this->indexer('customer_grid'),
        ]);

        $this->assertSame(CheckResult::OK, $result->status);
    }

    public function testInvalidIndexerWarns(): void
    {
        $result = $this->check([
            $this->indexer('catalogsearch_fulltext', invalid: true),
            $this->indexer('customer_grid'),
        ]);

        $this->assertSame(CheckResult::WARNING, $result->status);
        $this->assertSame(['catalogsearch_fulltext'], $result->meta['invalid']);
    }

    public function testBacklogOverThresholdWarns(): void
    {
        $result = $this->check([
            $this->indexer('catalog_product_price', scheduled: true, changelogVersion: 5000, stateVersion: 1000),
        ]);

        $this->assertSame(CheckResult::WARNING, $result->status);
        $this->assertSame(['catalog_product_price' => 4000], $result->meta['backlog']);
    }

    public function testBacklogIgnoredWhenNotScheduled(): void
    {
        $result = $this->check([
            $this->indexer('catalog_product_price', scheduled: false, changelogVersion: 5000, stateVersion: 1000),
        ]);

        $this->assertSame(CheckResult::OK, $result->status);
    }

    /**
     * @param IndexerInterface[] $indexers
     */
    private function check(array $indexers): CheckResult
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('getItems')->willReturn($indexers);
        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $config = $this->createConfig([
            'fruitcake_healthcheck/checks/indexers' => '1',
            'fruitcake_healthcheck/thresholds/indexer_backlog' => '1000',
        ]);
        $report = new HealthReport();
        (new Indexers($config, $collectionFactory))->execute(new Observer(['report' => $report]));

        return $report->getResults()['indexers'];
    }

    private function indexer(
        string $id,
        bool $invalid = false,
        bool $scheduled = false,
        int $changelogVersion = 0,
        int $stateVersion = 0
    ): IndexerInterface {
        $changelog = $this->createMock(ChangelogInterface::class);
        $changelog->method('getVersion')->willReturn($changelogVersion);
        $state = $this->createMock(StateInterface::class);
        $state->method('getVersionId')->willReturn($stateVersion);
        $view = $this->createMock(ViewInterface::class);
        $view->method('getChangelog')->willReturn($changelog);
        $view->method('getState')->willReturn($state);

        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->method('getId')->willReturn($id);
        $indexer->method('isInvalid')->willReturn($invalid);
        $indexer->method('isScheduled')->willReturn($scheduled);
        $indexer->method('getView')->willReturn($view);

        return $indexer;
    }
}
