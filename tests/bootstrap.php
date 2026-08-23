<?php

declare(strict_types=1);

$autoloaders = [
    dirname(__DIR__) . '/vendor/autoload.php',
    dirname(__DIR__, 3) . '/vendor/autoload.php',
];

foreach ($autoloaders as $autoloader) {
    if (is_file($autoloader) && is_readable($autoloader)) {
        require $autoloader;

        return;
    }
}

throw new RuntimeException('Composer autoloader not found. Run composer install in the package or monorepo root.');
