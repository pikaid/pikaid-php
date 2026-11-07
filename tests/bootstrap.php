<?php

declare(strict_types=1);

$vendorAutoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require_once $vendorAutoload;
} else {
    require_once __DIR__ . '/../src/PikaidException.php';
    require_once __DIR__ . '/../src/Pikaid.php';
}

require_once __DIR__ . '/IndependentOracle.php';
