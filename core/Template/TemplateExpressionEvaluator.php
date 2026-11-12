<?php

declare(strict_types=1);

namespace CajeerEngine\Template;

/**
 * Safe evaluator for Cajeer Template Engine expressions.
 *
 * The evaluator intentionally supports a small expression subset instead of PHP eval:
 * variables/array access, literals, null coalescing, boolean operators, comparisons,
 * empty(), is_array(), count() and json_encode() with whitelisted flags.
 */
final class TemplateExpressionEvaluator
{
    /** @var array<string, int> */
    private const JSON_FLAGS = [
        'JSON_PRETTY_PRINT' => JSON_PRETTY_PRINT,
        'JSON_UNESCAPED_UNICODE' => JSON_UNESCAPED_UNICODE,
        'JSON_UNESCAPED_SLASHES' => JSON_UNESCAPED_SLASHES,
        'JSON_INVALID_UTF8_SUBSTITUTE' => JSON_INVALID_UTF8_SUBSTITUTE,
    ];

    /** @param array<string, mixed> $vars */
    public function value(string $expression, array $vars): mixed
    {
        $expression = trim($expression);
        if ($expression === '') {
            return null;
        }

        foreach ($this->splitTopLevel($expression, '??') as $index => $part) {
            $value = $this->valueNoCoalesce($part, $vars);
            if ($index === 0 && count($this->splitTopLevel($expression, '??')) === 1) {
                return $value;
            }
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $vars */
    public function truthy(string $expression, array $vars): bool
    {
        return (bool) $this->value($expression, $vars);
    }

    /** @param array<string, mixed> $vars @return iterable<mixed, mixed> */
    public function iterable(string $expression, array $vars): iterable
    {
        $value = $this->value($expression, $vars);
        return is_iterable($value) ? $value : [];
    }

    /** @param array<string, mixed> $vars @return array<string, mixed> */
    public function props(string $expression, array $vars): array
    {
        $value = $this->value($expression, $vars);
        return is_array($value) ? $value : [];
    }

    /** @param array<string, mixed> $vars */
    private function valueNoCoalesce(string $expression, array $vars): mixed
    {
        $expression = trim($this->stripOuterParentheses($expression));

        foreach (['||', '&&'] as $operator) {
            $parts = $this->splitTopLevel($expression, $operator);
            if (count($parts) > 1) {
                if ($operator === '||') {
                    foreach ($parts as $part) {
                        if ($this->truthy($part, $vars)) {
                            return true;
                        }
                    }
                    return false;
                }
                foreach ($parts as $part) {
                    if (!$this->truthy($part, $vars)) {
                        return false;
                    }
                }
                return true;
            }
        }

        foreach (['===', '!==', '>=', '<=', '==', '!=', '>', '<'] as $operator) {
            $parts = $this->splitTopLevel($expression, $operator, 2);
            if (count($parts) === 2) {
                $left = $this->value($parts[0], $vars);
                $right = $this->value($parts[1], $vars);
                return match ($operator) {
                    '===' => $left === $right,
                    '!==' => $left !== $right,
                    '==' => $left == $right,
                    '!=' => $left != $right,
                    '>=' => $left >= $right,
                    '<=' => $left <= $right,
                    '>' => $left > $right,
                    '<' => $left < $right,
                    default => false,
                };
            }
        }

        if (str_starts_with($expression, '!')) {
            return !$this->truthy(substr($expression, 1), $vars);
        }

        if (preg_match('/^(empty|is_array|count)\s*\((.*)\)$/s', $expression, $match) === 1) {
            $value = $this->value($match[2], $vars);
            return match ($match[1]) {
                'empty' => empty($value),
                'is_array' => is_array($value),
                'count' => is_countable($value) ? count($value) : 0,
            };
        }

        if (preg_match('/^json_encode\s*\((.*)\)$/s', $expression, $match) === 1) {
            $args = $this->splitTopLevel($match[1], ',');
            $value = $this->value($args[0] ?? 'null', $vars);
            $flags = 0;
            if (isset($args[1])) {
                foreach ($this->splitTopLevel($args[1], '|') as $flag) {
                    $flag = trim($flag);
                    if ($flag === '') {
                        continue;
                    }
                    if (!array_key_exists($flag, self::JSON_FLAGS)) {
                        throw new \RuntimeException('JSON flag не разрешён в шаблоне: ' . $flag);
                    }
                    $flags |= self::JSON_FLAGS[$flag];
                }
            }
            return json_encode($value, $flags | JSON_THROW_ON_ERROR);
        }

        if (str_starts_with($expression, '[') && str_ends_with($expression, ']')) {
            return $this->arrayLiteral(substr($expression, 1, -1), $vars);
        }

        if (preg_match('/^\'(.*)\'$/s', $expression, $match) === 1 || preg_match('/^"(.*)"$/s', $expression, $match) === 1) {
            return stripcslashes($match[1]);
        }

        if (preg_match('/^-?\d+$/', $expression) === 1) {
            return (int) $expression;
        }
        if (preg_match('/^-?\d+\.\d+$/', $expression) === 1) {
            return (float) $expression;
        }

        return match (strtolower($expression)) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => $this->variable($expression, $vars),
        };
    }

    /** @param array<string, mixed> $vars @return array<string, mixed> */
    private function arrayLiteral(string $body, array $vars): array
    {
        $result = [];
        $nextIndex = 0;
        foreach ($this->splitTopLevel($body, ',') as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            $pair = $this->splitTopLevel($item, '=>', 2);
            if (count($pair) === 2) {
                $key = $this->value($pair[0], $vars);
                if (!is_int($key) && !is_string($key)) {
                    throw new \RuntimeException('Ключ массива в шаблоне должен быть строкой или числом.');
                }
                $result[$key] = $this->value($pair[1], $vars);
                continue;
            }
            $result[$nextIndex++] = $this->value($item, $vars);
        }
        return $result;
    }

    /** @param array<string, mixed> $vars */
    private function variable(string $expression, array $vars): mixed
    {
        if (preg_match('/^\$([a-zA-Z_][a-zA-Z0-9_]*)(.*)$/s', trim($expression), $match) !== 1) {
            throw new \RuntimeException('Неподдерживаемое выражение шаблона: ' . $expression);
        }

        $name = $match[1];
        $value = $vars[$name] ?? null;
        $tail = trim($match[2]);
        while ($tail !== '') {
            if (preg_match('/^\[\s*\'([^\']*)\'\s*\](.*)$/s', $tail, $m) === 1 || preg_match('/^\[\s*"([^"]*)"\s*\](.*)$/s', $tail, $m) === 1) {
                $key = stripcslashes($m[1]);
                $tail = trim($m[2]);
            } elseif (preg_match('/^\[\s*(\d+)\s*\](.*)$/s', $tail, $m) === 1) {
                $key = (int) $m[1];
                $tail = trim($m[2]);
            } else {
                throw new \RuntimeException('Неподдерживаемый доступ к переменной в шаблоне: $' . $name . $tail);
            }

            if (is_array($value) && array_key_exists($key, $value)) {
                $value = $value[$key];
                continue;
            }
            if (is_object($value) && isset($value->{$key})) {
                $value = $value->{$key};
                continue;
            }
            return null;
        }

        return $value;
    }

    private function stripOuterParentheses(string $expression): string
    {
        $expression = trim($expression);
        while (str_starts_with($expression, '(') && str_ends_with($expression, ')')) {
            $inner = substr($expression, 1, -1);
            if (!$this->balanced($inner)) {
                break;
            }
            $expression = trim($inner);
        }
        return $expression;
    }

    /** @return list<string> */
    private function splitTopLevel(string $expression, string $delimiter, ?int $limit = null): array
    {
        $parts = [];
        $start = 0;
        $depth = 0;
        $quote = null;
        $length = strlen($expression);
        $delimiterLength = strlen($delimiter);

        for ($i = 0; $i < $length; $i++) {
            $char = $expression[$i];
            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '\'' || $char === '"') {
                $quote = $char;
                continue;
            }
            if ($char === '(' || $char === '[') {
                $depth++;
                continue;
            }
            if ($char === ')' || $char === ']') {
                $depth = max(0, $depth - 1);
                continue;
            }
            if ($depth === 0 && substr($expression, $i, $delimiterLength) === $delimiter) {
                $parts[] = trim(substr($expression, $start, $i - $start));
                $i += $delimiterLength - 1;
                $start = $i + 1;
                if ($limit !== null && count($parts) >= $limit - 1) {
                    break;
                }
            }
        }

        if ($parts === []) {
            return [trim($expression)];
        }
        $parts[] = trim(substr($expression, $start));
        return $parts;
    }

    private function balanced(string $expression): bool
    {
        $depth = 0;
        $quote = null;
        $length = strlen($expression);
        for ($i = 0; $i < $length; $i++) {
            $char = $expression[$i];
            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '\'' || $char === '"') {
                $quote = $char;
                continue;
            }
            if ($char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === ']') {
                $depth--;
                if ($depth < 0) {
                    return false;
                }
            }
        }
        return $depth === 0 && $quote === null;
    }
}
