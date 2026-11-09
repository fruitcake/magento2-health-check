<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Observer\Check;

use Fruitcake\HealthCheck\Model\CheckResult;
use Fruitcake\HealthCheck\Model\Config;
use Fruitcake\HealthCheck\Observer\AbstractCheck;
use Magento\Framework\Amqp\ConfigFactory as AmqpConfigFactory;
use Magento\Framework\App\DeploymentConfig;

/**
 * Connects to RabbitMQ with the queue/amqp settings from env.php, through Magento's own AMQP config,
 * so SSL options work the same as for the consumers. Passes without a connection attempt when no
 * RabbitMQ is configured: Magento then uses MySQL queues.
 */
class Rabbitmq extends AbstractCheck
{
    public function __construct(
        Config $config,
        private readonly DeploymentConfig $deploymentConfig,
        private readonly AmqpConfigFactory $amqpConfigFactory
    ) {
        parent::__construct($config);
    }

    protected function measuresResponseTime(): bool
    {
        return true;
    }

    public function getCode(): string
    {
        return 'rabbitmq';
    }

    protected function check(): CheckResult
    {
        $host = (string) $this->deploymentConfig->get('queue/amqp/host');
        if ($host === '') {
            return CheckResult::ok('', ['configured' => false]);
        }

        $meta = [
            'host' => $host,
            'port' => (string) $this->deploymentConfig->get('queue/amqp/port'),
            'virtualhost' => (string) ($this->deploymentConfig->get('queue/amqp/virtualhost') ?: '/'),
        ];

        // A new config instead of the shared ConfigPool one, so its connection closes when it goes out of scope
        $amqpConfig = $this->amqpConfigFactory->create(['connectionName' => 'amqp']);
        $connection = $amqpConfig->getChannel()->getConnection();

        return $connection && $connection->isConnected()
            ? CheckResult::ok('', $meta)
            : CheckResult::failed('Not connected to RabbitMQ', $meta);
    }
}
