<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Test\Unit\Observer\Check;

use Fruitcake\HealthCheck\Model\CheckResult;
use Fruitcake\HealthCheck\Model\HealthReport;
use Fruitcake\HealthCheck\Observer\Check\Rabbitmq;
use Fruitcake\HealthCheck\Test\Unit\ConfigStub;
use Magento\Framework\Amqp\Config as AmqpConfig;
use Magento\Framework\Amqp\ConfigFactory as AmqpConfigFactory;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Event\Observer;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PHPUnit\Framework\TestCase;

class RabbitmqTest extends TestCase
{
    use ConfigStub;

    private const ENV = [
        'queue/amqp/host' => 'rabbitmq.internal',
        'queue/amqp/port' => '5672',
        'queue/amqp/user' => 'magento',
        'queue/amqp/password' => 'secret',
    ];

    public function testNotConfiguredPassesWithoutConnecting(): void
    {
        $factory = $this->createMock(AmqpConfigFactory::class);
        $factory->expects($this->never())->method('create');

        $result = $this->check([], $factory);

        $this->assertSame(CheckResult::OK, $result->status);
        $this->assertSame(['configured' => false], $result->meta);
    }

    public function testConnectedPassesWithoutPasswordInMeta(): void
    {
        $result = $this->check(self::ENV, $this->factory(connected: true));

        $this->assertSame(CheckResult::OK, $result->status);
        $this->assertSame(['host' => 'rabbitmq.internal', 'port' => '5672', 'virtualhost' => '/'], $result->meta);
    }

    public function testConnectionErrorFails(): void
    {
        $amqpConfig = $this->createMock(AmqpConfig::class);
        $amqpConfig->method('getChannel')->willThrowException(
            new \RuntimeException('Unable to connect to tcp://rabbitmq.internal:5672 (Connection refused)')
        );
        $factory = $this->createMock(AmqpConfigFactory::class);
        $factory->method('create')->willReturn($amqpConfig);

        $result = $this->check(self::ENV, $factory);

        $this->assertSame(CheckResult::FAILED, $result->status);
        $this->assertStringContainsString('Connection refused', $result->message);
    }

    public function testNotConnectedFails(): void
    {
        $this->assertSame(CheckResult::FAILED, $this->check(self::ENV, $this->factory(connected: false))->status);
    }

    private function factory(bool $connected): AmqpConfigFactory
    {
        $connection = $this->createMock(AbstractConnection::class);
        $connection->method('isConnected')->willReturn($connected);
        $channel = $this->createMock(AMQPChannel::class);
        $channel->method('getConnection')->willReturn($connection);
        $amqpConfig = $this->createMock(AmqpConfig::class);
        $amqpConfig->method('getChannel')->willReturn($channel);

        $factory = $this->createMock(AmqpConfigFactory::class);
        $factory->method('create')->with(['connectionName' => 'amqp'])->willReturn($amqpConfig);

        return $factory;
    }

    /**
     * @param array<string, string> $env env.php values
     */
    private function check(array $env, AmqpConfigFactory $factory): CheckResult
    {
        $deploymentConfig = $this->createMock(DeploymentConfig::class);
        $deploymentConfig->method('get')->willReturnCallback(static fn (string $path) => $env[$path] ?? null);

        $report = new HealthReport();
        $check = new Rabbitmq(
            $this->createConfig(['fruitcake_healthcheck/checks/rabbitmq' => '1']),
            $deploymentConfig,
            $factory
        );
        $check->execute(new Observer(['report' => $report]));

        return $report->getResults()['rabbitmq'];
    }
}
