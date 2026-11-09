<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Observer\Check;

use Fruitcake\HealthCheck\Model\CheckResult;
use Fruitcake\HealthCheck\Model\Config;
use Fruitcake\HealthCheck\Observer\AbstractCheck;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Session\SaveHandlerFactory;
use Magento\Framework\Session\SaveHandlerInterface;

/**
 * Writes, reads and removes a session in the storage from env.php (session/save).
 *
 * Uses the handler itself, not Magento's SaveHandler: that one quietly falls back to files
 * when Redis is down, so every node keeps its own sessions and customers get logged out.
 */
class SessionStorage extends AbstractCheck
{
    public function __construct(
        Config $config,
        private readonly DeploymentConfig $deploymentConfig,
        private readonly SaveHandlerFactory $saveHandlerFactory,
        private readonly Filesystem $filesystem
    ) {
        parent::__construct($config);
    }

    public function getCode(): string
    {
        return 'session_storage';
    }

    protected function check(): CheckResult
    {
        $handler = (string) ($this->deploymentConfig->get('session/save') ?: SaveHandlerInterface::DEFAULT_HANDLER);
        $meta = ['handler' => $handler];

        $works = match ($handler) {
            'files' => $this->testFiles(),
            'redis', 'db' => $this->testHandler($handler),
            default => null,
        };

        if ($works === null) {
            return CheckResult::warning(sprintf('No storage test available for session handler "%s"', $handler), $meta);
        }

        return $works
            ? CheckResult::ok('', $meta)
            : CheckResult::failed(sprintf('Session storage "%s" cannot write and read back a session', $handler), $meta);
    }

    private function testHandler(string $handler): bool
    {
        $storage = $this->saveHandlerFactory->create($handler);
        $sessionId = 'fruitcake_healthcheck_' . bin2hex(random_bytes(8));
        $value = (string) time();

        $storage->open('', 'healthcheck');
        try {
            return $storage->write($sessionId, $value) && $storage->read($sessionId) === $value;
        } finally {
            $storage->destroy($sessionId);
            $storage->close();
        }
    }

    /**
     * PHP's own files handler only works inside an active session, so test the directory instead.
     */
    private function testFiles(): bool
    {
        $path = (string) ($this->deploymentConfig->get('session/save_path') ?: ini_get('session.save_path'));
        if ($path === '') {
            $path = $this->filesystem->getDirectoryRead(DirectoryList::SESSION)->getAbsolutePath();
        }
        // session.save_path may be "N;/path" or "N;MODE;/path"
        $path = rtrim(substr($path, (int) strrpos(';' . $path, ';')), '/');

        $file = $path . '/sess_fruitcake_healthcheck_' . bin2hex(random_bytes(8));
        $value = (string) time();
        try {
            return @file_put_contents($file, $value) !== false && @file_get_contents($file) === $value;
        } finally {
            @unlink($file);
        }
    }
}
