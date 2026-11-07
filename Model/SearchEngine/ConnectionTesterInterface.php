<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Model\SearchEngine;

/**
 * Tests the connection of a search engine. Add implementations for other engines to the
 * "testers" argument of Fruitcake\HealthCheck\Observer\Check\SearchEngine in di.xml.
 */
interface ConnectionTesterInterface
{
    /**
     * @return bool|null true when reachable, false when not, null when this tester does not know the engine
     */
    public function test(string $engine): ?bool;
}
