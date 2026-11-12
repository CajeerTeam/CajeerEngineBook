<?php

declare(strict_types=1);

namespace CajeerEngine\Runtime;

final class ServiceContainer
{
    /** @var array<string, callable|object|scalar|array|null> */
    private array $definitions = [];

    /** @var array<string, object|scalar|array|null> */
    private array $instances = [];

    /** @var array<string, bool> */
    private array $shared = [];

    public function set(string $id, callable|object|array|string|int|float|bool|null $definition, bool $shared = true): void
    {
        $this->definitions[$id] = $definition;
        $this->shared[$id] = $shared;
        unset($this->instances[$id]);
    }

    public function instance(string $id, object|array|string|int|float|bool|null $value): void
    {
        $this->instances[$id] = $value;
        $this->shared[$id] = true;
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->instances) || array_key_exists($id, $this->definitions);
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (!array_key_exists($id, $this->definitions)) {
            throw new \RuntimeException('Сервис не зарегистрирован: ' . $id);
        }

        $definition = $this->definitions[$id];
        $value = is_callable($definition) ? $definition($this) : $definition;

        if (($this->shared[$id] ?? true) === true) {
            $this->instances[$id] = $value;
        }

        return $value;
    }

    /** @return list<array{id:string, resolved:bool, shared:bool, type:string}> */
    public function describe(): array
    {
        $ids = array_unique(array_merge(array_keys($this->definitions), array_keys($this->instances)));
        sort($ids);

        $result = [];
        foreach ($ids as $id) {
            $value = $this->instances[$id] ?? $this->definitions[$id] ?? null;
            $result[] = [
                'id' => $id,
                'resolved' => array_key_exists($id, $this->instances),
                'shared' => $this->shared[$id] ?? true,
                'type' => is_object($value) ? $value::class : get_debug_type($value),
            ];
        }

        return $result;
    }
}
