<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Test\Unit\Observer\Check;

use Fruitcake\HealthCheck\Model\CheckResult;
use Fruitcake\HealthCheck\Model\HealthReport;
use Fruitcake\HealthCheck\Observer\Check\PublicFiles;
use Fruitcake\HealthCheck\Test\Unit\ConfigStub;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;

class PublicFilesTest extends TestCase
{
    use ConfigStub;

    private string $pub;

    protected function setUp(): void
    {
        $this->pub = sys_get_temp_dir() . '/healthcheck_pub_' . bin2hex(random_bytes(4));
        mkdir($this->pub . '/media', 0777, true);
        touch($this->pub . '/index.php');
        touch($this->pub . '/.user.ini');
        touch($this->pub . '/.htaccess');
    }

    protected function tearDown(): void
    {
        foreach (array_merge(glob($this->pub . '/media/{,.}*', GLOB_BRACE), glob($this->pub . '/{,.}*', GLOB_BRACE)) as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->pub . '/media');
        rmdir($this->pub);
    }

    public function testRegularMagentoFilesPass(): void
    {
        $this->assertSame(CheckResult::OK, $this->check()->status);
    }

    public function testDumpsArchivesAndProjectFilesWarn(): void
    {
        touch($this->pub . '/backup.sql.gz');
        touch($this->pub . '/phpinfo.php');
        touch($this->pub . '/.env');
        touch($this->pub . '/media/export.zip');

        $result = $this->check();

        $this->assertSame(CheckResult::WARNING, $result->status);
        $this->assertEqualsCanonicalizing(
            ['pub/backup.sql.gz', 'pub/phpinfo.php', 'pub/.env', 'pub/media/export.zip'],
            $result->meta['files']
        );
    }

    private function check(): CheckResult
    {
        $directoryList = $this->createMock(DirectoryList::class);
        $directoryList->method('getPath')->with(DirectoryList::PUB)->willReturn($this->pub);

        $report = new HealthReport();
        (new PublicFiles($this->createConfig(['fruitcake_healthcheck/checks/public_files' => '1']), $directoryList))
            ->execute(new Observer(['report' => $report]));

        return $report->getResults()['public_files'];
    }
}
