<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Controller;

use Fruitcake\HealthCheck\Controller\Index\Index;
use Fruitcake\HealthCheck\Model\Config;
use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\RouterInterface;

/**
 * Matches the configured endpoint (default /health). The controller has no routes.xml front name,
 * so it is only reachable on that path.
 */
class Router implements RouterInterface
{
    public const ROUTE_NAME = 'fruitcake_healthcheck';

    public function __construct(
        private readonly ActionFactory $actionFactory,
        private readonly Config $config
    ) {
    }

    public function match(RequestInterface $request): ?ActionInterface
    {
        if (!$request instanceof HttpRequest || !$this->config->isEnabled()) {
            return null;
        }

        $path = trim($request->getPathInfo(), '/');
        if ($path !== $this->config->getEndpoint()) {
            return null;
        }

        $request->setModuleName(self::ROUTE_NAME)
            ->setControllerName('index')
            ->setActionName('index');
        $request->setRouteName(self::ROUTE_NAME);

        return $this->actionFactory->create(Index::class);
    }
}
