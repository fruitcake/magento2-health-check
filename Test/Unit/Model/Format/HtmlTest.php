<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Test\Unit\Model\Format;

use Fruitcake\HealthCheck\Model\BrowserDetector;
use Fruitcake\HealthCheck\Model\Config;
use Fruitcake\HealthCheck\Model\Format\HealthJson;
use Fruitcake\HealthCheck\Model\Format\Html;
use Fruitcake\HealthCheck\Model\ServiceInfo;
use PHPUnit\Framework\TestCase;

class HtmlTest extends TestCase
{
    public function testProblemsComeFirstInCheckOrder(): void
    {
        $html = $this->render([
            'database' => [['status' => 'pass']],
            'cron' => [['status' => 'warn']],
            'cache_storage' => [['status' => 'pass']],
            'search_engine' => [['status' => 'fail']],
            'opcache' => [['status' => 'warn']],
        ]);

        preg_match_all('#data-check="([a-z_]+)"#', $html, $matches);
        $this->assertSame(['search_engine', 'cron', 'opcache', 'database', 'cache_storage'], $matches[1]);
    }

    public function testMetaIsOneChipPerItem(): void
    {
        $html = $this->render(['cache_storage' => [['status' => 'pass', 'meta' => [
            'backends' => ['default' => 'Redis', 'page_cache' => 'File'],
            'failed' => ['default', 'page_cache'],
            'configured' => false,
            'last_finished_at' => '2026-11-09T12:11:08+00:00',
        ]]]]);

        // A nested object becomes a chip per key, a list one chip, dates are UTC
        $this->assertStringContainsString('<span class="chip-key">default</span>Redis</span>', $html);
        $this->assertStringContainsString('<span class="chip-key">page_cache</span>File</span>', $html);
        $this->assertStringContainsString('<span class="chip-key">failed</span>default, page_cache</span>', $html);
        $this->assertStringContainsString('<span class="chip-key">configured</span>false</span>', $html);
        $this->assertStringContainsString('<span class="chip-key">last_finished_at</span>2026-11-09 12:11:08 UTC</span>', $html);
    }

    public function testMeasurementsAreChipsOfTheirCheck(): void
    {
        $html = $this->render([
            'disk_space' => [['status' => 'pass', 'meta' => ['path' => 'var']]],
            'disk_space:free' => [['observedValue' => 61.3, 'observedUnit' => 'percent', 'status' => 'pass']],
            'disk_space:freeSpace' => [['observedValue' => 48213, 'observedUnit' => 'MiB', 'status' => 'pass']],
            'cron:lastFinished' => [['observedValue' => 3, 'observedUnit' => 'min', 'status' => 'pass']],
        ]);

        // One row for disk_space; a measurement without its check keeps a row of its own
        preg_match_all('#data-check="([a-z_:A-Z]+)"#', $html, $matches);
        $this->assertSame(['disk_space', 'cron:lastFinished'], $matches[1]);
        $this->assertStringContainsString('<span class="chip-key">free</span>61.3%</span>', $html);
        $this->assertStringContainsString('<span class="chip-key">freeSpace</span>47.1 GiB</span>', $html);
        $this->assertStringContainsString('<span class="chip-key">path</span>var</span>', $html);
    }

    public function testBannerNamesTheFailingChecks(): void
    {
        $data = ['status' => 'fail', 'serviceId' => 'web1', 'checks' => [
            'database' => [['status' => 'pass']],
            'search_engine' => [['status' => 'fail', 'output' => 'No alive nodes found in your cluster']],
        ]];
        $html = (new Html(
            new HealthJson($this->createMock(ServiceInfo::class)),
            new BrowserDetector($this->createMock(Config::class))
        ))->renderPage($data, '{}');

        $this->assertStringContainsString('<h1>web1</h1>', $html);
        $this->assertStringContainsString('Fail — 1 check failing', $html);
        $this->assertStringContainsString('search_engine</span>: No alive nodes found in your cluster', $html);
    }

    public function testMetaIsEscaped(): void
    {
        $html = $this->render(['custom' => [['status' => 'pass', 'meta' => ['<b>key</b>' => '<script>x</script>']]]]);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>', $html);
    }

    private function render(array $checks): string
    {
        $data = ['status' => 'warn', 'checks' => $checks];

        $html = new Html(
            new HealthJson($this->createMock(ServiceInfo::class)),
            new BrowserDetector($this->createMock(Config::class))
        );

        return $html->renderPage($data, (string) json_encode($data));
    }
}
