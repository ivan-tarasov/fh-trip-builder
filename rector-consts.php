<?php
declare(strict_types=1);
use Rector\Config\RectorConfig;
use Rector\Php83\Rector\ClassConst\AddTypeToConstRector;
return RectorConfig::configure()
    ->withPaths(['/Users/tarasov/Docroot/fh-trip-builder/src', '/Users/tarasov/Docroot/fh-trip-builder/index.php', '/Users/tarasov/Docroot/fh-trip-builder/noah'])
    ->withRules([AddTypeToConstRector::class]);
