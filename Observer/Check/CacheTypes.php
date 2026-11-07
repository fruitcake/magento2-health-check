<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Observer\Check;

use Fruitcake\HealthCheck\Model\CheckResult;
use Fruitcake\HealthCheck\Model\Config;
use Fruitcake\HealthCheck\Observer\AbstractCheck;
use Magento\Framework\App\Cache\TypeListInterface;

class CacheTypes extends AbstractCheck
{
    public function __construct(
        Config $config,
        private readonly TypeListInterface $typeList
    ) {
        parent::__construct($config);
    }

    public function getCode(): string
    {
        return 'cache_types';
    }

    protected function check(): CheckResult
    {
        $disabled = [];
        foreach ($this->typeList->getTypes() as $code => $type) {
            if (!$type->getData('status')) {
                $disabled[] = $code;
            }
        }
        $invalidated = array_keys($this->typeList->getInvalidated());

        $meta = array_filter(['disabled' => $disabled, 'invalidated' => $invalidated]);
        if ($disabled) {
            return CheckResult::warning('Disabled cache types: ' . implode(', ', $disabled), $meta);
        }
        if ($invalidated) {
            return CheckResult::warning('Invalidated cache types: ' . implode(', ', $invalidated), $meta);
        }

        return CheckResult::ok();
    }
}
