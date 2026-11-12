<?php

declare(strict_types=1);

namespace CajeerEngine\Template;

final class TemplateCompiler
{
    /** @return array{template:string, extends:?string} */
    public function compileDocument(string $source): array
    {
        $extends = null;
        if (preg_match('/^\s*@extends\([\'\"]([^\'\"]+)[\'\"]\)\s*/', $source, $match) === 1) {
            $extends = $match[1];
            $source = preg_replace('/^\s*@extends\([\'\"]([^\'\"]+)[\'\"]\)\s*/', '', $source, 1) ?? $source;
        }

        return [
            'template' => $this->compile($source),
            'extends' => $extends,
        ];
    }

    public function compile(string $source): string
    {
        $this->assertSafeSource($source);

        $source = preg_replace_callback('/\{\{\s*(.+?)\s*\}\}/s', fn (array $m): string => '<?= htmlspecialchars((string) $__engine->evaluate(' . var_export(trim($m[1]), true) . ', get_defined_vars()), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>', $source) ?? $source;
        $source = preg_replace_callback('/\{!!\s*(.+?)\s*!!\}/s', fn (array $m): string => '<?= (string) $__engine->evaluate(' . var_export(trim($m[1]), true) . ', get_defined_vars()) ?>', $source) ?? $source;

        $source = preg_replace_callback('/@include\([\'\"]([^\'\"]+)[\'\"]\)/', fn (array $m): string => '<?= $__engine->include(' . var_export($m[1], true) . ', get_defined_vars()) ?>', $source) ?? $source;
        $source = preg_replace_callback('/@component\([\'\"]([^\'\"]+)[\'\"]\s*,\s*(.*?)\)/s', fn (array $m): string => '<?= $__engine->component(' . var_export($m[1], true) . ', $__engine->evaluateProps(' . var_export(trim($m[2]), true) . ', get_defined_vars()), get_defined_vars()) ?>', $source) ?? $source;
        $source = preg_replace_callback('/@component\([\'\"]([^\'\"]+)[\'\"]\)/', fn (array $m): string => '<?= $__engine->component(' . var_export($m[1], true) . ', [], get_defined_vars()) ?>', $source) ?? $source;

        $source = preg_replace_callback('/@section\([\'\"]([^\'\"]+)[\'\"]\)/', fn (array $m): string => '<?php $__section = ' . var_export($m[1], true) . '; ob_start(); ?>', $source) ?? $source;
        $source = str_replace('@endsection', '<?php if (isset($__section)) { $__sections[$__section] = ob_get_clean(); unset($__section); } ?>', $source);
        $source = preg_replace_callback('/@yield\([\'\"]([^\'\"]+)[\'\"]\)/', fn (array $m): string => '<?= (string) ($__sections[' . var_export($m[1], true) . '] ?? "") ?>', $source) ?? $source;

        $source = preg_replace_callback('/@if\s*\((.*)\)/', fn (array $m): string => '<?php if ($__engine->truthy(' . var_export(trim($m[1]), true) . ', get_defined_vars())): ?>', $source) ?? $source;
        $source = preg_replace_callback('/@elseif\s*\((.*)\)/', fn (array $m): string => '<?php elseif ($__engine->truthy(' . var_export(trim($m[1]), true) . ', get_defined_vars())): ?>', $source) ?? $source;
        $source = str_replace('@else', '<?php else: ?>', $source);
        $source = str_replace('@endif', '<?php endif; ?>', $source);
        $source = preg_replace_callback('/@foreach\s*\((.*)\)/', fn (array $m): string => $this->compileForeach(trim($m[1])), $source) ?? $source;
        $source = str_replace('@endforeach', '<?php endforeach; ?>', $source);

        if (preg_match('/@for\s*\(|@while\s*\(|@php\b|@endphp\b/i', $source) === 1) {
            throw new \RuntimeException('Шаблон содержит неподдерживаемую директиву с произвольным PHP-кодом.');
        }

        return $source;
    }

    private function compileForeach(string $expression): string
    {
        if (preg_match('/^(.+)\s+as\s+(\$[a-zA-Z_][a-zA-Z0-9_]*)$/s', $expression, $match) === 1) {
            return '<?php foreach ($__engine->iterable(' . var_export(trim($match[1]), true) . ', get_defined_vars()) as ' . $match[2] . '): ?>';
        }

        if (preg_match('/^(.+)\s+as\s+(\$[a-zA-Z_][a-zA-Z0-9_]*)\s*=>\s*(\$[a-zA-Z_][a-zA-Z0-9_]*)$/s', $expression, $match) === 1) {
            return '<?php foreach ($__engine->iterable(' . var_export(trim($match[1]), true) . ', get_defined_vars()) as ' . $match[2] . ' => ' . $match[3] . '): ?>';
        }

        throw new \RuntimeException('Некорректная директива @foreach: ' . $expression);
    }

    private function assertSafeSource(string $source): void
    {
        $forbidden = ['<?php', '<?=', '<? ', '<?', 'eval(', 'shell_exec', 'proc_open', 'passthru', 'system(', 'exec(', 'assert(', 'include', 'require', '`'];
        foreach ($forbidden as $needle) {
            if (stripos($source, $needle) !== false) {
                throw new \RuntimeException('Шаблон содержит запрещённую PHP-конструкцию: ' . $needle);
            }
        }
    }
}
