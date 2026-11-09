<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Test\Unit\Controller\Index;

use Fruitcake\HealthCheck\Controller\Index\Index;
use Fruitcake\HealthCheck\Model\CheckResult;
use Fruitcake\HealthCheck\Model\HealthCheck;
use Fruitcake\HealthCheck\Model\HealthReport;
use Fruitcake\HealthCheck\Test\Unit\ConfigStub;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\HttpInterface as HttpResponse;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Event\ConfigInterface as EventConfig;
use Magento\Framework\Event\InvokerInterface;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class IndexTest extends TestCase
{
    use ConfigStub;

    private const KEY_CONFIG = [
        'fruitcake_healthcheck/general/key' => 'secret',
    ];

    public function testReturnsHealthJsonWithoutCaching(): void
    {
        $response = $this->call(['database' => CheckResult::ok()]);

        $this->assertSame(200, $response['code']);
        $this->assertSame('{"status":"pass"}', $response['body']);
        $this->assertSame('application/health+json', $response['headers']['Content-Type']);
        $this->assertStringContainsString('no-store', $response['headers']['Cache-Control']);
    }

    public function testReturnsJsonWhateverTheAcceptHeader(): void
    {
        $response = $this->call(['database' => CheckResult::ok()], headers: ['Accept' => 'text/plain']);

        $this->assertSame('application/health+json', $response['headers']['Content-Type']);
        $this->assertSame(['status' => 'pass'], json_decode($response['body'], true));
    }

    public function testWarningKeepsEndpointUp(): void
    {
        $response = $this->call(['database' => CheckResult::ok(), 'cron' => CheckResult::warning('late')]);

        $this->assertSame(200, $response['code']);
        $this->assertSame(['status' => 'warn'], json_decode($response['body'], true));
    }

    public function testAnyFailedCheckReturns503(): void
    {
        $response = $this->call([
            'database' => CheckResult::failed('down'),
            'cron' => CheckResult::warning('late'),
            'cache_storage' => CheckResult::ok(),
        ]);

        $this->assertSame(503, $response['code']);
        $this->assertSame(['status' => 'fail'], json_decode($response['body'], true));
    }

    public function testObserverThatThrowsReturns503(): void
    {
        $invoker = $this->createMock(InvokerInterface::class);
        $invoker->method('dispatch')->willThrowException(new \RuntimeException('broken observer'));

        $response = $this->render($this->controller([], [], $this->healthCheck($invoker)));

        $this->assertSame(503, $response['code']);
    }

    public function testRejectsMissingKey(): void
    {
        $response = $this->call(['database' => CheckResult::ok()], self::KEY_CONFIG, expectChecks: false);

        $this->assertSame(403, $response['code']);
    }

    public function testRejectsWrongBearerToken(): void
    {
        $response = $this->call(
            ['database' => CheckResult::ok()],
            self::KEY_CONFIG,
            headers: ['Authorization' => 'Bearer wrong'],
            expectChecks: false
        );

        $this->assertSame(403, $response['code']);
    }

    public function testEncryptedKeyFromBefore10StillWorks(): void
    {
        $config = ['fruitcake_healthcheck/general/key' => '0:3:secret'];

        $withoutKey = $this->call(['database' => CheckResult::ok()], $config, expectChecks: false);
        $withKey = $this->call(['database' => CheckResult::ok()], $config, ['Authorization' => 'Bearer secret']);

        $this->assertSame(403, $withoutKey['code']);
        $this->assertSame(200, $withKey['code']);
    }

    public function testKeyThatLooksEncryptedButDoesNotDecryptIsUsedAsIs(): void
    {
        $config = ['fruitcake_healthcheck/general/key' => '1:2:not-really'];

        $withoutKey = $this->call(['database' => CheckResult::ok()], $config, expectChecks: false);
        $withKey = $this->call(['database' => CheckResult::ok()], $config, ['Authorization' => 'Bearer 1:2:not-really']);

        $this->assertSame(403, $withoutKey['code']);
        $this->assertSame(200, $withKey['code']);
    }

    public function testForbiddenResponseRevealsNothing(): void
    {
        $response = $this->call(
            ['database' => CheckResult::failed('SQLSTATE[HY000] [2002] Connection refused')],
            self::KEY_CONFIG + ['fruitcake_healthcheck/general/show_details' => '1'],
            expectChecks: false
        );

        $this->assertSame(['error' => 'Forbidden'], json_decode($response['body'], true));
    }

    public function testAcceptsBearerToken(): void
    {
        $response = $this->call(
            ['database' => CheckResult::ok()],
            self::KEY_CONFIG,
            headers: ['Authorization' => 'Bearer secret']
        );

        $this->assertSame(200, $response['code']);
    }

    public function testAcceptsKeyParameter(): void
    {
        $response = $this->call(['database' => CheckResult::ok()], self::KEY_CONFIG, params: ['key' => 'secret']);

        $this->assertSame(200, $response['code']);
    }

    public function testArrayKeyParameterIsForbidden(): void
    {
        $response = $this->call(
            ['database' => CheckResult::ok()],
            self::KEY_CONFIG,
            params: ['key' => ['secret']],
            expectChecks: false
        );

        $this->assertSame(403, $response['code']);
    }

    public function testHidesDetailsWithoutKeyEvenWhenEnabled(): void
    {
        $response = $this->call(
            ['cron' => CheckResult::warning('late')],
            ['fruitcake_healthcheck/general/show_details' => '1'],
        );

        $this->assertSame(['status' => 'warn'], json_decode($response['body'], true));
    }

    public function testShowsDetailsWithKey(): void
    {
        $config = self::KEY_CONFIG + ['fruitcake_healthcheck/general/show_details' => '1'];
        $checks = [
            'database' => CheckResult::ok('connected')->withDuration(1.23),
            'cron' => CheckResult::warning('late', ['minutes_ago' => 20]),
        ];

        $response = $this->call($checks, $config, ['Authorization' => 'Bearer secret']);

        $this->assertSame([
            'status' => 'warn',
            'output' => 'cron: late',
            'checks' => [
                'database' => [['status' => 'pass', 'durationMs' => 1.2]],
                'cron' => [['status' => 'warn', 'output' => 'late', 'meta' => ['minutes_ago' => 20]]],
            ],
        ], json_decode($response['body'], true));
    }

    public function testOutputListsFailuresBeforeWarnings(): void
    {
        $config = self::KEY_CONFIG + ['fruitcake_healthcheck/general/show_details' => '1'];
        $checks = [
            'database' => CheckResult::ok(),
            'cron' => CheckResult::warning('late'),
            'search_engine' => CheckResult::failed('not reachable'),
        ];

        $response = $this->call($checks, $config, ['Authorization' => 'Bearer secret']);

        $this->assertSame('search_engine: not reachable; cron: late', json_decode($response['body'], true)['output']);
    }

    public function testNoOutputWhenEverythingPasses(): void
    {
        $config = self::KEY_CONFIG + ['fruitcake_healthcheck/general/show_details' => '1'];

        $response = $this->call(['database' => CheckResult::ok()], $config, ['Authorization' => 'Bearer secret']);

        $this->assertArrayNotHasKey('output', json_decode($response['body'], true));
    }

    public function testChecksIsAnObjectWhenNoCheckRan(): void
    {
        $config = self::KEY_CONFIG + ['fruitcake_healthcheck/general/show_details' => '1'];

        $response = $this->call([], $config, ['Authorization' => 'Bearer secret']);

        $this->assertSame('{"status":"pass","checks":{}}', $response['body']);
    }

    /**
     * @param array<string, CheckResult> $checks results the observers add
     */
    private function call(
        array $checks,
        array $config = [],
        array $headers = [],
        array $params = [],
        bool $expectChecks = true
    ): array {
        $invoker = $this->createMock(InvokerInterface::class);
        $invoker->expects($expectChecks ? $this->once() : $this->never())
            ->method('dispatch')
            ->willReturnCallback(static function (array $observerConfig, Observer $observer) use ($checks) {
                /** @var HealthReport $report */
                $report = $observer->getData('report');
                foreach ($checks as $code => $result) {
                    $report->add($code, $result);
                }
            });

        return $this->render($this->controller($headers, $params, $this->healthCheck($invoker), $config));
    }

    private function controller(
        array $headers,
        array $params,
        HealthCheck $healthCheck,
        array $config = []
    ): Index {
        $request = $this->createMock(HttpRequest::class);
        $request->method('getHeader')->willReturnCallback(static fn (string $name) => $headers[$name] ?? false);
        $request->method('getParam')->willReturnCallback(
            static fn (string $name, $default = null) => $params[$name] ?? $default
        );

        $rawFactory = $this->createMock(RawFactory::class);
        $rawFactory->method('create')->willReturnCallback(static fn () => new Raw());

        return new Index(
            $request,
            $rawFactory,
            $this->createConfig($config + ['fruitcake_healthcheck/general/enabled' => '1']),
            $healthCheck
        );
    }

    /**
     * A HealthCheck with one registered observer, run by the given invoker.
     */
    private function healthCheck(InvokerInterface $invoker): HealthCheck
    {
        $eventConfig = $this->createMock(EventConfig::class);
        $eventConfig->method('getObservers')->with(HealthCheck::EVENT)->willReturn([
            'test' => ['name' => 'test', 'instance' => 'Test\\Observer'],
        ]);

        return new HealthCheck(
            $eventConfig,
            $invoker,
            $this->createMock(CacheInterface::class),
            $this->createMock(LoggerInterface::class)
        );
    }

    /**
     * @return array{code: int, headers: array<string, string>, body: string}
     */
    private function render(Index $controller): array
    {
        $captured = ['code' => 200, 'headers' => [], 'body' => ''];
        $response = $this->createMock(HttpResponse::class);
        $response->method('setHttpResponseCode')->willReturnCallback(
            function (int $code) use (&$captured, $response) {
                $captured['code'] = $code;
                return $response;
            }
        );
        $response->method('setHeader')->willReturnCallback(
            function (string $name, string $value) use (&$captured, $response) {
                $captured['headers'][$name] = $value;
                return $response;
            }
        );
        $response->method('setBody')->willReturnCallback(
            function (string $body) use (&$captured, $response) {
                $captured['body'] = $body;
                return $response;
            }
        );

        $controller->execute()->renderResult($response);

        return $captured;
    }
}
