<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Test\Unit;

use Fruitcake\HealthCheck\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\TestCase;

/**
 * Builds a real Config on top of a scope config backed by an array, so tests set
 * config paths the way an admin would.
 */
trait ConfigStub
{
    /**
     * @param array<string, mixed> $values config path => value
     */
    private function createConfig(array $values = [], string $mode = State::MODE_PRODUCTION): Config
    {
        /** @var TestCase $this */
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path) => $values[$path] ?? null
        );
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static fn (string $path) => (bool) ($values[$path] ?? false)
        );

        // Stored keys are "encrypted" as enc:<key>
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturnCallback(
            static fn (string $value) => str_starts_with($value, 'enc:') ? substr($value, 4) : ''
        );

        $appState = $this->createMock(State::class);
        $appState->method('getMode')->willReturn($mode);

        return new Config($scopeConfig, $encryptor, $appState);
    }
}
