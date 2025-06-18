<?php

echo "We're in the .php-cs-fixer.php file.\n";

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
;

return (new PhpCsFixer\Config())
    ->setParallelConfig(PhpCsFixer\Runner\Parallel\ParallelConfigFactory::detect())
    ->setRules([
        // @Symfony has strict rules, but it's good stuff
        '@Symfony'               => true,
        // prevent disabling /** @psalm-suppress ... */
        'phpdoc_to_comment'      => false,
        // aligned is easier to read (for me at least)
        'binary_operator_spaces' => [
            'operators' => [
                '=>' => 'align',
            ],
        ],
        'strict_comparison' => true,
    ])
    ->setFinder($finder)
;
