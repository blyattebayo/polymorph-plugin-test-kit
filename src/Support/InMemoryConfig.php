<?php

declare(strict_types=1);

namespace Polymorph\Sdk\Testing\Support;

/**
 * Минимальный config-репозиторий для лёгкого контейнера test-kit — фолбэк на случай,
 * когда illuminate/config недоступен в vendor плагина (плагин тянет только
 * polymorph/plugin-test-kit без полного laravel/framework). Поддерживает ровно тот
 * контракт, что использует ServiceProvider::mergeConfigFrom() и хелпер config():
 * get/set/has с dot-notation. Когда illuminate/config есть (плагин с laravel в
 * require-dev), {@see \Polymorph\Sdk\Testing\PluginTestCase} биндит настоящий
 * Illuminate\Config\Repository ради полной точности.
 */
final class InMemoryConfig
{
    /** @var array<string, mixed> */
    private array $items = [];

    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->items)) {
            return $this->items[$key];
        }

        $value = $this->items;
        foreach (explode('.', $key) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $target = &$this->items;
        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $target[$segment] = $value;
                break;
            }
            if (! isset($target[$segment]) || ! is_array($target[$segment])) {
                $target[$segment] = [];
            }
            $target = &$target[$segment];
        }
    }

    public function has(string $key): bool
    {
        return $this->get($key, '__cr_missing__') !== '__cr_missing__';
    }
}
