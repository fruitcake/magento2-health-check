<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Test\Unit\Observer\Check;

use Fruitcake\HealthCheck\Model\CheckResult;
use Fruitcake\HealthCheck\Model\HealthReport;
use Fruitcake\HealthCheck\Observer\Check\DiskSpace;
use Fruitcake\HealthCheck\Test\Unit\ConfigStub;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Reads the real free space of the temp dir, so the thresholds sit at 0 and 100 to force each outcome.
 */
class DiskSpaceTest extends TestCase
{
    use ConfigStub;

    public static function thresholds(): array
    {
        return [
            'above both' => [0, 0, CheckResult::OK],
            'below warning' => [100, 0, CheckResult::WARNING],
            'below failed' => [100, 100, CheckResult::FAILED],
        ];
    }

    #[DataProvider('thresholds')]
    public function testThresholds(int $warning, int $failed, string $expected): void
    {
        $directoryList = $this->createMock(DirectoryList::class);
        $directoryList->method('getPath')->with(DirectoryList::VAR_DIR)->willReturn(sys_get_temp_dir());

        $config = $this->createConfig([
            'fruitcake_healthcheck/checks/disk_space' => '1',
            'fruitcake_healthcheck/thresholds/disk_free_warning' => (string) $warning,
            'fruitcake_healthcheck/thresholds/disk_free_failed' => (string) $failed,
        ]);
        $report = new HealthReport();
        (new DiskSpace($config, $directoryList))->execute(new Observer(['report' => $report]));

        $this->assertSame($expected, $report->getResults()['disk_space']->status);
    }
}
