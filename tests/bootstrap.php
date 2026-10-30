<?php

declare(strict_types=1);

date_default_timezone_set('UTC');

$autoloadCandidates = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
];

foreach ($autoloadCandidates as $file) {
    if (is_file($file)) {
        require $file;
        return;
    }
}

throw new RuntimeException('Could not find composer autoload.php. Run `composer install` first.');
