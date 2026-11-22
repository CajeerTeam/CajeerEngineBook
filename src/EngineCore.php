<?php

declare(strict_types=1);

namespace CajeerBook;

/** Pins the six embedded CajeerEngine runtime components to the bundled 2.1.0 source. */
final class EngineCore
{
    public const VERSION = '2.1.0';

    /** @var array<string, string> SHA-256 of the upstream 2.1.0 source archive. */
    private const FILES = [
        'Runtime/ServiceContainer.php' => '3e0c2aeac222310fccb4cadebc97b4660ea05559e5b0dc5e40b49797b77807a1',
        'Template/CajeerTemplateEngine.php' => '997e5df9b327c1ba1d73b9e87224385d9fb71c7fa3957c5d46a0f317a92cf2f2',
        'Template/TemplateCompiler.php' => '292a0ab45b015ded8e498fb915cc58cd4b497f9def641af56ab683b30b2d3d2e',
        'Template/TemplateExpressionEvaluator.php' => '0fba4bb7cf718e930694a392d8d2ca1136b648bfdb6ff16a9de2c34580998833',
        'Template/TemplateSandbox.php' => 'b9e8f302ef53fbb97ca742bf95659c9528e4b5f01e571ee95097cbefed360736',
        'Update/OperationFiles.php' => '8f4c36985ea3dc0012fe154a54979b51fb473c0a2780929afe0958ec723addcc',
    ];

    public static function verify(string $projectRoot): void
    {
        $root = rtrim($projectRoot, '/\\') . '/core';
        $versionFile = $root . '/ENGINE_VERSION';
        if (is_link($versionFile) || !is_file($versionFile)
            || trim((string) file_get_contents($versionFile)) !== self::VERSION) {
            throw new \RuntimeException('Embedded CajeerEngine version mismatch');
        }
        foreach (self::FILES as $relative => $expected) {
            $path = $root . '/' . $relative;
            if (is_link($path) || !is_file($path)) {
                throw new \RuntimeException('Missing or unsafe CajeerEngine component: ' . $relative);
            }
            $actual = hash_file('sha256', $path);
            if ($actual === false || !hash_equals($expected, $actual)) {
                throw new \RuntimeException('Modified CajeerEngine component: ' . $relative);
            }
        }
    }
}
