<?php
$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src')
;
return PhpCsFixer\Config::create()
    ->setRules(array(
        '@PSR2' => true,
    ))
    ->setFinder($finder)
;
