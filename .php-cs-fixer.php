<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src')
;

$config = new PhpCsFixer\Config;

$config
    ->setRules([
        '@PSR2' => true,
        'nullable_type_declaration_for_default_null_value' => true,
    ])
    ->setFinder($finder)
;

return $config;
