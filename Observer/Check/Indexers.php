<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Observer\Check;

use Fruitcake\HealthCheck\Model\CheckResult;
use Fruitcake\HealthCheck\Model\Config;
use Fruitcake\HealthCheck\Observer\AbstractCheck;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Indexer\Model\Indexer\CollectionFactory;

class Indexers extends AbstractCheck
{
    public function __construct(
        Config $config,
        private readonly CollectionFactory $indexerCollectionFactory
    ) {
        parent::__construct($config);
    }

    public function getCode(): string
    {
        return 'indexers';
    }

    protected function check(): CheckResult
    {
        $maxBacklog = (int) $this->config->getThreshold('indexer_backlog');
        $invalid = [];
        $backlog = [];

        /** @var IndexerInterface $indexer */
        foreach ($this->indexerCollectionFactory->create()->getItems() as $indexer) {
            if ($indexer->isInvalid()) {
                $invalid[] = $indexer->getId();
            }
            if ($indexer->isScheduled()) {
                $pending = $this->getPendingCount($indexer);
                if ($pending > $maxBacklog) {
                    $backlog[$indexer->getId()] = $pending;
                }
            }
        }

        $meta = array_filter(['invalid' => $invalid, 'backlog' => $backlog]);
        if ($invalid) {
            return CheckResult::warning('Invalid indexers: ' . implode(', ', $invalid), $meta);
        }
        if ($backlog) {
            return CheckResult::warning('Indexer backlog too large: ' . implode(', ', array_keys($backlog)), $meta);
        }

        return CheckResult::ok();
    }

    /**
     * Version difference between the changelog and the indexer state. This is the number of
     * changelog rows, not unique entities, which keeps it cheap on large backlogs.
     */
    private function getPendingCount(IndexerInterface $indexer): int
    {
        $view = $indexer->getView();
        try {
            return max(0, (int) $view->getChangelog()->getVersion() - (int) $view->getState()->getVersionId());
        } catch (\Exception $e) {
            return 0;
        }
    }
}
