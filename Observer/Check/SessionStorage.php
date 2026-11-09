<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Observer\Check;

use Fruitcake\HealthCheck\Model\CheckResult;
use Fruitcake\HealthCheck\Model\Config;
use Fruitcake\HealthCheck\Observer\AbstractCheck;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Session\Config\ConfigInterface as SessionConfig;
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
        private readonly SessionConfig $sessionConfig
    ) {
        parent::__construct($config);
    }

    protected function measuresResponseTime(): bool
    {
        return true;
    }

    public function getCode(): string
    {
        return 'session_storage';
    }

    protected function check(): CheckResult
    {
        $configured = (string) ($this->deploymentConfig->get('session/save') ?: SaveHandlerInterface::DEFAULT_HANDLER);
        $handler = $this->getEffectiveHandler($configured);
        $meta = ['handler' => $handler] + ($handler !== $configured ? ['configured' => $configured] : []);

        $works = match ($handler) {
            'files' => $this->testFiles(),
            'redis', 'db' => $this->testHandler($handler),
            default => null,
        };

        if ($works === null) {
            return CheckResult::warning(sprintf('No storage test available for session handler "%s"', $handler), $meta);
        }

        if (!$works) {
            return CheckResult::failed(sprintf('Session storage "%s" cannot write and read back a session', $handler), $meta);
        }

        // The db handler only deletes old sessions in gc(), which PHP never calls with a probability of 0
        // (the Debian/Ubuntu default: their cron job only cleans session files)
        if ($handler === 'db' && (int) ini_get('session.gc_probability') <= 0) {
            return CheckResult::warning(
                'Session garbage collection is off (session.gc_probability = 0), so the session table is never cleaned',
                $meta + ['gc_probability' => 0]
            );
        }

        return CheckResult::ok('', $meta);
    }

    /**
     * Magento has its own handlers for "db" and "redis" only (SaveHandlerFactory in app/etc/di.xml). Anything else,
     * e.g. "database", uses PHP's own handler, and only "memcached" is passed on to php.ini: the rest keeps
     * PHP's session.save_handler, usually files.
     */
    private function getEffectiveHandler(string $configured): string
    {
        if (in_array($configured, ['db', 'redis', 'memcached'], true)) {
            return $configured;
        }

        return (string) (ini_get('session.save_handler') ?: SaveHandlerInterface::DEFAULT_HANDLER);
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
     * PHP's own files handler only works inside an active session, so write a session file the way it does:
     * in the save path Magento gives PHP (env.php, php.ini or var/session, which Magento creates), and with
     * "N;/path" in N levels of subdirectories named after the first characters of the session id.
     */
    private function testFiles(): bool
    {
        $savePath = $this->sessionConfig->getSavePath();
        $parts = explode(';', $savePath);
        $path = rtrim((string) array_pop($parts), '/');
        $depth = count($parts) > 0 ? (int) $parts[0] : 0;

        // Hex characters only, which exist as subdirectory names whatever session.sid_bits_per_character is
        $sessionId = bin2hex(random_bytes(16));
        $dir = $path;
        for ($i = 0; $i < $depth; $i++) {
            $dir .= '/' . $sessionId[$i];
        }

        $file = $dir . '/sess_' . $sessionId;
        $value = (string) time();
        try {
            return @file_put_contents($file, $value) !== false && @file_get_contents($file) === $value;
        } finally {
            @unlink($file);
        }
    }
}
