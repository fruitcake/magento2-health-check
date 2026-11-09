<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Test\Unit\Observer\Check;

use Fruitcake\HealthCheck\Model\CheckResult;
use Fruitcake\HealthCheck\Model\HealthReport;
use Fruitcake\HealthCheck\Observer\Check\Cron;
use Fruitcake\HealthCheck\Test\Unit\ConfigStub;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;

class CronTest extends TestCase
{
    use ConfigStub;

    private string $timezone;

    protected function setUp(): void
    {
        // cron_schedule stores UTC; run in a non-UTC timezone so reading it as local time shows
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Amsterdam');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
    }

    public function testRecentJobIsOk(): void
    {
        $result = $this->check(gmdate('Y-m-d H:i:s', time() - 5 * 60));

        $this->assertSame(CheckResult::OK, $result->status);
        $this->assertSame(5, $result->measurements['lastFinished']['value']);
    }

    public function testJobOlderThanThresholdWarns(): void
    {
        $result = $this->check(gmdate('Y-m-d H:i:s', time() - 20 * 60));

        $this->assertSame(CheckResult::WARNING, $result->status);
        $this->assertSame(20, $result->measurements['lastFinished']['value']);
    }

    public function testNoFinishedJobWarns(): void
    {
        $this->assertSame(CheckResult::WARNING, $this->check(null)->status);
    }

    private function check(?string $lastFinishedAt): CheckResult
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->with($select)->willReturn($lastFinishedAt ?? false);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $config = $this->createConfig([
            'fruitcake_healthcheck/checks/cron' => '1',
            'fruitcake_healthcheck/thresholds/cron_max_minutes' => '15',
        ]);
        $report = new HealthReport();
        (new Cron($config, $resource))->execute(new Observer(['report' => $report]));

        return $report->getResults()['cron'];
    }
}
