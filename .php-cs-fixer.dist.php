<?php

return (new PhpCsFixer\Config())
    ->setRules(['@Symfony' => true])
    ->setFinder(PhpCsFixer\Finder::create()->in([__DIR__.'/src', __DIR__.'/tests']));
