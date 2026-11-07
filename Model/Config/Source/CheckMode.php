<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class CheckMode implements OptionSourceInterface
{
    public const OFF = '0';
    public const ON = '1';
    public const STRICT = 'strict';
    public const STRICT_PRODUCTION = 'strict_production';

    public function toOptionArray(): array
    {
        return [
            ['value' => self::OFF, 'label' => __('No')],
            ['value' => self::ON, 'label' => __('Yes')],
            ['value' => self::STRICT, 'label' => __('Yes, warnings fail')],
            ['value' => self::STRICT_PRODUCTION, 'label' => __('Yes, warnings fail in production mode')],
        ];
    }
}
