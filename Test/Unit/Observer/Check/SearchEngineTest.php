<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Test\Unit\Observer\Check;

use Fruitcake\HealthCheck\Model\CheckResult;
use Fruitcake\HealthCheck\Model\HealthReport;
use Fruitcake\HealthCheck\Model\SearchEngine\ConnectionTesterInterface;
use Fruitcake\HealthCheck\Model\SearchEngine\CoreConnectionTester;
use Fruitcake\HealthCheck\Model\SearchEngine\ElasticsuiteConnectionTester;
use Fruitcake\HealthCheck\Observer\Check\SearchEngine;
use Fruitcake\HealthCheck\Test\Unit\ConfigStub;
use Magento\AdvancedSearch\Model\Client\ClientInterface;
use Magento\AdvancedSearch\Model\Client\ClientResolver;
use Magento\Framework\Event\Observer;
use Magento\Framework\Search\EngineResolverInterface;
use Magento\Framework\ObjectManagerInterface;
use PHPUnit\Framework\TestCase;

class SearchEngineTest extends TestCase
{
    use ConfigStub;

    public function testFirstTesterThatKnowsTheEngineDecides(): void
    {
        $result = $this->check('amasty_elastic', [
            $this->tester(['opensearch' => true]),
            $this->tester(['amasty_elastic' => false]),
            $this->tester(['amasty_elastic' => true]),
        ]);

        $this->assertSame(CheckResult::FAILED, $result->status);
        $this->assertSame(['engine' => 'amasty_elastic'], $result->meta);
    }

    public function testReachableEngineIsOk(): void
    {
        $this->assertSame(CheckResult::OK, $this->check('opensearch', [$this->tester(['opensearch' => true])])->status);
    }

    public function testEngineNoTesterKnowsOnlyWarns(): void
    {
        $result = $this->check('mirasvit', [$this->tester(['opensearch' => true])]);

        $this->assertSame(CheckResult::WARNING, $result->status);
    }

    public function testCoreTesterSkipsEnginesWithoutCoreClient(): void
    {
        $resolver = $this->createMock(ClientResolver::class);
        $resolver->method('create')->willReturnCallback(function (string $engine) {
            if ($engine !== 'opensearch') {
                throw new \LogicException('There is no such client factory: ' . $engine);
            }
            $client = $this->createMock(ClientInterface::class);
            $client->method('testConnection')->willReturn(false);
            return $client;
        });
        $tester = new CoreConnectionTester($resolver);

        $this->assertNull($tester->test('amasty_elastic'));
        $this->assertFalse($tester->test('opensearch'));
    }

    public function testElasticsuiteTesterOnlyKnowsElasticsuiteWhenInstalled(): void
    {
        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->expects($this->never())->method('get');
        $tester = new ElasticsuiteConnectionTester($objectManager);

        $this->assertNull($tester->test('opensearch'));
        // ElasticSuite is not installed here, so its client is never fetched
        $this->assertNull($tester->test('elasticsuite'));
    }

    /**
     * @param array<string, bool> $engines engine => reachable
     */
    private function tester(array $engines): ConnectionTesterInterface
    {
        $tester = $this->createMock(ConnectionTesterInterface::class);
        $tester->method('test')->willReturnCallback(static fn (string $engine) => $engines[$engine] ?? null);

        return $tester;
    }

    /**
     * @param ConnectionTesterInterface[] $testers
     */
    private function check(string $engine, array $testers): CheckResult
    {
        $engineResolver = $this->createMock(EngineResolverInterface::class);
        $engineResolver->method('getCurrentSearchEngine')->willReturn($engine);

        $config = $this->createConfig(['fruitcake_healthcheck/checks/search_engine' => '1']);
        $report = new HealthReport();
        (new SearchEngine($config, $engineResolver, $testers))->execute(new Observer(['report' => $report]));

        return $report->getResults()['search_engine'];
    }
}
