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
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Framework\Translate\InlineInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class IndexTest extends TestCase
{
    use ConfigStub;

    private const KEY_CONFIG = [
        'fruitcake_healthcheck/general/key' => 'enc:secret',
    ];

    public function testReturnsOkAsPlainTextWithoutCaching(): void
    {
        $response = $this->call(['database' => CheckResult::ok()]);

        $this->assertSame(200, $response['code']);
        $this->assertSame("OK\n", $response['body']);
        $this->assertSame('text/plain; charset=utf-8', $response['headers']['Content-Type']);
        $this->assertStringContainsString('no-store', $response['headers']['Cache-Control']);
    }

    public function testReturnsJsonWhenAccepted(): void
    {
        $response = $this->call(['database' => CheckResult::ok()], headers: ['Accept' => 'application/json']);

        $this->assertSame(200, $response['code']);
        $this->assertSame('application/json', $response['headers']['Content-Type']);
        $this->assertSame(['status' => 'ok'], json_decode($response['body'], true));
    }

    public function testWarningKeepsEndpointUp(): void
    {
        $response = $this->call(['database' => CheckResult::ok(), 'cron' => CheckResult::warning('late')]);

        $this->assertSame(200, $response['code']);
        $this->assertSame("WARNING\n", $response['body']);
    }

    public function testAnyFailedCheckReturns503(): void
    {
        $response = $this->call([
            'database' => CheckResult::failed('down'),
            'cron' => CheckResult::warning('late'),
            'cache' => CheckResult::ok(),
        ]);

        $this->assertSame(503, $response['code']);
        $this->assertSame("FAILED\n", $response['body']);
    }

    public function testObserverThatThrowsReturns503(): void
    {
        $eventManager = $this->createMock(ManagerInterface::class);
        $eventManager->method('dispatch')->willThrowException(new \RuntimeException('broken observer'));

        $response = $this->render($this->controller([], [], $eventManager));

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

    public function testPlainTextKeyThatDoesNotDecryptStillProtectsEndpoint(): void
    {
        $config = ['fruitcake_healthcheck/general/key' => 'plain-secret'];

        $withoutKey = $this->call(['database' => CheckResult::ok()], $config, expectChecks: false);
        $withKey = $this->call(['database' => CheckResult::ok()], $config, ['Authorization' => 'Bearer plain-secret']);

        $this->assertSame(403, $withoutKey['code']);
        $this->assertSame(200, $withKey['code']);
    }

    public function testForbiddenResponseRevealsNothing(): void
    {
        $response = $this->call(
            ['database' => CheckResult::failed('SQLSTATE[HY000] [2002] Connection refused')],
            self::KEY_CONFIG + ['fruitcake_healthcheck/general/show_details' => '1'],
            headers: ['Accept' => 'application/json'],
            expectChecks: false
        );

        $this->assertSame(['status' => 'forbidden'], json_decode($response['body'], true));
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

    public function testHidesDetailsWithoutKeyEvenWhenEnabled(): void
    {
        $response = $this->call(
            ['cron' => CheckResult::warning('late')],
            ['fruitcake_healthcheck/general/show_details' => '1'],
            headers: ['Accept' => 'application/json']
        );

        $this->assertSame(['status' => 'warning'], json_decode($response['body'], true));
    }

    public function testShowsDetailsWithKey(): void
    {
        $config = self::KEY_CONFIG + ['fruitcake_healthcheck/general/show_details' => '1'];
        $checks = ['database' => CheckResult::ok(), 'cron' => CheckResult::warning('late', ['minutes_ago' => 20])];

        $json = $this->call($checks, $config, ['Accept' => 'application/json', 'Authorization' => 'Bearer secret']);
        $text = $this->call($checks, $config, ['Authorization' => 'Bearer secret']);

        $this->assertSame([
            'status' => 'warning',
            'checks' => [
                'database' => ['status' => 'ok'],
                'cron' => ['status' => 'warning', 'message' => 'late', 'meta' => ['minutes_ago' => 20]],
            ],
        ], json_decode($json['body'], true));
        $this->assertSame("WARNING\ndatabase: ok\ncron: warning - late\n", $text['body']);
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
        $eventManager = $this->createMock(ManagerInterface::class);
        $eventManager->expects($expectChecks ? $this->once() : $this->never())
            ->method('dispatch')
            ->with(HealthCheck::EVENT)
            ->willReturnCallback(static function (string $event, array $data) use ($checks) {
                /** @var HealthReport $report */
                $report = $data['report'];
                foreach ($checks as $code => $result) {
                    $report->add($code, $result);
                }
            });

        return $this->render($this->controller($headers, $params, $eventManager, $config));
    }

    private function controller(
        array $headers,
        array $params,
        ManagerInterface $eventManager,
        array $config = []
    ): Index {
        $request = $this->createMock(HttpRequest::class);
        $request->method('getHeader')->willReturnCallback(static fn (string $name) => $headers[$name] ?? false);
        $request->method('getParam')->willReturnCallback(
            static fn (string $name, $default = null) => $params[$name] ?? $default
        );

        $jsonFactory = $this->createMock(JsonFactory::class);
        $jsonFactory->method('create')->willReturnCallback(
            fn () => new Json($this->createMock(InlineInterface::class), new JsonSerializer())
        );
        $rawFactory = $this->createMock(RawFactory::class);
        $rawFactory->method('create')->willReturnCallback(static fn () => new Raw());

        return new Index(
            $request,
            $jsonFactory,
            $rawFactory,
            $this->createConfig($config + ['fruitcake_healthcheck/general/enabled' => '1']),
            new HealthCheck($eventManager, $this->createMock(LoggerInterface::class))
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
