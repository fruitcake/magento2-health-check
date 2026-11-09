<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Observer\Check;

use Fruitcake\HealthCheck\Model\CheckResult;
use Fruitcake\HealthCheck\Model\Config;
use Fruitcake\HealthCheck\Observer\AbstractCheck;
use Magento\Framework\App\Filesystem\DirectoryList;

class DiskSpace extends AbstractCheck
{
    public function __construct(
        Config $config,
        private readonly DirectoryList $directoryList
    ) {
        parent::__construct($config);
    }

    public function getCode(): string
    {
        return 'disk_space';
    }

    protected function check(): CheckResult
    {
        $path = $this->directoryList->getPath(DirectoryList::VAR_DIR);
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);
        if ($free === false || !$total) {
            return CheckResult::warning('Could not read disk space');
        }

        $percent = round($free / $total * 100, 1);
        $message = sprintf('%s%% disk space free', $percent);

        if ($percent < $this->config->getThreshold('disk_free_failed')) {
            $result = CheckResult::failed($message);
        } elseif ($percent < $this->config->getThreshold('disk_free_warning')) {
            $result = CheckResult::warning($message);
        } else {
            $result = CheckResult::ok($message);
        }

        return $result
            ->withMeasurement('free', $percent, 'percent')
            ->withMeasurement('freeSpace', (int) ($free / 1024 / 1024), 'MiB');
    }
}
