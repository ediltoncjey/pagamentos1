<?php

declare(strict_types=1);

namespace App\Utils;

use Closure;
use ReflectionClass;
use ReflectionFunction;
use RuntimeException;

final class Container
{
    /**
     * @var array<string, object|string>
     */
    private array $bindings = [];

    /**
     * @var array<string, object|string>
     */
    private array $singletons = [];

    /**
     * @var array<string, object>
     */
    private array $instances = [];

    public function bind(string $id, object|string $resolver): void
    {
        $this->bindings[$id] = $resolver;
    }

    public function singleton(string $id, object|string $resolver): void
    {
        $this->singletons[$id] = $resolver;
    }

    public function instance(string $id, object $instance): void
    {
        $this->instances[$id] = $instance;
    }

    public function has(string $id): bool
    {
        return isset($this->bindings[$id])
            || isset($this->singletons[$id])
            || isset($this->instances[$id])
            || class_exists($id);
    }

    public function make(string $id, array $parameters = []): mixed
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (isset($this->singletons[$id])) {
            $resolved = $this->resolve($this->singletons[$id], $parameters);
            if (!is_object($resolved)) {
                throw new RuntimeException(sprintf('Singleton `%s` must resolve to an object.', $id));
            }

            $this->instances[$id] = $resolved;
            return $resolved;
        }

        if (isset($this->bindings[$id])) {
            return $this->resolve($this->bindings[$id], $parameters);
        }

        if (class_exists($id)) {
            return $this->build($id, $parameters);
        }

        throw new RuntimeException(sprintf('Binding `%s` not found in container.', $id));
    }

    private function resolve(object|string $resolver, array $parameters = []): mixed
    {
        if ($resolver instanceof Closure) {
            $reflection = new ReflectionFunction($resolver);
            $count = $reflection->getNumberOfParameters();

            if ($count === 0) {
                return $resolver();
            }

            if ($count === 1) {
                return $resolver($this);
            }

            return $resolver($this, $parameters);
        }

        if (is_object($resolver) && !$resolver instanceof Closure) {
            return $resolver;
        }

        if (is_string($resolver) && class_exists($resolver)) {
            return $this->build($resolver, $parameters);
        }

        throw new RuntimeException('Invalid container resolver.');
    }

    private function build(string $class, array $parameters = []): object
    {
        $reflection = new ReflectionClass($class);
        if (!$reflection->isInstantiable()) {
            throw new RuntimeException(sprintf('Class `%s` is not instantiable.', $class));
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
            return new $class();
        }

        $dependencies = [];
        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();
            if (array_key_exists($name, $parameters)) {
                $dependencies[] = $parameters[$name];
                continue;
            }

            $type = $parameter->getType();
            if ($type !== null && !$type->isBuiltin()) {
                $dependencies[] = $this->make($type->getName());
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $dependencies[] = $parameter->getDefaultValue();
                continue;
            }

            throw new RuntimeException(
                sprintf('Unable to resolve parameter `%s` on `%s`.', $name, $class)
            );
        }

        return $reflection->newInstanceArgs($dependencies);
    }
}
