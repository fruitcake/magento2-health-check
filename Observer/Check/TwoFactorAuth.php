<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Observer\Check;

use Fruitcake\HealthCheck\Model\CheckResult;
use Fruitcake\HealthCheck\Model\Config;
use Fruitcake\HealthCheck\Observer\AbstractCheck;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Module\Manager as ModuleManager;

/**
 * Warns when two-factor authentication for the admin is off: the module is disabled, a common
 * shortcut that sometimes reaches production, or turned off with twofactorauth/general/enable = 0,
 * the setting of modules like MarkShust_DisableTwoFactorAuth.
 */
class TwoFactorAuth extends AbstractCheck
{
    private const MODULE = 'Magento_TwoFactorAuth';
    private const ENABLE_PATH = 'twofactorauth/general/enable';

    public function __construct(
        Config $config,
        private readonly ModuleManager $moduleManager,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
        parent::__construct($config);
    }

    public function getCode(): string
    {
        return 'two_factor_auth';
    }

    protected function check(): CheckResult
    {
        if (!$this->moduleManager->isEnabled(self::MODULE)) {
            return CheckResult::warning(
                'Two-factor authentication for the admin is off: ' . self::MODULE . ' is disabled',
                ['module_enabled' => false]
            );
        }

        $setting = $this->scopeConfig->getValue(self::ENABLE_PATH);
        if ($setting !== null && !(bool) $setting) {
            return CheckResult::warning(
                'Two-factor authentication for the admin is off: ' . self::ENABLE_PATH . ' is 0',
                ['module_enabled' => true, 'setting' => (string) $setting]
            );
        }

        return CheckResult::ok('', ['module_enabled' => true]);
    }
}
