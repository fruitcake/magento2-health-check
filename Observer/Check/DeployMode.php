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

        $classMap = $this->getComposerClassMap();
        if ($classMap !== null && !isset($classMap[\Magento\Framework\App\Http::class])) {
            return CheckResult::warning(
                'Composer autoloader is not optimized: run composer dump-autoload --optimize',
                ['mode' => $mode, 'autoloader_optimized' => false]
            );
        }

        return CheckResult::ok('', ['mode' => $mode]);
    }

    /**
     * The class map of Composer's autoloader, null when it is not found. An optimized autoloader maps
     * every class, so Magento's own classes are in it.
     *
     * @return array<string, string>|null
     */
    protected function getComposerClassMap(): ?array
    {
        foreach (spl_autoload_functions() ?: [] as $function) {
            if (is_array($function) && $function[0] instanceof \Composer\Autoload\ClassLoader) {
                return $function[0]->getClassMap();
            }
        }

        return null;
    }
}
