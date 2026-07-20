<?php

declare(strict_types=1);

namespace Polymorph\Sdk\Testing;

use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Polymorph\Sdk\Data\DefinitionRegistry;
use Polymorph\Sdk\Data\ExtensionData;
use Polymorph\Sdk\Data\Repository;
use Polymorph\Sdk\Extension\ExtensionContext;
use Polymorph\Sdk\Extension\ExtensionServices;
use Polymorph\Sdk\Logging\Redactor;
use Polymorph\Sdk\Testing\Extension\InMemoryExtensionServices;
use Polymorph\Sdk\Testing\Logging\FakeRedactor;
use Polymorph\Sdk\Testing\Validation\FakeValidationConstraints;
use Polymorph\Sdk\Validation\ValidationConstraints;

/**
 * Базовый TestCase расширения (V2) — замена V1 PluginTestKit\PluginTestCase.
 *
 * Поднимает лёгкий контейнер (без полного Laravel/БД), биндит SDK-контракты на
 * in-memory фейки, читает extension.json плагина, регистрирует его провайдер и
 * прогоняет onEnable (сидинг определений/контента в in-memory стор). После этого
 * сервисы расширения резолвятся через `$this->app->make(...)` ровно как в проде,
 * но поверх фейкового data-слоя.
 *
 *     uses(\Polymorph\Sdk\Testing\PluginTestCase::class);
 *
 *     it('...', function () {
 *         $svc = $this->app->make(ProgressionService::class);
 *         ...
 *     });
 */
abstract class PluginTestCase extends TestCase
{
    protected Container $app;

    protected InMemoryExtensionServices $services;

    protected ExtensionContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new Container();
        Container::setInstance($this->app);

        $this->services = new InMemoryExtensionServices();
        $this->app->instance(ExtensionServices::class, $this->services);
        $this->app->singleton(ValidationConstraints::class, static fn (): ValidationConstraints => new FakeValidationConstraints());
        $this->app->singleton(Redactor::class, static fn (): Redactor => new FakeRedactor());

        // config-репозиторий (ленивый): ExtensionProvider::register() мерджит
        // собственный be/config/{id}.php через mergeConfigFrom(), который резолвит
        // 'config'. В лёгком контейнере его нет — без этого регистрация провайдера
        // расширения с конфиг-файлом падала бы BindingResolutionException. Берём
        // настоящий Illuminate\Config\Repository, когда illuminate/config доступен
        // (полная точность), иначе компактный in-kit фолбэк.
        $this->app->singleton('config', static fn (): object => class_exists(\Illuminate\Config\Repository::class)
            ? new \Illuminate\Config\Repository()
            : new \Polymorph\Sdk\Testing\Support\InMemoryConfig());

        $manifest = $this->loadManifest();
        $this->context = ExtensionContext::for($manifest['id']);

        $providerClass = $manifest['provider'] ?? null;
        if (is_string($providerClass) && class_exists($providerClass)) {
            $provider = new $providerClass($this->app);
            $provider->register();
            // boot() пропускаем намеренно: listeners/schedule/ExtensionState —
            // рантайм хоста, в unit-тестах не нужны. onEnable прогоняем явно.
            if (method_exists($provider, 'fireEnabled')) {
                $provider->fireEnabled();
            }
        }
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);

        parent::tearDown();
    }

    /**
     * Скоупнутый репозиторий сущности (общий стор с data()).
     *
     * @return Repository<\Polymorph\Sdk\Data\Entity>
     */
    protected function records(string $entity): Repository
    {
        return $this->services->repository($this->context, $entity);
    }

    protected function data(): ExtensionData
    {
        return $this->services->data($this->context);
    }

    protected function definitions(): DefinitionRegistry
    {
        return $this->services->definitions($this->context);
    }

    /** Стампит authorId на последующие create() (эмуляция текущего актора хоста). */
    protected function actingAs(int $userId): static
    {
        $this->services->actingAs($userId);

        return $this;
    }

    /**
     * Читает extension.json плагина. Pest стартует из корня плагина
     * (composer-скрипт `vendor/bin/pest be/tests`), поэтому манифест ищем от cwd
     * вверх по нескольким уровням.
     *
     * @return array{id: string, provider?: string}
     */
    private function loadManifest(): array
    {
        $candidates = [];
        $cwd = getcwd();
        if (is_string($cwd) && $cwd !== '') {
            $candidates[] = $cwd . DIRECTORY_SEPARATOR . 'extension.json';
            $candidates[] = dirname($cwd) . DIRECTORY_SEPARATOR . 'extension.json';
        }
        // Фолбэк: от расположения теста вверх до корня плагина.
        $candidates[] = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'extension.json';

        foreach ($candidates as $path) {
            if (is_file($path)) {
                $decoded = json_decode((string) file_get_contents($path), true);
                if (is_array($decoded) && isset($decoded['id']) && is_string($decoded['id'])) {
                    /** @var array{id: string, provider?: string} $decoded */
                    return $decoded;
                }
            }
        }

        throw new \RuntimeException(
            'PluginTestCase: extension.json not found. Run pest from the plugin root (composer test script).',
        );
    }
}
