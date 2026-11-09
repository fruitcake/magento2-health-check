<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Test\Unit\Model\Format;

use Fruitcake\HealthCheck\Model\Format\Html;
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

        preg_match_all('#<tr><td>([a-z_]+)</td>#', $html, $matches);
        $this->assertSame(['search_engine', 'cron', 'opcache', 'database', 'cache_storage'], $matches[1]);
    }

    public function testMetaIsOneLinePerItem(): void
    {
        $html = $this->render(['cache_storage' => [['status' => 'pass', 'meta' => [
            'backends' => ['default' => 'Redis', 'page_cache' => 'File'],
            'failed' => ['default', 'page_cache'],
            'configured' => false,
            'free_percent' => 61.3,
        ]]]]);

        $this->assertStringContainsString('backends:</span> default: Redis, page_cache: File</div>', $html);
        $this->assertStringContainsString('failed:</span> default, page_cache</div>', $html);
        $this->assertStringContainsString('configured:</span> false</div>', $html);
        $this->assertStringContainsString('free_percent:</span> 61.3</div>', $html);
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

        return (new Html())->render($data, (string) json_encode($data));
    }
}
