<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/config'])
    ->append([__DIR__ . '/index.php', __DIR__ . '/noah']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PER-CS2.0' => true,
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        // No alignment padding: a single space around every binary operator
        // (collapses aligned `=` / `=>` blocks).
        'binary_operator_spaces' => ['default' => 'single_space'],
        // Trailing comma on every multiline array, call and signature.
        'trailing_comma_in_multiline' => [
            'after_heredoc' => true,
            'elements' => ['arrays', 'arguments', 'parameters', 'match'],
        ],
        // Import global-namespace classes instead of leading-backslash qualifiers.
        'global_namespace_import' => [
            'import_classes' => true,
            'import_constants' => false,
            'import_functions' => false,
        ],
        'fully_qualified_strict_types' => true,
    ])
    ->setFinder($finder);
