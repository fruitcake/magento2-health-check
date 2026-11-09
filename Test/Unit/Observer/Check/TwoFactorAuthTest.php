<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Test\Unit\Observer\Check;

use Fruitcake\HealthCheck\Model\CheckResult;
use Fruitcake\HealthCheck\Model\HealthReport;
use Fruitcake\HealthCheck\Observer\Check\TwoFactorAuth;
use Fruitcake\HealthCheck\Test\Unit\ConfigStub;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Module\Manager as ModuleManager;
use PHPUnit\Framework\TestCase;

class TwoFactorAuthTest extends TestCase
{
    use ConfigStub;

    public function testEnabledModulePasses(): void
    {
        $this->assertSame(CheckResult::OK, $this->check(true, null)->status);
    }

    public function testDisabledModuleWarns(): void
    {
        $result = $this->check(false, null);

        $this->assertSame(CheckResult::WARNING, $result->status);
        $this->assertStringContainsString('Magento_TwoFactorAuth is disabled', $result->message);
    }

    public function testDisabledSettingWarns(): void
    {
        $result = $this->check(true, '0');

        $this->assertSame(CheckResult::WARNING, $result->status);
        $this->assertStringContainsString('twofactorauth/general/enable is 0', $result->message);
    }

    public function testEnabledSettingPasses(): void
    {
        $this->assertSame(CheckResult::OK, $this->check(true, '1')->status);
    }

    private function check(bool $moduleEnabled, ?string $setting): CheckResult
    {
        $moduleManager = $this->createMock(ModuleManager::class);
        $moduleManager->method('isEnabled')->with('Magento_TwoFactorAuth')->willReturn($moduleEnabled);
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->with('twofactorauth/general/enable')->willReturn($setting);

        $report = new HealthReport();
        $check = new TwoFactorAuth(
            $this->createConfig(['fruitcake_healthcheck/checks/two_factor_auth' => '1']),
            $moduleManager,
            $scopeConfig
        );
        $check->execute(new Observer(['report' => $report]));

        return $report->getResults()['two_factor_auth'];
    }
}
