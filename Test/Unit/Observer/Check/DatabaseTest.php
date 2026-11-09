<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Test\Unit\Observer\Check;

use Fruitcake\HealthCheck\Model\CheckResult;
use Fruitcake\HealthCheck\Model\HealthReport;
use Fruitcake\HealthCheck\Observer\Check\Database;
use Fruitcake\HealthCheck\Test\Unit\ConfigStub;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;

class DatabaseTest extends TestCase
{
    use ConfigStub;

    public function testMeasuresConnections(): void
    {
        $result = $this->check(42, 150);

        $this->assertSame(CheckResult::OK, $result->status);
        $this->assertSame(['value' => 42, 'unit' => null], $result->measurements['connections']);
        $this->assertSame(['value' => 28.0, 'unit' => 'percent'], $result->measurements['connectionsUsed']);
        $this->assertSame('ms', $result->measurements['responseTime']['unit']);
    }

    public function testNearlyAllConnectionsInUseWarns(): void
    {
        $result = $this->check(140, 150);

        $this->assertSame(CheckResult::WARNING, $result->status);
        $this->assertSame('140 of 150 database connections in use (93.3%)', $result->message);
    }

    public function testNoConnectionFails(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('fetchOne')->willThrowException(new \RuntimeException('Connection refused'));

        $result = $this->runCheck($connection);

        $this->assertSame(CheckResult::FAILED, $result->status);
    }

    private function check(int $connected, int $max): CheckResult
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('fetchOne')->willReturn('1');
        $connection->method('fetchRow')->willReturnCallback(static fn (string $sql) => str_contains($sql, 'Threads_connected')
            ? ['Variable_name' => 'Threads_connected', 'Value' => (string) $connected]
            : ['Variable_name' => 'max_connections', 'Value' => (string) $max]);

        return $this->runCheck($connection);
    }

    private function runCheck(AdapterInterface $connection): CheckResult
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);

        $report = new HealthReport();
        (new Database($this->createConfig(['fruitcake_healthcheck/checks/database' => '1']), $resource))
            ->execute(new Observer(['report' => $report]));

        return $report->getResults()['database'];
    }
}
