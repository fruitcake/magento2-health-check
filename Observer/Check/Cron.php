<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Observer\Check;

use Fruitcake\HealthCheck\Model\CheckResult;
use Fruitcake\HealthCheck\Model\Config;
use Fruitcake\HealthCheck\Observer\AbstractCheck;
use Magento\Framework\App\ResourceConnection;

class Cron extends AbstractCheck
{
    public function __construct(
        Config $config,
        private readonly ResourceConnection $resource
    ) {
        parent::__construct($config);
    }

    public function getCode(): string
    {
        return 'cron';
    }

    protected function check(): CheckResult
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('cron_schedule'), ['last' => 'MAX(finished_at)'])
            ->where('status = ?', 'success');
        $last = $connection->fetchOne($select);

        if (!$last) {
            return CheckResult::warning('No cron job has finished yet');
        }

        // cron_schedule stores times in UTC
        $finishedAt = new \DateTimeImmutable($last, new \DateTimeZone('UTC'));
        $minutes = (int) floor((time() - $finishedAt->getTimestamp()) / 60);
        $meta = ['last_finished_at' => $finishedAt->format(DATE_ATOM), 'minutes_ago' => $minutes];

        $max = (int) $this->config->getThreshold('cron_max_minutes');
        if ($minutes > $max) {
            return CheckResult::warning(sprintf('Last cron job finished %d minutes ago', $minutes), $meta);
        }

        return CheckResult::ok('', $meta);
    }
}
