<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Observer\Check;

use Fruitcake\HealthCheck\Model\CheckResult;
use Fruitcake\HealthCheck\Model\Config;
use Fruitcake\HealthCheck\Observer\AbstractCheck;
use Magento\Framework\App\Filesystem\DirectoryList;

/**
 * Warns about files in pub/ and pub/media/ that anyone can download: database dumps, archives,
 * phpinfo() pages and project files like .env or auth.json. Only the top level of each directory
 * is read, so the check stays fast.
 */
class PublicFiles extends AbstractCheck
{
    private const NAMES = [
        'info.php', 'phpinfo.php', 'adminer.php', '.git', '.env',
        'composer.json', 'composer.lock', 'auth.json', 'env.php',
    ];

    private const EXTENSIONS = '/\.(sql|sql\.gz|sql\.bz2|sql\.zip|tar|tar\.gz|tgz|zip|7z|rar|bak|old|dump)$/i';

    public function __construct(
        Config $config,
        private readonly DirectoryList $directoryList
    ) {
        parent::__construct($config);
    }

    public function getCode(): string
    {
        return 'public_files';
    }

    protected function check(): CheckResult
    {
        $pub = $this->directoryList->getPath(DirectoryList::PUB);
        $found = [];
        foreach (['' => $pub, 'media/' => $pub . '/media'] as $prefix => $dir) {
            foreach (@scandir($dir) ?: [] as $name) {
                if (in_array(strtolower($name), self::NAMES, true) || preg_match(self::EXTENSIONS, $name)) {
                    $found[] = 'pub/' . $prefix . $name;
                }
            }
        }

        if ($found) {
            return CheckResult::warning('Files that should not be public: ' . implode(', ', $found), ['files' => $found]);
        }

        return CheckResult::ok();
    }
}
