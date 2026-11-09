<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Test\Unit\Controller;

use Fruitcake\HealthCheck\Controller\Index\Index;
use Fruitcake\HealthCheck\Controller\Router;
use Fruitcake\HealthCheck\Test\Unit\ConfigStub;
use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RouterTest extends TestCase
{
    use ConfigStub;

    public static function matchingPaths(): array
    {
        return [
            'default endpoint' => [null, '/health'],
            'trailing slash' => [null, '/health/'],
            'custom endpoint with slashes in config' => ['/up/', '/up'],
        ];
    }

    #[DataProvider('matchingPaths')]
    public function testMatchesConfiguredEndpoint(?string $endpoint, string $path): void
    {
        $action = $this->createMock(ActionInterface::class);
        $actionFactory = $this->createMock(ActionFactory::class);
        $actionFactory->expects($this->once())->method('create')->with(Index::class)->willReturn($action);

        $router = new Router($actionFactory, $this->createConfig([
            'fruitcake_healthcheck/general/enabled' => '1',
            'fruitcake_healthcheck/general/endpoint' => $endpoint,
        ]));

        $this->assertSame($action, $router->match($this->request($path)));
    }

    public static function otherPaths(): array
    {
        return [
            'home' => ['/'],
            'prefix' => ['/healthy'],
            'nested' => ['/catalog/health'],
        ];
    }

    #[DataProvider('otherPaths')]
    public function testIgnoresOtherPaths(string $path): void
    {
        $router = new Router($this->neverCreates(), $this->createConfig([
            'fruitcake_healthcheck/general/enabled' => '1',
            'fruitcake_healthcheck/general/endpoint' => 'health',
        ]));

        $this->assertNull($router->match($this->request($path)));
    }

    public function testIgnoresEndpointWhenDisabled(): void
    {
        $router = new Router($this->neverCreates(), $this->createConfig([
            'fruitcake_healthcheck/general/enabled' => '0',
            'fruitcake_healthcheck/general/endpoint' => 'health',
        ]));

        $this->assertNull($router->match($this->request('/health')));
    }

    private function request(string $path): Http
    {
        $request = $this->createMock(Http::class);
        $request->method('getPathInfo')->willReturn($path);
        $request->method('setModuleName')->willReturnSelf();
        $request->method('setControllerName')->willReturnSelf();
        $request->method('setActionName')->willReturnSelf();

        return $request;
    }

    private function neverCreates(): ActionFactory
    {
        $actionFactory = $this->createMock(ActionFactory::class);
        $actionFactory->expects($this->never())->method('create');

        return $actionFactory;
    }
}
