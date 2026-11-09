<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Test\Unit\Observer\Check;

use Fruitcake\HealthCheck\Model\CheckResult;
use Fruitcake\HealthCheck\Model\HealthReport;
use Fruitcake\HealthCheck\Observer\Check\DeployMode;
use Fruitcake\HealthCheck\Test\Unit\ConfigStub;
use Magento\Framework\App\State;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;

class DeployModeTest extends TestCase
{
    use ConfigStub;

    public function testProductionWithOptimizedAutoloaderPasses(): void
    {
        $result = $this->check(State::MODE_PRODUCTION, [\Magento\Framework\App\Http::class => '/vendor/x.php']);

        $this->assertSame(CheckResult::OK, $result->status);
    }

    public function testUnoptimizedAutoloaderWarns(): void
    {
        $result = $this->check(State::MODE_PRODUCTION, ['Composer\\InstalledVersions' => '/vendor/x.php']);

        $this->assertSame(CheckResult::WARNING, $result->status);
        $this->assertStringContainsString('composer dump-autoload --optimize', $result->message);
    }

    public function testDeveloperModeWarnsWithoutLookingAtAutoloader(): void
    {
        $result = $this->check(State::MODE_DEVELOPER, []);

        $this->assertSame('Running in developer mode', $result->message);
    }

    public function testNoComposerAutoloaderFoundPasses(): void
    {
        $this->assertSame(CheckResult::OK, $this->check(State::MODE_PRODUCTION, null)->status);
    }

    private function check(string $mode, ?array $classMap): CheckResult
    {
        $appState = $this->createMock(State::class);
        $appState->method('getMode')->willReturn($mode);
        $config = $this->createConfig(['fruitcake_healthcheck/checks/deploy_mode' => '1']);

        $check = new class ($config, $appState, $classMap) extends DeployMode {
            public function __construct($config, $appState, private readonly ?array $classMap)
            {
                parent::__construct($config, $appState);
            }

            protected function getComposerClassMap(): ?array
            {
                return $this->classMap;
            }
        };

        $report = new HealthReport();
        $check->execute(new Observer(['report' => $report]));

        return $report->getResults()['deploy_mode'];
    }
}
