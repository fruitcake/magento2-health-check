<?php

declare(strict_types=1);

use Magento\Framework\Code\Generator\Io;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\TestFramework\Unit\Autoloader\FactoryGenerator;
use Magento\Framework\TestFramework\Unit\Autoloader\GeneratedClassesAutoloader;

require __DIR__ . '/../../vendor/autoload.php';

// Generates *Factory classes on the fly, as Magento's own unit test setup does
$autoloader = new GeneratedClassesAutoloader(
    [new FactoryGenerator()],
    new Io(new File(), __DIR__ . '/../../.phpunit.cache/generated')
);
spl_autoload_register([$autoloader, 'load']);
