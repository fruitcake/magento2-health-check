<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Test\Unit\Model;

use Fruitcake\HealthCheck\Model\Redactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RedactorTest extends TestCase
{
    public static function texts(): array
    {
        return [
            'url' => [
                'Could not connect to https://elastic:s3cr3t@search.internal:9200/_cluster/health',
                'Could not connect to https://***:***@search.internal:9200/_cluster/health',
            ],
            'amqp' => ['amqp://magento:p4ss@rabbit:5672/', 'amqp://***:***@rabbit:5672/'],
            'without scheme' => ['Failed for magento:p4ss@rabbit:5672', 'Failed for ***:***@rabbit:5672'],
            'dsn' => ['mysql:host=db;dbname=m2;password=hunter2', 'mysql:host=db;dbname=m2;password=***'],
            'json' => ['{"user":"m2","password":"hunter2"}', '{"user":"m2","password":"***"}'],
            'plain message' => [
                'SQLSTATE[HY000] [2002] Connection refused',
                'SQLSTATE[HY000] [2002] Connection refused',
            ],
            'email address' => ['Mail to info@example.com failed', 'Mail to info@example.com failed'],
        ];
    }

    #[DataProvider('texts')]
    public function testRedacts(string $text, string $expected): void
    {
        $this->assertSame($expected, Redactor::redact($text));
    }
}
