<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Test\Unit\Observer\Check;

use Fruitcake\HealthCheck\Model\CheckResult;
use Fruitcake\HealthCheck\Model\HealthReport;
use Fruitcake\HealthCheck\Observer\Check\SessionStorage;
use Fruitcake\HealthCheck\Test\Unit\ConfigStub;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Event\Observer;
use Magento\Framework\Session\Config\ConfigInterface as SessionConfig;
use Magento\Framework\Session\SaveHandlerFactory;
use PHPUnit\Framework\TestCase;

class SessionStorageTest extends TestCase
{
    use ConfigStub;

    public function testRedisThatStoresSessionsPasses(): void
    {
        $result = $this->check(['session/save' => 'redis'], $this->storage());

        $this->assertSame(CheckResult::OK, $result->status);
        $this->assertSame(['handler' => 'redis'], $result->meta);
    }

    public function testRedisThatDoesNotStoreFails(): void
    {
        $result = $this->check(['session/save' => 'redis'], $this->storage(works: false));

        $this->assertSame(CheckResult::FAILED, $result->status);
        $this->assertSame('Session storage "redis" cannot write and read back a session', $result->message);
    }

    public function testRedisConnectionErrorFails(): void
    {
        $storage = $this->createMock(\SessionHandlerInterface::class);
        $storage->method('open')->willThrowException(new \RuntimeException('Connection to Redis failed'));

        $result = $this->check(['session/save' => 'redis'], $storage);

        $this->assertSame(CheckResult::FAILED, $result->status);
        $this->assertSame('Connection to Redis failed', $result->message);
    }

    public function testWritableFilesDirectoryPasses(): void
    {
        $dir = sys_get_temp_dir() . '/healthcheck_' . bin2hex(random_bytes(4));
        mkdir($dir);

        $result = $this->check(['session/save' => 'files'], savePath: $dir);

        $this->assertSame(CheckResult::OK, $result->status);
        $this->assertSame([], glob($dir . '/*'));
        rmdir($dir);
    }

    public function testFilesWithDepthNeedsSubdirectories(): void
    {
        $dir = sys_get_temp_dir() . '/healthcheck_' . bin2hex(random_bytes(4));
        mkdir($dir);

        $withoutSubdirectories = $this->check(['session/save' => 'files'], savePath: '1;' . $dir);
        foreach (str_split('0123456789abcdef') as $char) {
            mkdir($dir . '/' . $char);
        }
        $withSubdirectories = $this->check(['session/save' => 'files'], savePath: '1;' . $dir);

        $this->assertSame(CheckResult::FAILED, $withoutSubdirectories->status);
        $this->assertSame(CheckResult::OK, $withSubdirectories->status);
        $this->assertSame([], glob($dir . '/*/*'));
        array_map('rmdir', glob($dir . '/*'));
        rmdir($dir);
    }

    public function testMissingFilesDirectoryFails(): void
    {
        $result = $this->check(['session/save' => 'files'], savePath: '/does/not/exist');

        $this->assertSame(CheckResult::FAILED, $result->status);
    }

    public function testHandlerMagentoDoesNotKnowUsesPhpFiles(): void
    {
        $dir = sys_get_temp_dir() . '/healthcheck_' . bin2hex(random_bytes(4));
        mkdir($dir);

        $result = $this->check(['session/save' => 'database'], savePath: $dir);
        rmdir($dir);

        $this->assertSame(CheckResult::OK, $result->status);
        $this->assertSame(['handler' => 'files', 'configured' => 'database'], $result->meta);
    }

    public function testUnknownHandlerWarns(): void
    {
        $result = $this->check(['session/save' => 'memcached']);

        $this->assertSame(CheckResult::WARNING, $result->status);
    }

    private function storage(bool $works = true): \SessionHandlerInterface
    {
        $stored = [];
        $storage = $this->createMock(\SessionHandlerInterface::class);
        $storage->method('open')->willReturn(true);
        $storage->method('write')->willReturnCallback(
            static function ($id, $value) use (&$stored, $works) {
                if ($works) {
                    $stored[$id] = $value;
                }
                return $works;
            }
        );
        $storage->method('read')->willReturnCallback(
            static function ($id) use (&$stored) {
                return $stored[$id] ?? '';
            }
        );
        $storage->expects($this->once())->method('destroy');

        return $storage;
    }

    /**
     * @param array<string, string> $env env.php values
     */
    private function check(array $env, ?\SessionHandlerInterface $storage = null, string $savePath = ''): CheckResult
    {
        $sessionConfig = $this->createMock(SessionConfig::class);
        $sessionConfig->method('getSavePath')->willReturn($savePath);

        $deploymentConfig = $this->createMock(DeploymentConfig::class);
        $deploymentConfig->method('get')->willReturnCallback(static fn (string $path) => $env[$path] ?? null);

        $factory = $this->createMock(SaveHandlerFactory::class);
        $factory->method('create')->willReturn($storage ?? $this->createMock(\SessionHandlerInterface::class));

        $report = new HealthReport();
        $check = new SessionStorage(
            $this->createConfig(['fruitcake_healthcheck/checks/session_storage' => '1']),
            $deploymentConfig,
            $factory,
            $sessionConfig
        );
        $check->execute(new Observer(['report' => $report]));

        return $report->getResults()['session_storage'];
    }
}
