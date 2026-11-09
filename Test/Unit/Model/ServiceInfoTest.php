<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Test\Unit\Model;

use Fruitcake\HealthCheck\Model\ServiceInfo;
use Magento\Framework\App\View\Deployment\Version\StorageInterface;
use PHPUnit\Framework\TestCase;

class ServiceInfoTest extends TestCase
{
    public function testServiceIdIsHostname(): void
    {
        $this->assertSame(gethostname(), $this->serviceInfo("1731151234\n")->getServiceId());
    }

    public function testReleaseIdIsDeployedVersion(): void
    {
        $this->assertSame('1731151234', $this->serviceInfo("1731151234\n")->getReleaseId());
    }

    public function testReleaseIdIsEmptyWithoutDeployedVersion(): void
    {
        $this->assertSame('', $this->serviceInfo(false)->getReleaseId());
    }

    private function serviceInfo(string|false $deployedVersion): ServiceInfo
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->method('load')->willReturn($deployedVersion);

        return new ServiceInfo($storage);
    }
}
