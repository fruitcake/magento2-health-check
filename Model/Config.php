<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Model;

use Fruitcake\HealthCheck\Model\Config\Source\CheckMode;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State;
use Magento\Framework\Encryption\EncryptorInterface;

/**
 * All settings live in the default scope: the health check is not store specific.
 */
class Config
{
    private const PREFIX = 'fruitcake_healthcheck/';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly State $appState
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::PREFIX . 'general/enabled');
    }

    public function getEndpoint(): string
    {
        $endpoint = trim((string) $this->scopeConfig->getValue(self::PREFIX . 'general/endpoint'), " /");

        return $endpoint !== '' ? $endpoint : 'health';
    }

    /**
     * The key is stored as plain text. Versions before 1.0 stored it encrypted ("0:3:..."), which still works.
     */
    public function getKey(): string
    {
        $value = trim((string) $this->scopeConfig->getValue(self::PREFIX . 'general/key'));
        if (!self::isEncrypted($value)) {
            return $value;
        }

        try {
            $decrypted = trim((string) $this->encryptor->decrypt($value));
        } catch (\Throwable $e) {
            $decrypted = '';
        }

        // A value that does not decrypt is used as is, so the endpoint never ends up open
        return $decrypted !== '' ? $decrypted : $value;
    }

    /**
     * Magento's encrypted format: key version, cipher version and data, e.g. "0:3:abc...".
     * Only those values go through decrypt(): it treats anything else as legacy Blowfish and throws or returns garbage.
     */
    public static function isEncrypted(string $value): bool
    {
        return (bool) preg_match('/^\d+:\d+:/', $value);
    }

    public function hasKey(): bool
    {
        return $this->getKey() !== '';
    }

    public function isValidKey(string $given): bool
    {
        $key = $this->getKey();

        return $key === '' || hash_equals(hash('sha256', $key), hash('sha256', $given));
    }

    public function showDetails(): bool
    {
        return $this->hasKey() && $this->scopeConfig->isSetFlag(self::PREFIX . 'general/show_details');
    }

    /**
     * Extra monitor user agents from the admin, one per line, on top of the ones in di.xml.
     *
     * @return string[]
     */
    public function getMonitorUserAgents(): array
    {
        $value = (string) $this->scopeConfig->getValue(self::PREFIX . 'general/monitor_user_agents');

        return array_values(array_filter(array_map('trim', preg_split('/\R/', $value) ?: [])));
    }

    public function isCheckEnabled(string $code): bool
    {
        return $this->getCheckMode($code) !== CheckMode::OFF;
    }

    /**
     * A strict check reports warnings as failures.
     */
    public function isCheckStrict(string $code): bool
    {
        return match ($this->getCheckMode($code)) {
            CheckMode::STRICT => true,
            CheckMode::STRICT_PRODUCTION => $this->appState->getMode() === State::MODE_PRODUCTION,
            default => false,
        };
    }

    private function getCheckMode(string $code): string
    {
        $mode = (string) $this->scopeConfig->getValue(self::PREFIX . 'checks/' . $code);

        return $mode !== '' ? $mode : CheckMode::OFF;
    }

    public function getThreshold(string $code): float
    {
        return (float) $this->scopeConfig->getValue(self::PREFIX . 'thresholds/' . $code);
    }
}
