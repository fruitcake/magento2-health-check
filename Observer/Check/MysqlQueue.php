<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Observer\Check;

use Fruitcake\HealthCheck\Model\CheckResult;
use Fruitcake\HealthCheck\Model\Config;
use Fruitcake\HealthCheck\Observer\AbstractCheck;
use Magento\Framework\App\ResourceConnection;

/**
 * Messages waiting in Magento's MySQL queues (Magento_MysqlMq), e.g. for async order processing or
 * inventory reservations. A backlog that keeps growing means a consumer is not running.
 */
class MysqlQueue extends AbstractCheck
{
    // Statuses of Magento\MysqlMq\Model\QueueManagement
    private const STATUS_NEW = 2;
    private const STATUS_RETRY = 5;
    private const STATUS_ERROR = 6;

    /** Queues listed in meta */
    private const MAX_QUEUES = 10;

    public function __construct(
        Config $config,
        private readonly ResourceConnection $resource
    ) {
        parent::__construct($config);
    }

    public function getCode(): string
    {
        return 'mysql_queue';
    }

    protected function check(): CheckResult
    {
        $connection = $this->resource->getConnection();
        $statusTable = $this->resource->getTableName('queue_message_status');
        if (!$connection->isTableExists($statusTable)) {
            return CheckResult::ok('', ['configured' => false]);
        }

        $select = $connection->select()
            ->from(['s' => $statusTable], ['status', 'count' => 'COUNT(*)'])
            ->join(['q' => $this->resource->getTableName('queue')], 'q.id = s.queue_id', ['queue' => 'name'])
            ->where('s.status IN (?)', [self::STATUS_NEW, self::STATUS_RETRY, self::STATUS_ERROR])
            ->group(['q.name', 's.status']);

        $totals = [self::STATUS_NEW => 0, self::STATUS_RETRY => 0, self::STATUS_ERROR => 0];
        $waiting = [];
        foreach ($connection->fetchAll($select) as $row) {
            $totals[(int) $row['status']] += (int) $row['count'];
            if ((int) $row['status'] === self::STATUS_NEW) {
                $waiting[$row['queue']] = (int) $row['count'];
            }
        }
        arsort($waiting);
        $meta = $waiting ? ['waiting' => array_slice($waiting, 0, self::MAX_QUEUES, true)] : [];

        $max = (int) $this->config->getThreshold('queue_backlog');
        $result = $max > 0 && $totals[self::STATUS_NEW] >= $max
            ? CheckResult::warning(
                sprintf('%d messages waiting in MySQL queues; check that the consumers run', $totals[self::STATUS_NEW]),
                $meta
            )
            : CheckResult::ok('', $meta);

        return $result
            ->withMeasurement('waiting', $totals[self::STATUS_NEW])
            ->withMeasurement('retry', $totals[self::STATUS_RETRY])
            ->withMeasurement('failed', $totals[self::STATUS_ERROR]);
    }
}
