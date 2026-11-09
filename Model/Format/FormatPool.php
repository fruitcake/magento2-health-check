<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Model\Format;

use Magento\Framework\App\Request\Http as HttpRequest;

/**
 * The formats from di.xml, by name. Picks ?format=<name>, otherwise the first format whose matches() is true,
 * otherwise the default format.
 */
class FormatPool
{
    /**
     * @param FormatInterface[] $formats by name, in the order matches() is asked
     */
    public function __construct(
        private readonly array $formats,
        private readonly string $defaultFormat = 'json'
    ) {
        if (!isset($formats[$defaultFormat])) {
            throw new \InvalidArgumentException(sprintf('Default format "%s" is not in the pool', $defaultFormat));
        }
    }

    public function select(HttpRequest $request): FormatInterface
    {
        $name = $request->getParam('format');
        if (is_string($name) && isset($this->formats[$name])) {
            return $this->formats[$name];
        }

        foreach ($this->formats as $format) {
            if ($format->matches($request)) {
                return $format;
            }
        }

        return $this->formats[$this->defaultFormat];
    }

    /**
     * @return FormatInterface[] by name
     */
    public function getFormats(): array
    {
        return $this->formats;
    }
}
