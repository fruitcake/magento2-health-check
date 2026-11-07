<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Observer\Check;

use Fruitcake\HealthCheck\Model\CheckResult;
use Fruitcake\HealthCheck\Model\Config;
use Fruitcake\HealthCheck\Observer\AbstractCheck;
use Magento\Framework\App\State;

class DeployMode extends AbstractCheck
{
    public function __construct(
        Config $config,
        private readonly State $appState
    ) {
        parent::__construct($config);
    }

    public function getCode(): string
    {
        return 'deploy_mode';
    }

    protected function check(): CheckResult
    {
        $mode = $this->appState->getMode();
        if ($mode !== State::MODE_PRODUCTION) {
            return CheckResult::warning(sprintf('Running in %s mode', $mode), ['mode' => $mode]);
        }

        return CheckResult::ok('', ['mode' => $mode]);
    }
}
