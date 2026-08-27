<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/index.php',
        __DIR__ . '/noah',
    ])
    ->withSets([
        SetList::CODE_QUALITY,
    ]);
