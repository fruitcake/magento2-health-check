<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Test\Unit\Model\Format;

use Fruitcake\HealthCheck\Model\Format\FormatInterface;
use Fruitcake\HealthCheck\Model\Format\FormatPool;
use Magento\Framework\App\Request\Http as HttpRequest;
use PHPUnit\Framework\TestCase;

class FormatPoolTest extends TestCase
{
    public function testFormatParameterPicksByName(): void
    {
        $pool = $this->pool();

        $this->assertSame('prometheus', $pool->select($this->request(['format' => 'prometheus']))->getLabel());
    }

    public function testFirstMatchingFormatWins(): void
    {
        $pool = $this->pool();

        $this->assertSame('first', $pool->select($this->request([], ['X-Both' => '1']))->getLabel());
    }

    public function testUnknownFormatFallsBackToMatchingOrDefault(): void
    {
        $pool = $this->pool();

        $this->assertSame('json', $pool->select($this->request(['format' => 'nope']))->getLabel());
        $this->assertSame('json', $pool->select($this->request(['format' => ['json']]))->getLabel());
    }

    public function testDefaultFormatMustExist(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new FormatPool(['html' => $this->format('html')], 'json');
    }

    private function pool(): FormatPool
    {
        return new FormatPool([
            'first' => $this->format('first', 'X-Both'),
            'second' => $this->format('second', 'X-Both'),
            'prometheus' => $this->format('prometheus'),
            'json' => $this->format('json'),
        ]);
    }

    private function format(string $label, ?string $header = null): FormatInterface
    {
        $format = $this->createMock(FormatInterface::class);
        $format->method('getLabel')->willReturn($label);
        $format->method('matches')->willReturnCallback(
            static fn (HttpRequest $request) => $header !== null && $request->getHeader($header) !== false
        );

        return $format;
    }

    private function request(array $params = [], array $headers = []): HttpRequest
    {
        $request = $this->createMock(HttpRequest::class);
        $request->method('getParam')->willReturnCallback(static fn (string $name) => $params[$name] ?? null);
        $request->method('getHeader')->willReturnCallback(static fn (string $name) => $headers[$name] ?? false);

        return $request;
    }
}
