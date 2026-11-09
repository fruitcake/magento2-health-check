<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Observer\Check;

use Fruitcake\HealthCheck\Model\CheckResult;
use Fruitcake\HealthCheck\Model\Config;
use Fruitcake\HealthCheck\Observer\AbstractCheck;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class Debug extends AbstractCheck
{
    private const DEFAULT_PATHS = [
        'dev/debug/debug_logging',
        'dev/debug/template_hints_admin',
    ];

    private const STORE_PATHS = [
        'dev/debug/template_hints_storefront',
        'dev/translate_inline/active',
    ];

    public function __construct(
        Config $config,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly DeploymentConfig $deploymentConfig
    ) {
        parent::__construct($config);
    }

    public function getCode(): string
    {
        return 'debug';
    }

    protected function check(): CheckResult
    {
        $enabled = [];
        foreach (self::DEFAULT_PATHS as $path) {
            if ($this->scopeConfig->isSetFlag($path)) {
                $enabled[] = $path;
            }
        }
        foreach ($this->storeManager->getStores() as $store) {
            foreach (self::STORE_PATHS as $path) {
                if ($this->scopeConfig->isSetFlag($path, ScopeInterface::SCOPE_STORE, $store->getId())) {
                    $enabled[] = $path . ' (' . $store->getCode() . ')';
                }
            }
        }

        // env.php: a database profiler records every query, on-demand static content deploys during requests
        foreach ((array) $this->deploymentConfig->get('db/connection', []) as $name => $connection) {
            $profiler = $connection['profiler'] ?? false;
            if (is_array($profiler) ? !empty($profiler['enabled']) : (bool) $profiler) {
                $enabled[] = 'database profiler (' . $name . ')';
            }
        }
        if ($this->deploymentConfig->get('static_content_on_demand_in_production')) {
            $enabled[] = 'static_content_on_demand_in_production';
        }

        if ($enabled) {
            return CheckResult::warning('Debug settings enabled: ' . implode(', ', $enabled), ['enabled' => $enabled]);
        }

        return CheckResult::ok();
    }
}
