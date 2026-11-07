<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Test\Unit\Observer;

use Fruitcake\HealthCheck\Model\CheckResult;
use Fruitcake\HealthCheck\Model\Config;
use Fruitcake\HealthCheck\Model\HealthReport;
use Fruitcake\HealthCheck\Observer\AbstractCheck;
use Fruitcake\HealthCheck\Test\Unit\ConfigStub;
use Magento\Framework\App\State;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AbstractCheckTest extends TestCase
{
    use ConfigStub;

    public static function disabledModes(): array
    {
        return ['off' => ['0'], 'not configured' => [null]];
    }

    #[DataProvider('disabledModes')]
    public function testDisabledCheckDoesNotRun(?string $mode): void
    {
        $report = $this->runCheck(
            fn () => $this->fail('A disabled check must not run'),
            $mode
        );

        $this->assertSame([], $report->getResults());
    }

    public function testExceptionBecomesFailedResult(): void
    {
        $report = $this->runCheck(fn () => throw new \RuntimeException('connection refused'), '1');

        $result = $report->getResults()['test'];
        $this->assertSame(CheckResult::FAILED, $result->status);
        $this->assertSame('connection refused', $result->message);
    }

    public static function warningModes(): array
    {
        return [
            'enabled' => ['1', State::MODE_PRODUCTION, CheckResult::WARNING],
            'strict' => ['strict', State::MODE_DEVELOPER, CheckResult::FAILED],
            'strict in production, running production' => ['strict_production', State::MODE_PRODUCTION, CheckResult::FAILED],
            'strict in production, running developer' => ['strict_production', State::MODE_DEVELOPER, CheckResult::WARNING],
        ];
    }

    #[DataProvider('warningModes')]
    public function testStrictModeTurnsWarningIntoFailure(string $mode, string $appMode, string $expected): void
    {
        $report = $this->runCheck(fn () => CheckResult::warning('late'), $mode, $appMode);

        $this->assertSame($expected, $report->getResults()['test']->status);
        $this->assertSame('late', $report->getResults()['test']->message);
    }

    private function runCheck(callable $check, ?string $mode, string $appMode = State::MODE_PRODUCTION): HealthReport
    {
        $config = $this->createConfig(['fruitcake_healthcheck/checks/test' => $mode], $appMode);
        $observer = new class($config, $check) extends AbstractCheck {
            /** @var callable */
            private $callback;

            public function __construct(Config $config, callable $callback)
            {
                parent::__construct($config);
                $this->callback = $callback;
            }

            public function getCode(): string
            {
                return 'test';
            }

            protected function check(): CheckResult
            {
                return ($this->callback)();
            }
        };

        $report = new HealthReport();
        $observer->execute(new Observer(['report' => $report]));

        return $report;
    }
}
