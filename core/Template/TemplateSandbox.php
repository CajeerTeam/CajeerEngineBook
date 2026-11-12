<?php

declare(strict_types=1);

namespace CajeerEngine\Template;

final readonly class TemplateSandbox
{
    public function __construct(private string $templatesRoot)
    {
    }

    public function assertTemplatePath(string $templatePath): void
    {
        $root = realpath($this->templatesRoot);
        $template = realpath($templatePath);

        if ($root === false || $template === false || !str_starts_with($template, $root . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('Шаблон находится вне разрешённой директории.');
        }

        if (!str_ends_with($template, '.cjr')) {
            throw new \RuntimeException('Cajeer Template Engine принимает только .cjr шаблоны.');
        }
    }

    /** @param array<string, mixed> $context */
    public function execute(string $compiledPhp, array $context, CajeerTemplateEngine $engine, array &$sections = []): string
    {
        $reserved = ['compiledPhp', 'context', '__engine', '__sections'];
        foreach ($reserved as $key) {
            unset($context[$key]);
        }

        if (is_array($context['sections'] ?? null)) {
            $sections = array_merge($sections, $context['sections']);
        }
        unset($context['sections']);
        $__sections = &$sections;
        $__engine = $engine;

        ob_start();
        try {
        (static function () use ($compiledPhp, $context, $__engine, &$__sections): void {
            extract($context, EXTR_SKIP);
            eval('?>' . $compiledPhp);
        })();

        return (string) ob_get_contents();
        } finally { ob_end_clean(); }
    }
}
