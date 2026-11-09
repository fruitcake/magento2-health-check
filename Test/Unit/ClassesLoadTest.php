<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Test\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Loads every class of the module. PHP checks inheritance (visibility, signatures) only when a class is loaded,
 * so a class without its own test, like an admin block, would otherwise first fail in setup:di:compile.
 */
class ClassesLoadTest extends TestCase
{
    #[DataProvider('classes')]
    public function testClassLoads(string $class): void
    {
        $this->assertTrue(class_exists($class) || interface_exists($class) || trait_exists($class), $class);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function classes(): array
    {
        $root = dirname(__DIR__, 2);
        $classes = [];
        foreach (['Block', 'Controller', 'Model', 'Observer'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir));
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $relative = substr($file->getPathname(), strlen($root) + 1, -4);
                $class = 'Fruitcake\\HealthCheck\\' . str_replace('/', '\\', $relative);
                $classes[$class] = [$class];
            }
        }
        ksort($classes);

        return $classes;
    }
}
