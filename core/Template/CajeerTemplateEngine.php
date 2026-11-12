<?php

declare(strict_types=1);

namespace CajeerEngine\Template;

final class CajeerTemplateEngine
{
    private TemplateCompiler $compiler;
    private int $depth = 0;
    private TemplateExpressionEvaluator $expressions;

    public function __construct(private readonly TemplateSandbox $sandbox, private readonly string $templatesRoot, private readonly ?string $cachePath = null)
    {
        $this->compiler = new TemplateCompiler();
        $this->expressions = new TemplateExpressionEvaluator();
    }

    /** @param array<string, mixed> $context */
    public function render(string $templatePath, array $context = []): string
    {
        if(++$this->depth>32){--$this->depth;throw new \RuntimeException("Template nesting limit exceeded");}
        try {
        $this->sandbox->assertTemplatePath($templatePath);
        $source = file_get_contents($templatePath);
        if ($source === false) {
            throw new \RuntimeException("Шаблон не найден: {$templatePath}");
        }

        $compiled = $this->compiledDocument($templatePath, $source);
        $context['__template_namespace'] = $this->namespaceForPath($templatePath);
        $sections = is_array($context['sections'] ?? null) ? $context['sections'] : [];
        $html = $this->sandbox->execute($compiled['template'], $context, $this, $sections);

        if ($compiled['extends'] !== null) {
            $layoutPath = $this->resolve($compiled['extends']);
            $layoutContext = array_merge($context, [
                'slot' => $html,
                'sections' => $sections,
            ]);
            return $this->render($layoutPath, $layoutContext);
        }

        return $html;
        } finally { --$this->depth; }
    }


    /** @param array<string, mixed> $vars */
    public function evaluate(string $expression, array $vars): mixed
    {
        return $this->expressions->value($expression, $this->cleanVars($vars));
    }

    /** @param array<string, mixed> $vars */
    public function truthy(string $expression, array $vars): bool
    {
        return $this->expressions->truthy($expression, $this->cleanVars($vars));
    }

    /** @param array<string, mixed> $vars @return iterable<mixed, mixed> */
    public function iterable(string $expression, array $vars): iterable
    {
        return $this->expressions->iterable($expression, $this->cleanVars($vars));
    }

    /** @param array<string, mixed> $vars @return array<string, mixed> */
    public function evaluateProps(string $expression, array $vars): array
    {
        return $this->expressions->props($expression, $this->cleanVars($vars));
    }

    /** @param array<string, mixed> $context */
    public function renderName(string $name, array $context = []): string
    {
        return $this->render($this->resolve($name), $context);
    }

    /** @param array<string, mixed> $vars */
    public function include(string $name, array $vars = []): string
    {
        return $this->renderName($name, $this->cleanVars($vars));
    }

    /** @param array<string, mixed> $props @param array<string, mixed> $vars */
    public function component(string $name, array $props = [], array $vars = []): string
    {
        if (str_starts_with($name, 'components.') || str_starts_with($name, 'components/')) {
            $candidate = $name;
        } else {
            $namespace = trim((string) ($vars['__template_namespace'] ?? ''), '. /');
            $candidate = $namespace !== '' ? $namespace . '.components.' . $name : 'components.' . $name;
        }

        return $this->renderName($candidate, array_merge($this->cleanVars($vars), $props));
    }

    public function resolve(string $name): string
    {
        $name = trim($name);
        $name = str_replace(['\\', '..'], ['/', ''], $name);
        if (str_ends_with($name, '.cjr')) {
            $relative = str_replace('.', '/', substr($name, 0, -4)) . '.cjr';
        } else {
            $relative = str_replace('.', '/', $name) . '.cjr';
        }

        $path = rtrim($this->templatesRoot, '/') . '/' . ltrim($relative, '/');
        $this->sandbox->assertTemplatePath($path);
        return $path;
    }


    private function namespaceForPath(string $templatePath): string
    {
        $root = realpath($this->templatesRoot);
        $path = realpath($templatePath);
        if ($root === false || $path === false || !str_starts_with($path, $root)) {
            return '';
        }

        $relative = trim(str_replace('\\', '/', substr($path, strlen($root))), '/');
        $first = explode('/', $relative)[0] ?? '';
        return preg_match('/^[a-zA-Z0-9_-]+$/', $first) === 1 ? $first : '';
    }

    /** @return array{template:string, extends:?string} */
    private function compiledDocument(string $templatePath, string $source): array
    {
        if ($this->cachePath === null) {
            return $this->compiler->compileDocument($source);
        }

        $cacheFile = rtrim($this->cachePath, '/') . '/' . hash('sha256', $templatePath) . '.php.cache.json';
        $mtime = hash('sha256',$source . hash_file('sha256',__DIR__.'/TemplateCompiler.php'));
        if (is_file($cacheFile)) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached) && ($cached['mtime'] ?? null) === $mtime && isset($cached['template'])) {
                return [
                    'template' => (string) $cached['template'],
                    'extends' => isset($cached['extends']) ? (string) $cached['extends'] : null,
                ];
            }
        }

        $compiled = $this->compiler->compileDocument($source);
        if (!is_dir(dirname($cacheFile))) {
            @mkdir(dirname($cacheFile), 0775, true);
        }
        try { \CajeerEngine\Update\OperationFiles::write($cacheFile, json_encode([
            'mtime' => $mtime,
            'template' => $compiled['template'],
            'extends' => $compiled['extends'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),0640); } catch (\Throwable) { /* compilation still succeeds without a writable cache */ }

        return $compiled;
    }

    /** @param array<string, mixed> $vars @return array<string, mixed> */
    private function cleanVars(array $vars): array
    {
        foreach (['__engine', '__sections', 'compiledPhp', 'context'] as $reserved) {
            unset($vars[$reserved]);
        }

        return $vars;
    }
}
