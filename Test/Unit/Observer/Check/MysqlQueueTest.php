<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Test\Unit\Observer\Check;

use Fruitcake\HealthCheck\Model\CheckResult;
use Fruitcake\HealthCheck\Model\HealthReport;
use Fruitcake\HealthCheck\Observer\Check\MysqlQueue;
use Fruitcake\HealthCheck\Test\Unit\ConfigStub;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;

class MysqlQueueTest extends TestCase
{
    use ConfigStub;

    public function testMeasuresWaitingRetryAndFailedMessages(): void
    {
        $result = $this->check([
            ['queue' => 'inventory.reservations.update', 'status' => '2', 'count' => '12'],
            ['queue' => 'async.operations.all', 'status' => '2', 'count' => '30'],
            ['queue' => 'async.operations.all', 'status' => '5', 'count' => '2'],
            ['queue' => 'async.operations.all', 'status' => '6', 'count' => '1'],
        ]);

        $this->assertSame(CheckResult::OK, $result->status);
        $this->assertSame(42, $result->measurements['waiting']['value']);
        $this->assertSame(2, $result->measurements['retry']['value']);
        $this->assertSame(1, $result->measurements['failed']['value']);
        $this->assertSame(['async.operations.all' => 30, 'inventory.reservations.update' => 12], $result->meta['waiting']);
    }

    public function testBacklogOverThresholdWarns(): void
    {
        $result = $this->check([['queue' => 'async.operations.all', 'status' => '2', 'count' => '1500']]);

        $this->assertSame(CheckResult::WARNING, $result->status);
        $this->assertStringContainsString('1500 messages waiting', $result->message);
    }

    public function testWithoutMysqlQueuePasses(): void
    {
        $result = $this->check([], tableExists: false);

        $this->assertSame(CheckResult::OK, $result->status);
        $this->assertSame(['configured' => false], $result->meta);
    }

    private function check(array $rows, bool $tableExists = true): CheckResult
    {
        $select = $this->createMock(Select::class);
        foreach (['from', 'join', 'where', 'group'] as $method) {
            $select->method($method)->willReturnSelf();
        }
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('isTableExists')->willReturn($tableExists);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn($rows);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $report = new HealthReport();
        (new MysqlQueue($this->createConfig([
            'fruitcake_healthcheck/checks/mysql_queue' => '1',
            'fruitcake_healthcheck/thresholds/queue_backlog' => '1000',
        ]), $resource))->execute(new Observer(['report' => $report]));

        return $report->getResults()['mysql_queue'];
    }
}
