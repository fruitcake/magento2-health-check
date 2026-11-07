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

        return $endpoint !== '' ? $endpoint : 'up';
    }

    public function getKey(): string
    {
        $value = trim((string) $this->scopeConfig->getValue(self::PREFIX . 'general/key'));
        if ($value === '') {
            return '';
        }

        // A value that does not decrypt (plain text in env.php) is the key itself, so the endpoint never ends up open
        $decrypted = (string) $this->encryptor->decrypt($value);

        return $decrypted !== '' ? $decrypted : $value;
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
