<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Test\Unit\Observer\Check;

use Fruitcake\HealthCheck\Model\CheckResult;
use Fruitcake\HealthCheck\Model\HealthReport;
use Fruitcake\HealthCheck\Observer\Check\Debug;
use Fruitcake\HealthCheck\Test\Unit\ConfigStub;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\Observer;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class DebugTest extends TestCase
{
    use ConfigStub;

    public function testNoDebugSettingsIsOk(): void
    {
        $this->assertSame(CheckResult::OK, $this->check([])->status);
    }

    public function testTemplateHintsOnOneStoreViewWarns(): void
    {
        $result = $this->check([
            'store' => [2 => ['dev/debug/template_hints_storefront' => '1']],
        ]);

        $this->assertSame(CheckResult::WARNING, $result->status);
        $this->assertSame(['dev/debug/template_hints_storefront (nl)'], $result->meta['enabled']);
    }

    public function testDebugLoggingWarns(): void
    {
        $result = $this->check(['default' => ['dev/debug/debug_logging' => '1']]);

        $this->assertSame(['dev/debug/debug_logging'], $result->meta['enabled']);
    }

    /**
     * @param array{default?: array<string, string>, store?: array<int, array<string, string>>} $values
     */
    private function check(array $values): CheckResult
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static function (string $path, string $scope = ScopeConfigInterface::SCOPE_TYPE_DEFAULT, $code = null) use ($values) {
                if ($scope === ScopeInterface::SCOPE_STORE) {
                    return (bool) ($values['store'][$code][$path] ?? false);
                }
                return (bool) ($values['default'][$path] ?? false);
            }
        );

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([
            $this->store(1, 'default'),
            $this->store(2, 'nl'),
        ]);

        $config = $this->createConfig(['fruitcake_healthcheck/checks/debug' => '1']);
        $report = new HealthReport();
        (new Debug($config, $scopeConfig, $storeManager))->execute(new Observer(['report' => $report]));

        return $report->getResults()['debug'];
    }

    private function store(int $id, string $code): StoreInterface
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn($id);
        $store->method('getCode')->willReturn($code);

        return $store;
    }
}
